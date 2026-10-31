<?php

namespace App\Filament\Traits;

use App\Enums\OrderStatus;
use App\Enums\TripStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderCharge;
use App\Models\Trip;
use App\Services\Export\TripExcelExportService;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator as LengthAwarePaginatorContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

/**
 * Dữ liệu bảng Thống kê chuyến: dùng chung cho page (header actions, xuất Excel) và widget React.
 * Lớp dùng trait phải có các thuộc tính public: startDate, endDate, customerId, vehicleOwner,
 * search, chargeStatus, page, perPage, activeTab.
 */
trait HasTripStatistics
{
    protected ?Collection $cachedBaseRows = null;

    protected ?Collection $cachedTripRows = null;

    protected function getBaseRowsCacheKey(): string
    {
        $userId = Auth::id() ?? 'guest';
        $filterHash = md5(json_encode([
            $this->startDate,
            $this->endDate,
            $this->customerId,
            $this->vehicleOwner,
            $this->search,
        ]));

        return "trip_stats_rows_{$userId}_{$filterHash}";
    }

    public function clearBaseRowsCache(): void
    {
        $this->cachedBaseRows = null;
        $this->cachedTripRows = null;
        Cache::forget($this->getBaseRowsCacheKey());
    }

    /**
     * Số đơn hàng ngoài (theo bộ lọc hiện tại) đã xuất bảng kê / chưa có giá.
     *
     * @return array{locked: int, missing: int}
     */
    public function getChargeStatusCounts(): array
    {
        $rows = $this->rowsForTab('external');
        $charges = $this->chargesByOrderId($rows);

        return [
            'locked' => $rows->filter(fn (array $row): bool => $this->chargeStatusOf($row, $charges) === 'locked')->count(),
            'missing' => $rows->filter(fn (array $row): bool => $this->chargeStatusOf($row, $charges) === 'missing')->count(),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, OrderCharge>
     */
    private function chargesByOrderId(Collection $rows): Collection
    {
        return OrderCharge::query()
            ->whereIn('order_id', $rows->pluck('order_id')->filter())
            ->get(['order_id', 'base_price', 'exported_at'])
            ->keyBy('order_id');
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  Collection<int, OrderCharge>  $charges
     */
    private function chargeStatusOf(array $row, Collection $charges): ?string
    {
        $charge = $charges->get($row['order_id']);

        return match (true) {
            $charge?->exported_at !== null => 'locked',
            $charge === null || $charge->base_price === null => 'missing',
            default => null,
        };
    }

    /**
     * @return Collection<int, Trip>
     */
    public function getFilteredTrips(): Collection
    {
        $dateFrom = $this->startDate ? Carbon::parse($this->startDate)->startOfDay() : null;
        $dateTo = $this->endDate ? Carbon::parse($this->endDate)->endOfDay() : null;
        $search = trim((string) $this->search);

        $tripsQuery = Trip::query()
            ->where('trips.status', TripStatus::Completed)
            ->with([
                'vehicle',
                'driver',
                'orders.customer',
                'orders.area',
                'orders.pickupLocation',
                'orders.deliveryPoints.location',
                'orders.tripCheckpoints',
                'checkpoints',
                'startLocation',
                'endLocation',
            ])
            ->when($dateFrom || $dateTo, function (Builder $q) use ($dateFrom, $dateTo): Builder {
                if ($dateFrom && $dateTo) {
                    return $q->where(function (Builder $sub) use ($dateFrom, $dateTo): void {
                        $sub->whereBetween('trips.started_at', [$dateFrom, $dateTo])
                            ->orWhere(function (Builder $o) use ($dateFrom, $dateTo): void {
                                $o->whereNull('trips.started_at')
                                    ->whereBetween('trips.created_at', [$dateFrom, $dateTo]);
                            });
                    });
                }
                if ($dateFrom) {
                    return $q->where(function (Builder $sub) use ($dateFrom): void {
                        $sub->where('trips.started_at', '>=', $dateFrom)
                            ->orWhere(function (Builder $o) use ($dateFrom): void {
                                $o->whereNull('trips.started_at')
                                    ->where('trips.created_at', '>=', $dateFrom);
                            });
                    });
                }

                return $q->where(function (Builder $sub) use ($dateTo): void {
                    $sub->where('trips.started_at', '<=', $dateTo)
                        ->orWhere(function (Builder $o) use ($dateTo): void {
                            $o->whereNull('trips.started_at')
                                ->where('trips.created_at', '<=', $dateTo);
                        });
                });
            })
            ->when(filled($search), function (Builder $q) use ($search): Builder {
                return $q->where(function (Builder $sub) use ($search): void {
                    $sub->where('trips.trip_code', 'like', "%{$search}%")
                        ->orWhereHas('vehicle', fn ($v) => $v->where('plate_number', 'like', "%{$search}%"))
                        ->orWhereHas('driver', fn ($d) => $d->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('orders', function (Builder $o) use ($search): void {
                            $o->where('order_code', 'like', "%{$search}%")
                                ->orWhere('cargo_name', 'like', "%{$search}%")
                                ->orWhereHas('customer', fn ($c) => $c->where('code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%"));
                        });
                });
            })
            ->when($this->vehicleOwner !== 'all', function (Builder $q): Builder {
                return $q->whereHas('vehicle', fn ($v) => $v->where('type', $this->vehicleOwner));
            })
            ->when(filled($this->customerId), function (Builder $q): Builder {
                return $q->whereHas('orders', fn ($o) => $o->where('customer_id', $this->customerId));
            })
            ->orderByDesc('trips.started_at')
            ->orderByDesc('trips.created_at');

        $trips = $tripsQuery->get();

        // Lấy thêm các orders chưa gán trip (nếu có) đã hoàn thành
        $unassignedOrdersQuery = Order::query()
            ->whereNull('trip_id')
            ->where('status', OrderStatus::Completed)
            ->with([
                'customer',
                'area',
                'pickupLocation',
                'deliveryPoints.location',
                'tripCheckpoints',
            ])
            ->when($dateFrom || $dateTo, function (Builder $q) use ($dateFrom, $dateTo): Builder {
                if ($dateFrom && $dateTo) {
                    return $q->whereBetween('planned_loading_at', [$dateFrom, $dateTo]);
                }
                if ($dateFrom) {
                    return $q->where('planned_loading_at', '>=', $dateFrom);
                }

                return $q->where('planned_loading_at', '<=', $dateTo);
            })
            ->when(filled($search), function (Builder $q) use ($search): Builder {
                return $q->where(function (Builder $sub) use ($search): void {
                    $sub->where('order_code', 'like', "%{$search}%")
                        ->orWhere('cargo_name', 'like', "%{$search}%")
                        ->orWhereHas('customer', fn ($c) => $c->where('code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%"));
                });
            })
            ->when(filled($this->customerId), fn ($q) => $q->where('customer_id', $this->customerId));

        $unassignedOrders = $unassignedOrdersQuery->get();

        /** @var TripExcelExportService $exportService */
        $exportService = app(TripExcelExportService::class);
        $syntheticTrips = $exportService->convertOrdersToTripsCollection($unassignedOrders);

        return $trips->merge($syntheticTrips);
    }

    /**
     * Lấy toàn bộ dòng dữ liệu đã lọc theo ngày, khách hàng, xe và từ khóa
     * (chưa lọc theo loại hình / tab để tái sử dụng cho đếm số lượng từng tab).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function getBaseFilteredRows(): Collection
    {
        if ($this->cachedBaseRows !== null) {
            return $this->cachedBaseRows;
        }

        $cacheKey = $this->getBaseRowsCacheKey();

        /** @var array<int, array<string, mixed>>|null $cached */
        $cached = Cache::get($cacheKey);
        if ($cached !== null && is_array($cached)) {
            return $this->cachedBaseRows = collect($cached);
        }

        $trips = $this->getFilteredTrips();

        /** @var TripExcelExportService $exportService */
        $exportService = app(TripExcelExportService::class);
        $rows = $exportService->getTripSummaryRows($trips);

        // 1. Lọc theo Khách hàng
        if (filled($this->customerId)) {
            $rows = $rows->filter(function (array $row): bool {
                return (string) ($row['customer_id'] ?? '') === (string) $this->customerId;
            })->values();
        }

        // 2. Lọc theo Quản lý xe (Xe công ty / Xe thuê)
        if ($this->vehicleOwner !== 'all') {
            $rows = $rows->filter(function (array $row): bool {
                $targetOwner = $this->vehicleOwner === 'company' ? 'Xe công ty' : 'Xe thuê';

                return ($row['vehicle_owner'] ?? '') === $targetOwner;
            })->values();
        }

        // 3. Lọc theo Từ khóa tìm kiếm
        if (filled($this->search)) {
            $query = mb_strtolower(trim($this->search));
            $rows = $rows->filter(function (array $row) use ($query): bool {
                $searchable = implode(' ', [
                    $row['code'] ?? '',
                    $row['plate_number'] ?? '',
                    $row['driver_name'] ?? '',
                    $row['customer'] ?? '',
                    $row['warehouse'] ?? '',
                    $row['journey'] ?? '',
                    $row['note'] ?? '',
                    $row['cargo_type'] ?? '',
                ]);

                return str_contains(mb_strtolower($searchable), $query);
            })->values();
        }

        // Chuẩn hóa ngày giờ sang chuỗi ISO / scalar để serialize an toàn vào Cache
        $serializedRows = $rows->map(function (array $row): array {
            $row['date'] = $row['date'] instanceof Carbon ? $row['date']->toDateTimeString() : ($row['date'] ? (string) $row['date'] : null);
            $row['time_a'] = $row['time_a'] instanceof Carbon ? $row['time_a']->toDateTimeString() : ($row['time_a'] ? (string) $row['time_a'] : null);
            $row['time_b'] = $row['time_b'] instanceof Carbon ? $row['time_b']->toDateTimeString() : ($row['time_b'] ? (string) $row['time_b'] : null);
            $row['time_c'] = $row['time_c'] instanceof Carbon ? $row['time_c']->toDateTimeString() : ($row['time_c'] ? (string) $row['time_c'] : null);
            $row['time_d'] = $row['time_d'] instanceof Carbon ? $row['time_d']->toDateTimeString() : ($row['time_d'] ? (string) $row['time_d'] : null);

            return $row;
        });

        Cache::put($cacheKey, $serializedRows->all(), 120);

        return $this->cachedBaseRows = $serializedRows;
    }

    /**
     * Số lượng bản ghi cho 3 tab
     *
     * @return array<string, int>
     */
    public function getTabCounts(): array
    {
        $baseRows = $this->getBaseFilteredRows();

        $tripCount = fn (string $serviceType): int => $baseRows->where('service_type', $serviceType)->pluck('trip_id')->unique()->count();

        return [
            'HHHK' => $tripCount('HHHK'),
            'external' => $tripCount('Hàng ngoài'),
            'empty' => $tripCount('Xe không hàng'),
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function getAllRows(): Collection
    {
        $target = $this->activeTab ?: $this->serviceType;
        $rows = $this->rowsForTab($target);

        if ($target !== 'external' || $this->chargeStatus === null) {
            return $rows;
        }

        $charges = $this->chargesByOrderId($rows);

        return $rows->filter(fn (array $row): bool => $this->chargeStatusOf($row, $charges) === $this->chargeStatus)->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function rowsForTab(string $target): Collection
    {
        $rows = $this->getBaseFilteredRows();

        return match ($target) {
            'HHHK' => $rows->filter(fn (array $row): bool => $row['service_type'] === 'HHHK')->values(),
            'external' => $rows->filter(fn (array $row): bool => $row['service_type'] === 'Hàng ngoài')->values(),
            'empty' => $rows->filter(fn (array $row): bool => $row['service_type'] === 'Xe không hàng')->values(),
            default => $rows,
        };
    }

    /**
     * Mỗi chuyến một dòng, kèm các dòng đơn trong 'orders'.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function getTripRows(): Collection
    {
        return $this->cachedTripRows ??= app(TripExcelExportService::class)->groupRowsByTrip($this->getAllRows());
    }

    public function getActiveTabTitle(): string
    {
        return match ($this->activeTab) {
            'HHHK' => 'HHHK (Hàng không)',
            'external' => 'Hàng ngoài',
            'empty' => 'Xe không hàng',
            default => 'Tất cả loại hình',
        };
    }

    /**
     * Dữ liệu cho bảng React: mỗi dòng đã format sẵn phần hiển thị, React chỉ vẽ.
     *
     * @return array<string, mixed>
     */
    public function getReactTableConfiguration(?LengthAwarePaginatorContract $paginator = null): array
    {
        $paginator ??= $this->getPaginator();
        $showCharges = $this->activeTab === 'external';
        $charges = $showCharges ? $this->getChargesForRows($paginator->items()) : collect();

        $rows = [];

        foreach ($paginator->items() as $index => $tripRow) {
            $stt = ($paginator->currentPage() - 1) * $paginator->perPage() + $index + 1;
            $orders = $tripRow['orders'];

            if (count($orders) > 1) {
                $tripTotal = collect($orders)->sum(fn (array $order): float => (float) $charges->get($order['order_id'])?->total);
                $rows[] = $this->reactRow($tripRow, 'trip', $stt, $charges, $tripTotal);

                foreach ($orders as $orderRow) {
                    $rows[] = $this->reactRow($orderRow, 'order', null, $charges);
                }
            } else {
                $rows[] = $this->reactRow($orders[0], 'single', $stt, $charges);
            }
        }

        return [
            'rows' => $rows,
            'showCharges' => $showCharges,
            'showChargeableWeight' => $this->activeTab !== 'HHHK',
            'codeLabel' => $this->activeTab === 'empty' ? 'Mã chuyến' : 'Mã đơn hàng',
            'warehouseLabel' => $this->activeTab === 'empty' ? 'Kho đến' : 'Kho đóng/trả',
            'emptyMessage' => 'Không tìm thấy dữ liệu chuyến nào cho '.$this->getActiveTabTitle().' phù hợp với bộ lọc',
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  Collection<int, OrderCharge>  $charges
     * @return array<string, mixed>
     */
    private function reactRow(array $row, string $variant, ?int $stt, Collection $charges, ?float $tripTotal = null): array
    {
        $charge = filled($row['order_id'] ?? null) ? $charges->get($row['order_id']) : null;
        $time = fn (mixed $value): ?string => $value ? Carbon::parse($value)->format('H:i d/m') : null;
        $positive = fn (mixed $value): ?float => (float) $value > 0 ? (float) $value : null;

        return [
            'key' => $variant.'-'.$row['trip_id'].'-'.($row['order_id'] ?? 'trip'),
            'stt' => $stt,
            'variant' => $variant,
            'orderId' => $row['order_id'] ?? null,
            'ordersCount' => $variant === 'trip' ? count($row['orders'] ?? []) : null,
            'code' => $row['code'] ?? null,
            'plate' => $row['plate_number'] ?? null,
            'date' => filled($row['date'] ?? null) ? Carbon::parse($row['date'])->format('d/m/Y') : null,
            'journey' => $row['journey'] ?? null,
            'timeA' => $time($row['time_a'] ?? null),
            'timeB' => $time($row['time_b'] ?? null),
            'timeC' => $time($row['time_c'] ?? null),
            'timeD' => $time($row['time_d'] ?? null),
            'loading' => $this->formatDuration($row['time_loading'] ?? null),
            'loadingTitle' => $this->formatDurationTooltip($row['time_loading'] ?? null),
            'travel' => $this->formatDuration($row['time_travel'] ?? null),
            'travelTitle' => $this->formatDurationTooltip($row['time_travel'] ?? null),
            'unloading' => $this->formatDuration($row['time_unloading'] ?? null),
            'unloadingTitle' => $this->formatDurationTooltip($row['time_unloading'] ?? null),
            'waiting22h' => ($row['time_waiting_22h'] ?? 0) > 0 ? $this->formatDuration($row['time_waiting_22h']) : null,
            'waiting22hTitle' => $this->formatDurationTooltip($row['time_waiting_22h'] ?? null),
            'totalTime' => $this->formatDuration($row['time_total_trip'] ?? null),
            'totalTimeTitle' => $this->formatDurationTooltip($row['time_total_trip'] ?? null),
            'customer' => $row['customer'] ?? null,
            'warehouse' => $row['warehouse'] ?? null,
            'vehicleOwner' => $row['vehicle_owner'] ?? null,
            'vehicleType' => $row['vehicle_type'] ?? null,
            'note' => $row['note'] ?? null,
            'isReturnTrip' => (bool) ($row['is_return_trip'] ?? false),
            'driverName' => $row['driver_name'] ?? null,
            'kmLoaded' => $positive($row['km_loaded'] ?? 0),
            'kmEmpty' => $positive($row['km_empty'] ?? 0),
            'pcs' => $positive($row['pcs'] ?? 0),
            'gw' => $positive($row['gw'] ?? 0),
            'cargoType' => $row['cargo_type'] ?? null,
            'chargeableWeight' => $positive($row['chargeable_weight'] ?? 0),
            'tripTotal' => $tripTotal,
            'charge' => $charge === null ? null : [
                'priceCode' => $charge->price_code,
                'rateType' => $charge->rate_type ?: 'Chuẩn',
                'basePrice' => $charge->base_price === null ? null : (float) $charge->base_price,
                'fuelPercent' => (float) $charge->fuel_adjustment_percent,
                'adjustedPrice' => $charge->adjusted_price === null ? null : (float) $charge->adjusted_price,
                'waitingFee' => (float) $charge->waiting_fee,
                'waitingDuration' => (int) $charge->waiting_minutes > 0 ? $this->formatDuration((int) $charge->waiting_minutes) : null,
                'otherFees' => (float) $charge->other_fees_total,
                'otherFeesCount' => $charge->fees->count(),
                'vatPercent' => (float) $charge->vat_percent,
                'vatAmount' => (float) $charge->vat_amount,
                'total' => $charge->total === null ? null : (float) $charge->total,
                'locked' => $charge->isLocked(),
                'missing' => ! $charge->isLocked() && $charge->base_price === null,
            ],
        ];
    }

    /**
     * Định dạng thời gian từ số phút sang định dạng giờ và phút (ví dụ: 193h 29p, 45p, 2h).
     */
    public function formatDuration(?int $minutes): string
    {
        if ($minutes === null) {
            return '—';
        }

        if ($minutes <= 0) {
            return '0p';
        }

        $hours = intdiv($minutes, 60);
        $mins = $minutes % 60;

        if ($hours > 0 && $mins > 0) {
            return "{$hours}h {$mins}p";
        }

        if ($hours > 0) {
            return "{$hours}h";
        }

        return "{$mins}p";
    }

    /**
     * Tooltip hiển thị đầy đủ chi tiết thời gian (ví dụ: "193 giờ 29 phút (11,609 phút)").
     */
    public function formatDurationTooltip(?int $minutes): string
    {
        if ($minutes === null) {
            return 'Chưa có dữ liệu';
        }

        if ($minutes <= 0) {
            return '0 phút';
        }

        $hours = intdiv($minutes, 60);
        $mins = $minutes % 60;
        $formattedTotal = number_format($minutes);

        if ($hours > 0 && $mins > 0) {
            return "{$hours} giờ {$mins} phút ({$formattedTotal} phút)";
        }

        if ($hours > 0) {
            return "{$hours} giờ ({$formattedTotal} phút)";
        }

        return "{$mins} phút";
    }

    /**
     * @return array<string, mixed>
     */
    public function getSummaryMetrics(): array
    {
        $rows = $this->getAllRows();
        $tripRows = $this->getTripRows();

        $totalRecords = $rows->whereNotNull('order_id')->count();
        $totalTrips = $tripRows->count();
        $totalKmLoaded = $tripRows->sum('km_loaded');
        $totalKmEmpty = $tripRows->sum('km_empty');
        $totalPcs = $rows->sum('pcs');
        $totalGw = $rows->sum('gw');
        $totalChargeableWeight = $rows->sum('chargeable_weight');

        $rowsWithDuration = $tripRows->whereNotNull('time_total_trip');
        $avgDuration = $rowsWithDuration->isNotEmpty() ? (int) round($rowsWithDuration->avg('time_total_trip')) : 0;
        $countWaiting22h = $tripRows->filter(fn (array $r) => ($r['time_waiting_22h'] ?? 0) > 0)->count();

        return [
            'total_records' => $totalRecords,
            'total_trips' => $totalTrips,
            'total_km_loaded' => $totalKmLoaded,
            'total_km_empty' => $totalKmEmpty,
            'total_km' => $totalKmLoaded + $totalKmEmpty,
            'total_pcs' => $totalPcs,
            'total_gw' => $totalGw,
            'total_chargeable_weight' => $totalChargeableWeight,
            'avg_duration' => $avgDuration,
            'count_waiting_22h' => $countWaiting22h,
            'total_payment' => $this->activeTab === 'external'
                ? (float) OrderCharge::whereIn('order_id', $rows->pluck('order_id')->filter())->sum('total')
                : 0.0,
        ];
    }

    public function getPaginator(): LengthAwarePaginatorContract
    {
        $rows = $this->getTripRows();
        $total = $rows->count();
        $perPage = max(10, $this->perPage);
        $currentPage = max(1, $this->page);

        $items = $rows->slice(($currentPage - 1) * $perPage, $perPage)->values();

        return new LengthAwarePaginator(
            $items,
            $total,
            $perPage,
            $currentPage,
            ['path' => request()->url(), 'query' => request()->query()]
        );
    }

    /**
     * @return array<int, string>
     */
    public function getCustomerOptions(): array
    {
        return Cache::remember('trip_stats_customer_options', 300, function (): array {
            return Customer::query()
                ->where('is_active', true)
                ->orderBy('code')
                ->pluck('code', 'id')
                ->toArray();
        });
    }

    /**
     * Cước của các đơn trên trang hiện tại, theo order_id.
     *
     * @param  iterable<int, array<string, mixed>>  $rows
     * @return Collection<int, OrderCharge>
     */
    public function getChargesForRows(iterable $rows): Collection
    {
        $orderIds = collect($rows)->flatMap(fn (array $row): array => $row['orders'] ?? [$row])->pluck('order_id')->filter()->unique();

        if ($orderIds->isEmpty()) {
            return collect();
        }

        return OrderCharge::query()
            ->with('fees')
            ->whereIn('order_id', $orderIds)
            ->get()
            ->keyBy('order_id');
    }
}
