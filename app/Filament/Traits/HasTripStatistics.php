<?php

namespace App\Filament\Traits;

use App\Enums\CheckpointType;
use App\Enums\OrderStatus;
use App\Enums\TripStatus;
use App\Models\Area;
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
 * Lớp dùng trait phải có các thuộc tính public: startDate, endDate, customerId, areaId, vehicleOwner,
 * search, chargeStatus, page, perPage, activeTab.
 */
trait HasTripStatistics
{
    protected ?Collection $cachedBaseRows = null;

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
            ->get([
                'order_id',
                'price_code',
                'rate_type',
                'base_price',
                'fuel_adjustment_percent',
                'adjusted_price',
                'waiting_minutes',
                'waiting_fee',
                'other_fees_total',
                'vat_amount',
                'total',
                'exported_at',
                'manually_updated_at',
            ])
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
                'orders.tripCheckpoints.deliveryPoint.location',
                'checkpoints.deliveryPoint.location',
                'startLocation.area',
                'endLocation.area',
            ])
            ->when($dateFrom || $dateTo, fn (Builder $q): Builder => $this->applyTripStatsLoadingWindow($q, $dateFrom, $dateTo))
            ->when(filled($search), function (Builder $q) use ($search): Builder {
                return $q->where(function (Builder $sub) use ($search): void {
                    $sub->whereLike('trips.trip_code', "%{$search}%", caseSensitive: false)
                        ->orWhereLike('trips.external_driver_name', "%{$search}%", caseSensitive: false)
                        ->orWhereHas('vehicle', fn ($v) => $v
                            ->whereLike('plate_number', "%{$search}%", caseSensitive: false)
                            ->orWhereLike('external_driver_name', "%{$search}%", caseSensitive: false))
                        ->orWhereHas('driver', fn ($d) => $d->whereLike('name', "%{$search}%", caseSensitive: false))
                        ->orWhereHas('orders', fn (Builder $o): Builder => $this->applyTripStatsOrderSearch($o, $search));
                });
            })
            ->when($this->vehicleOwner !== 'all', function (Builder $q): Builder {
                return $q->whereHas('vehicle', fn ($v) => $v->where('type', $this->vehicleOwner));
            })
            ->when(filled($this->customerId), function (Builder $q): Builder {
                return $q->whereHas('orders', fn ($o) => $o->where('customer_id', $this->customerId));
            })
            ->orderByRaw('COALESCE(trips.completed_at, trips.started_at, trips.created_at) desc');

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
            ->when($dateFrom || $dateTo, fn (Builder $q): Builder => $this->applyUnassignedOrderLoadingWindow($q, $dateFrom, $dateTo))
            ->when(filled($search), fn (Builder $q): Builder => $this->applyTripStatsOrderSearch($q, $search))
            ->when(filled($this->customerId), fn ($q) => $q->where('customer_id', $this->customerId));

        $unassignedOrders = $unassignedOrdersQuery->get();

        /** @var TripExcelExportService $exportService */
        $exportService = app(TripExcelExportService::class);
        $syntheticTrips = $exportService->convertOrdersToTripsCollection($unassignedOrders);

        return $trips->merge($syntheticTrips);
    }

    /**
     * Lọc sơ bộ ở tầng SQL để lấy mọi chuyến có khả năng có ngày đóng hàng (time_a) nằm trong khoảng [dateFrom, dateTo].
     * Độ chính xác tuyệt đối theo ngày đóng hàng của từng dòng được chốt tại getBaseFilteredRows().
     */
    private function applyTripStatsLoadingWindow(Builder $query, ?Carbon $dateFrom, ?Carbon $dateTo): Builder
    {
        return $query->where(function (Builder $q) use ($dateFrom, $dateTo): void {
            $applyRange = static function (Builder $b, string $column) use ($dateFrom, $dateTo): void {
                if ($dateFrom !== null) {
                    $b->where($column, '>=', $dateFrom);
                }
                if ($dateTo !== null) {
                    $b->where($column, '<=', $dateTo);
                }
            };

            $q->where(fn (Builder $sub) => $applyRange($sub, 'trips.started_at'))
                ->orWhere(fn (Builder $sub) => $applyRange($sub, 'trips.completed_at'))
                ->orWhere(fn (Builder $sub) => $sub
                    ->whereNull('trips.started_at')
                    ->whereNull('trips.completed_at')
                    ->tap(fn (Builder $s) => $applyRange($s, 'trips.created_at')))
                ->orWhereHas('orders', fn (Builder $o) => $applyRange($o, 'orders.planned_loading_at'))
                ->orWhereHas('checkpoints', fn (Builder $cp) => $cp
                    ->whereIn('checkpoint_type', [CheckpointType::ArrivedPickup, CheckpointType::Started])
                    ->where(function (Builder $t) use ($applyRange): void {
                        $t->where(fn (Builder $a) => $applyRange($a, 'trip_checkpoints.adjusted_at'))
                            ->orWhere(fn (Builder $oc) => $oc
                                ->whereNull('trip_checkpoints.adjusted_at')
                                ->tap(fn (Builder $s) => $applyRange($s, 'trip_checkpoints.occurred_at')));
                    }));
        });
    }

    private function applyUnassignedOrderLoadingWindow(Builder $query, ?Carbon $dateFrom, ?Carbon $dateTo): Builder
    {
        return $query->where(function (Builder $q) use ($dateFrom, $dateTo): void {
            $applyRange = static function (Builder $b, string $column) use ($dateFrom, $dateTo): void {
                if ($dateFrom !== null) {
                    $b->where($column, '>=', $dateFrom);
                }
                if ($dateTo !== null) {
                    $b->where($column, '<=', $dateTo);
                }
            };

            $q->where(fn (Builder $sub) => $applyRange($sub, 'orders.planned_loading_at'))
                ->orWhereHas('tripCheckpoints', fn (Builder $cp) => $cp
                    ->where('checkpoint_type', CheckpointType::ArrivedPickup)
                    ->where(function (Builder $t) use ($applyRange): void {
                        $t->where(fn (Builder $a) => $applyRange($a, 'trip_checkpoints.adjusted_at'))
                            ->orWhere(fn (Builder $oc) => $oc
                                ->whereNull('trip_checkpoints.adjusted_at')
                                ->tap(fn (Builder $s) => $applyRange($s, 'trip_checkpoints.occurred_at')));
                    }));
        });
    }

    private function applyTripStatsOrderSearch(Builder $query, string $search): Builder
    {
        return $query->where(function (Builder $sub) use ($search): void {
            $sub->whereLike('order_code', "%{$search}%", caseSensitive: false)
                ->orWhereLike('cargo_name', "%{$search}%", caseSensitive: false)
                ->orWhereHas('area', fn ($a) => $a
                    ->whereLike('code', "%{$search}%", caseSensitive: false)
                    ->orWhereLike('name', "%{$search}%", caseSensitive: false))
                ->orWhereHas('customer', fn ($c) => $c
                    ->whereLike('code', "%{$search}%", caseSensitive: false)
                    ->orWhereLike('name', "%{$search}%", caseSensitive: false));
        });
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

        // 0. Lọc chính xác theo Ngày đóng hàng (time_a / date) trong khoảng [startDate 00:00:00, endDate 23:59:59]
        if (filled($this->startDate) || filled($this->endDate)) {
            $dateFrom = filled($this->startDate) ? Carbon::parse($this->startDate)->startOfDay() : null;
            $dateTo = filled($this->endDate) ? Carbon::parse($this->endDate)->endOfDay() : null;

            $rows = $rows->filter(function (array $row) use ($dateFrom, $dateTo): bool {
                $rawLoadingDate = $row['time_a'] ?? $row['date'] ?? null;
                if (blank($rawLoadingDate)) {
                    return false;
                }

                $loadingAt = $rawLoadingDate instanceof Carbon ? $rawLoadingDate : Carbon::parse($rawLoadingDate);

                if ($dateFrom !== null && $loadingAt->lt($dateFrom)) {
                    return false;
                }
                if ($dateTo !== null && $loadingAt->gt($dateTo)) {
                    return false;
                }

                return true;
            })->values();
        }

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
                    $row['area'] ?? '',
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
        $baseRows = $this->filterRowsByArea($this->getBaseFilteredRows());

        $tripCount = fn (string $serviceType): int => $baseRows->where('service_type', $serviceType)->pluck('trip_id')->unique()->count();

        return [
            'HHHK' => $tripCount('HHHK'),
            'external' => $tripCount('Hàng ngoài'),
            'empty' => $tripCount('Xe không hàng'),
        ];
    }

    /**
     * Số chuyến theo từng khu vực (trong tab hiện tại, bỏ qua bộ lọc khu vực đang chọn).
     *
     * @return list<array{id: int, label: string, count: int}>
     */
    public function getAreaFilters(): array
    {
        $target = $this->activeTab ?: $this->serviceType;
        $rows = $this->rowsForTabWithoutArea($target);

        if ($target === 'external' && $this->chargeStatus !== null) {
            $charges = $this->chargesByOrderId($rows);
            $rows = $rows->filter(fn (array $row): bool => $this->chargeStatusOf($row, $charges) === $this->chargeStatus)->values();
        }

        $countsByAreaId = $rows
            ->filter(fn (array $row): bool => filled($row['area_id'] ?? null))
            ->groupBy('area_id')
            ->map(fn (Collection $group): int => $group->pluck('trip_id')->unique()->count());

        return collect($this->getAreaOptions())
            ->map(fn (array $area): array => [
                'id' => $area['id'],
                'label' => $area['label'],
                'count' => (int) $countsByAreaId->get($area['id'], 0),
            ])
            ->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    public function filterRowsByArea(Collection $rows): Collection
    {
        if (! filled($this->areaId)) {
            return $rows;
        }

        return $rows->filter(function (array $row): bool {
            return (string) ($row['area_id'] ?? '') === (string) $this->areaId;
        })->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    public function filterRowsByJourney(Collection $rows): Collection
    {
        $selectedJourneys = array_values(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            (array) ($this->journeys ?? []),
        )));

        if (empty($selectedJourneys)) {
            return $rows;
        }

        return $rows
            ->filter(fn (array $row): bool => in_array(trim((string) ($row['journey'] ?? '')), $selectedJourneys, true))
            ->values();
    }

    /**
     * Danh sách hành trình duy nhất trong tab/khu vực hiện tại kèm số lượng chuyến để lọc nhanh.
     *
     * @return list<array{value: string, count: int}>
     */
    public function getJourneyOptions(): array
    {
        $target = $this->activeTab ?: $this->serviceType;

        return $this->rowsForTab($target)
            ->map(fn (array $row): string => trim((string) ($row['journey'] ?? '')))
            ->filter()
            ->countBy()
            ->map(fn (int $count, string $value): array => [
                'value' => $value,
                'count' => $count,
            ])
            ->sort(fn (array $a, array $b): int => strnatcasecmp($a['value'], $b['value']))
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function getAllRows(): Collection
    {
        $target = $this->activeTab ?: $this->serviceType;
        $rows = $this->filterRowsByJourney($this->rowsForTab($target));

        if ($target === 'external' && $this->chargeStatus !== null) {
            $charges = $this->chargesByOrderId($rows);
            $rows = $rows->filter(fn (array $row): bool => $this->chargeStatusOf($row, $charges) === $this->chargeStatus)->values();
        }

        return $this->sortRows($rows);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    public function sortRows(Collection $rows): Collection
    {
        $sortBy = $this->sortBy ?? null;
        if (! filled($sortBy) || $rows->isEmpty()) {
            return $rows;
        }

        $direction = ($this->sortDirection ?? 'asc') === 'desc' ? 'desc' : 'asc';
        $chargeColumns = [
            'priceCode', 'rateType', 'basePrice', 'fuelPercent',
            'adjustedPrice', 'waitingDuration', 'waitingFee', 'otherFees', 'vat', 'total',
        ];
        $charges = in_array($sortBy, $chargeColumns, true)
            ? $this->chargesByOrderId($rows)
            : collect();

        $values = $rows->all();
        usort($values, function (array $a, array $b) use ($sortBy, $direction, $charges): int {
            $valA = $this->extractSortValue($a, $sortBy, $charges);
            $valB = $this->extractSortValue($b, $sortBy, $charges);

            $emptyA = $valA === null || $valA === '';
            $emptyB = $valB === null || $valB === '';

            if ($emptyA && $emptyB) {
                return 0;
            }
            if ($emptyA) {
                return 1;
            }
            if ($emptyB) {
                return -1;
            }

            $cmp = (is_int($valA) || is_float($valA)) && (is_int($valB) || is_float($valB))
                ? ($valA <=> $valB)
                : strnatcasecmp((string) $valA, (string) $valB);

            return $direction === 'desc' ? -$cmp : $cmp;
        });

        return collect($values);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  Collection<int, OrderCharge>  $charges
     */
    private function extractSortValue(array $row, string $sortBy, Collection $charges): int|float|string|null
    {
        $timestamp = static function (mixed $value): ?int {
            if (blank($value)) {
                return null;
            }

            return ($value instanceof Carbon ? $value : Carbon::parse($value))->getTimestamp();
        };

        $positiveNumber = static function (mixed $value): ?float {
            return (float) $value > 0 ? (float) $value : null;
        };

        $charge = filled($row['order_id'] ?? null) ? $charges->get($row['order_id']) : null;

        return match ($sortBy) {
            'code' => trim((string) ($row['code'] ?? '')),
            'plate' => trim((string) ($row['plate_number'] ?? '')),
            'date' => $timestamp($row['date'] ?? null),
            'journey' => trim((string) ($row['journey'] ?? '')),
            'area' => trim((string) ($row['area'] ?? '')),
            'timeA' => $timestamp($row['time_a'] ?? null),
            'timeB' => $timestamp($row['time_b'] ?? null),
            'timeC' => $timestamp($row['time_c'] ?? null),
            'timeD' => $timestamp($row['time_d'] ?? null),
            'loading' => $row['time_loading'] ?? null,
            'travel' => $row['time_travel'] ?? null,
            'unloading' => $row['time_unloading'] ?? null,
            'waiting22h' => (int) ($row['time_waiting_22h'] ?? 0) > 0 ? (int) $row['time_waiting_22h'] : null,
            'totalTime' => $row['time_total_trip'] ?? null,
            'customer' => trim((string) ($row['customer'] ?? '')),
            'warehouse' => trim((string) ($row['warehouse'] ?? '')),
            'vehicleOwner' => trim((string) ($row['vehicle_owner'] ?? '')),
            'vehicleType' => trim((string) ($row['vehicle_type'] ?? '')),
            'note' => trim((string) ($row['note'] ?? '')),
            'isReturnTrip' => ($row['is_return_trip'] ?? false) ? 1 : 0,
            'driverName' => trim((string) ($row['driver_name'] ?? '')),
            'kmLoaded' => $positiveNumber($row['km_loaded'] ?? 0),
            'kmEmpty' => $positiveNumber($row['km_empty'] ?? 0),
            'pcs' => $positiveNumber($row['pcs'] ?? 0),
            'gw' => $positiveNumber($row['gw'] ?? 0),
            'cargoType' => trim((string) ($row['cargo_type'] ?? '')),
            'chargeableWeight' => $positiveNumber($row['chargeable_weight'] ?? 0),
            'priceCode' => $charge?->price_code,
            'rateType' => $charge?->rate_type,
            'basePrice' => $charge?->base_price !== null ? (float) $charge->base_price : null,
            'fuelPercent' => $charge !== null ? (float) $charge->fuel_adjustment_percent : null,
            'adjustedPrice' => $charge?->adjusted_price !== null ? (float) $charge->adjusted_price : null,
            'waitingDuration' => $charge !== null && (int) $charge->waiting_minutes > 0 ? (int) $charge->waiting_minutes : null,
            'waitingFee' => $charge !== null ? (float) $charge->waiting_fee : null,
            'otherFees' => $charge !== null ? (float) $charge->other_fees_total : null,
            'vat' => $charge !== null ? (float) $charge->vat_amount : null,
            'total' => $charge?->total !== null ? (float) $charge->total : null,
            default => null,
        };
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function rowsForTab(string $target): Collection
    {
        return $this->filterRowsByArea($this->rowsForTabWithoutArea($target));
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function rowsForTabWithoutArea(string $target): Collection
    {
        $rows = $this->getBaseFilteredRows();

        return match ($target) {
            'HHHK' => $rows->filter(fn (array $row): bool => $row['service_type'] === 'HHHK')->values(),
            'external' => $rows->filter(fn (array $row): bool => $row['service_type'] === 'Hàng ngoài')->values(),
            'empty' => $rows->filter(fn (array $row): bool => $row['service_type'] === 'Xe không hàng')->values(),
            default => $rows,
        };
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

        foreach ($paginator->items() as $index => $row) {
            $stt = ($paginator->currentPage() - 1) * $paginator->perPage() + $index + 1;
            $rows[] = $this->reactRow($row, $stt, $charges);
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
    private function reactRow(array $row, int $stt, Collection $charges): array
    {
        $charge = filled($row['order_id'] ?? null) ? $charges->get($row['order_id']) : null;
        $time = fn (mixed $value): ?string => $value ? Carbon::parse($value)->format('H:i d/m') : null;
        $positive = fn (mixed $value): ?float => (float) $value > 0 ? (float) $value : null;

        return [
            'key' => 'single-'.$row['trip_id'].'-'.($row['order_id'] ?? 'trip'),
            'stt' => $stt,
            'variant' => 'single',
            'orderId' => $row['order_id'] ?? null,
            'code' => $row['code'] ?? null,
            'plate' => $row['plate_number'] ?? null,
            'date' => filled($row['date'] ?? null) ? Carbon::parse($row['date'])->format('d/m/Y') : null,
            'journey' => $row['journey'] ?? null,
            'area' => filled($row['area'] ?? null) ? $row['area'] : null,
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
                'isEdited' => $charge->manually_updated_at !== null,
                'updatedAt' => $charge->manually_updated_at?->format('H:i d/m/Y'),
            ],
        ];
    }

    /**
     * Định dạng thời gian từ số phút sang tổng số phút dạng số (ví dụ: 90, 60, 0).
     */
    public function formatDuration(?int $minutes): string
    {
        if ($minutes === null) {
            return '—';
        }

        if ($minutes <= 0) {
            return '0';
        }

        return (string) $minutes;
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

        $totalRecords = $rows->whereNotNull('order_id')->count();
        $totalTrips = $rows->count();
        $totalKmLoaded = $rows->sum('km_loaded');
        $totalKmEmpty = $rows->sum('km_empty');
        $totalPcs = $rows->sum('pcs');
        $totalGw = $rows->sum('gw');
        $totalChargeableWeight = $rows->sum('chargeable_weight');

        $rowsWithDuration = $rows->whereNotNull('time_total_trip');
        $avgDuration = $rowsWithDuration->isNotEmpty() ? (int) round($rowsWithDuration->avg('time_total_trip')) : 0;
        $countWaiting22h = $rows->filter(fn (array $r) => ($r['time_waiting_22h'] ?? 0) > 0)->count();

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
        $rows = $this->getAllRows();
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
     * @return list<array{id: int, label: string}>
     */
    public function getAreaOptions(): array
    {
        return Cache::remember('trip_stats_area_options', 300, function (): array {
            return Area::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get(['id', 'code', 'name'])
                ->map(fn (Area $area): array => [
                    'id' => $area->id,
                    'label' => $area->code === 'PROVINCE' ? 'Điểm khác' : (string) ($area->code ?: $area->name),
                ])
                ->values()
                ->all();
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
        $orderIds = collect($rows)->pluck('order_id')->filter()->unique();

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
