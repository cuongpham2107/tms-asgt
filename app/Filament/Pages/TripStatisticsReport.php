<?php

namespace App\Filament\Pages;

use App\Enums\OrderStatus;
use App\Enums\TripStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Trip;
use App\Services\Export\TripExcelExportService;
use BackedEnum;
use Carbon\Carbon;
use Filament\Pages\Page;
use Illuminate\Contracts\Pagination\LengthAwarePaginator as LengthAwarePaginatorContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

class TripStatisticsReport extends Page
{
    protected static string|UnitEnum|null $navigationGroup = 'Tổng quan';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationLabel = 'Thống kê';

    protected static ?string $title = 'Bảng thống kê tổng hợp các chuyến đã phục vụ';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.pages.trip-statistics-report';

    #[Url]
    public ?string $startDate = null;

    #[Url]
    public ?string $endDate = null;

    #[Url]
    public ?string $datePreset = 'thisMonth';

    #[Url]
    public string $serviceType = 'all';

    #[Url]
    public ?int $customerId = null;

    #[Url]
    public string $vehicleOwner = 'all';

    #[Url]
    public ?string $search = null;

    #[Url]
    public int $perPage = 25;

    public int $page = 1;

    public function mount(): void
    {
        if (blank($this->startDate)) {
            $this->startDate = now()->startOfMonth()->format('Y-m-d');
        }

        if (blank($this->endDate)) {
            $this->endDate = now()->format('Y-m-d');
        }

        $this->syncDatePreset();
    }

    public function updatedSearch(): void
    {
        $this->page = 1;
    }

    public function updatedStartDate(): void
    {
        $this->syncDatePreset();
        $this->page = 1;
    }

    public function updatedEndDate(): void
    {
        $this->syncDatePreset();
        $this->page = 1;
    }

    public function updatedServiceType(): void
    {
        $this->page = 1;
    }

    public function updatedCustomerId(): void
    {
        $this->page = 1;
    }

    public function updatedVehicleOwner(): void
    {
        $this->page = 1;
    }

    public function updatedPerPage(): void
    {
        $this->page = 1;
    }

    public function gotoPage(int $page): void
    {
        $this->page = $page;
    }

    public function setDatePreset(string $preset): void
    {
        $this->datePreset = $preset;

        match ($preset) {
            'today' => [
                $this->startDate = now()->format('Y-m-d'),
                $this->endDate = now()->format('Y-m-d'),
            ],
            'yesterday' => [
                $this->startDate = now()->subDay()->format('Y-m-d'),
                $this->endDate = now()->subDay()->format('Y-m-d'),
            ],
            'last7days' => [
                $this->startDate = now()->subDays(6)->format('Y-m-d'),
                $this->endDate = now()->format('Y-m-d'),
            ],
            'thisMonth' => [
                $this->startDate = now()->startOfMonth()->format('Y-m-d'),
                $this->endDate = now()->format('Y-m-d'),
            ],
            'lastMonth' => [
                $this->startDate = now()->subMonth()->startOfMonth()->format('Y-m-d'),
                $this->endDate = now()->subMonth()->endOfMonth()->format('Y-m-d'),
            ],
            default => null,
        };

        $this->page = 1;
    }

    public function syncDatePreset(): void
    {
        $today = now()->format('Y-m-d');
        $yesterday = now()->subDay()->format('Y-m-d');

        $this->datePreset = match (true) {
            $this->startDate === $today && $this->endDate === $today => 'today',
            $this->startDate === $yesterday && $this->endDate === $yesterday => 'yesterday',
            $this->startDate === now()->subDays(6)->format('Y-m-d') && $this->endDate === $today => 'last7days',
            $this->startDate === now()->startOfMonth()->format('Y-m-d') && $this->endDate === $today => 'thisMonth',
            $this->startDate === now()->subMonth()->startOfMonth()->format('Y-m-d') && $this->endDate === now()->subMonth()->endOfMonth()->format('Y-m-d') => 'lastMonth',
            default => null,
        };
    }

    public function isDatePresetActive(string $preset): bool
    {
        if ($this->datePreset === $preset) {
            return true;
        }

        $today = now()->format('Y-m-d');
        $yesterday = now()->subDay()->format('Y-m-d');

        return match ($preset) {
            'today' => $this->startDate === $today && $this->endDate === $today,
            'yesterday' => $this->startDate === $yesterday && $this->endDate === $yesterday,
            'last7days' => $this->startDate === now()->subDays(6)->format('Y-m-d') && $this->endDate === $today,
            'thisMonth' => $this->startDate === now()->startOfMonth()->format('Y-m-d') && $this->endDate === $today,
            'lastMonth' => $this->startDate === now()->subMonth()->startOfMonth()->format('Y-m-d') && $this->endDate === now()->subMonth()->endOfMonth()->format('Y-m-d'),
            default => false,
        };
    }

    public function resetFilters(): void
    {
        $this->startDate = now()->startOfMonth()->format('Y-m-d');
        $this->endDate = now()->format('Y-m-d');
        $this->datePreset = 'thisMonth';
        $this->serviceType = 'all';
        $this->customerId = null;
        $this->vehicleOwner = 'all';
        $this->search = null;
        $this->page = 1;
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
     * @return Collection<int, array<string, mixed>>
     */
    public function getAllRows(): Collection
    {
        $trips = $this->getFilteredTrips();

        /** @var TripExcelExportService $exportService */
        $exportService = app(TripExcelExportService::class);
        $rows = $exportService->getTripSummaryRows($trips);

        // 1. Lọc theo Loại hình phục vụ
        if ($this->serviceType !== 'all') {
            $rows = $rows->filter(function (array $row): bool {
                return match ($this->serviceType) {
                    'HHHK' => $row['service_type'] === 'HHHK',
                    'external' => $row['service_type'] === 'Hàng ngoài',
                    'empty' => $row['service_type'] === 'Xe không hàng',
                    default => true,
                };
            })->values();
        }

        // 2. Lọc theo Khách hàng
        if (filled($this->customerId)) {
            $rows = $rows->filter(function (array $row): bool {
                return (string) ($row['customer_id'] ?? '') === (string) $this->customerId;
            })->values();
        }

        // 3. Lọc theo Quản lý xe (Xe công ty / Xe thuê)
        if ($this->vehicleOwner !== 'all') {
            $rows = $rows->filter(function (array $row): bool {
                $targetOwner = $this->vehicleOwner === 'company' ? 'Xe công ty' : 'Xe thuê';

                return ($row['vehicle_owner'] ?? '') === $targetOwner;
            })->values();
        }

        // 4. Lọc theo Từ khóa tìm kiếm
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

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    public function getSummaryMetrics(): array
    {
        $rows = $this->getAllRows();

        $totalRecords = $rows->count();
        $totalTrips = $rows->pluck('trip_id')->filter()->unique()->count();
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
        return Customer::query()
            ->where('is_active', true)
            ->orderBy('code')
            ->pluck('code', 'id')
            ->toArray();
    }

    public function exportExcel(): StreamedResponse
    {
        $rows = $this->getAllRows();
        $trips = $this->getFilteredTrips();

        /** @var TripExcelExportService $exportService */
        $exportService = app(TripExcelExportService::class);
        $filename = 'Bao-cao-thong-ke-chuyen-'.now()->format('Ymd-His').'.xlsx';

        return $exportService->exportFromRows($rows, $trips, $filename);
    }
}
