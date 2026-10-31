<?php

namespace App\Filament\Resources\Trips\Pages;

use App\Enums\GeoCheckStatus;
use App\Enums\TripKmReportStatus;
use App\Enums\TripStatus;
use App\Filament\Forms\Components\OrderDateRangePicker;
use App\Filament\Forms\Components\PillFilter;
use App\Filament\Resources\Trips\Actions\CreateEmptyRunAction;
use App\Filament\Resources\Trips\Actions\ExportTripsExcelAction;
use App\Filament\Resources\Trips\TripResource;
use App\Models\Area;
use App\Models\Trip;
use App\Services\Export\TripExcelExportService;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Request;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListTrips extends ListRecords
{
    protected static string $resource = TripResource::class;

    protected string $view = 'filament.resources.trips.pages.list-trips';

    /**
     * Fallback polling interval (60s) nếu kết nối WebSocket bị gián đoạn.
     */
    public function getTablePollingInterval(): ?string
    {
        return '60s';
    }

    /**
     * Lắng nghe broadcast event realtime qua Laravel Reverb / Echo.
     * Khi tài xế cập nhật chuyến (checkpoint, hoàn thành, báo sai km, ...)
     * hoặc chuyến bị thay đổi, giao diện tự động làm mới thẻ thống kê và bảng chuyến xe.
     *
     * @param  array<string, mixed>|null  $event
     */
    #[On('echo:trips,TripUpdated')]
    #[On('echo:trips,.TripUpdated')]
    public function onTripUpdated(?array $event = null): void
    {
        if (filled($this->mountedActions ?? [])) {
            return;
        }

        $this->memoizedCounts = [];
        $this->flushCachedTableRecords();
    }

    #[Url(keep: true)]
    public ?string $dateFrom = null;

    #[Url(keep: true)]
    public ?string $dateTo = null;

    public ?array $dateRange = null;

    #[Url]
    public ?string $tripSearch = null;

    #[Url]
    public ?string $activeStatusFilter = 'all';

    #[Url]
    public string $vehicleOwner = 'all';

    #[Url]
    public string $orderType = 'HHHK';

    #[Url]
    public ?string $activePlaceFilter = 'NBA';

    /**
     * Catch layer clicks from the map widget inside the column-action modal.
     * The action uses `<x-filament-leaflet::map widget>` which dispatches
     * Livewire calls that would otherwise throw MethodNotFoundException.
     */
    public function handleLayerClick(string $layerId): void {}

    public function handleMapClick(float $latitude, float $longitude): void {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handleLayerUpdated(string $layerId, array $data): void {}

    /**
     * @var array<string, string>
     */
    public array $orderPlaceFilters = [];

    public array $orderTypeFilters = [
        'HHHK' => ['label' => 'HHHK', 'color' => 'bg-blue-500'],
        'external' => ['label' => 'Hàng ngoài', 'color' => 'bg-amber-500'],
        'empty' => ['label' => 'Chuyến không hàng', 'color' => 'bg-violet-500'],
        'all' => ['label' => 'Tất cả', 'color' => 'bg-blue-600'],
    ];

    public array $tripStatusFilters = [
        'all' => ['label' => 'Tất cả', 'color' => 'bg-blue-600'],
        'pending' => ['label' => 'Chờ chạy', 'color' => 'bg-sky-500'],
        'running' => ['label' => 'Đang chạy', 'color' => 'bg-sky-500'],
        'completed' => ['label' => 'Hoàn thành', 'color' => 'bg-emerald-500'],
        'driver_swap' => ['label' => 'Đảo lái', 'color' => 'bg-red-600'],
        'km_report' => ['label' => 'Báo sai km', 'color' => 'bg-amber-500'],
        'geo_out_of_range' => ['label' => 'Sai vị trí', 'color' => 'bg-rose-500'],
        'cancelled' => ['label' => 'Đã huỷ', 'color' => 'bg-red-500'],
    ];

    public array $vehicleOwnerFilters = [
        'all' => ['label' => 'Tất cả', 'color' => 'bg-blue-600'],
        'company' => ['label' => 'Xe công ty', 'color' => 'bg-blue-500'],
        'rent' => ['label' => 'Xe thuê ngoài', 'color' => 'bg-amber-500'],
    ];

    /** Chuyến được mở thẳng qua link (?tableActionRecord=…); giữ lại cho các request sau (bảng tải chậm). */
    public ?string $deepLinkTripId = null;

    public function mount(): void
    {
        $actionRecordId = Request::query('tableActionRecord');
        $this->deepLinkTripId = filled($actionRecordId) ? (string) $actionRecordId : null;
        if (filled($actionRecordId)) {
            $targetTrip = Trip::find($actionRecordId);
            if ($targetTrip) {
                $requestedStatus = Request::query('activeStatusFilter');
                $this->activeStatusFilter = filled($requestedStatus) ? $requestedStatus : 'all';
                $this->orderType = 'all';
                $this->activePlaceFilter = 'all';
                $this->vehicleOwner = 'all';
                $this->dateFrom = null;
                $this->dateTo = null;
                $this->tripSearch = $targetTrip->trip_code;
            }
        }

        $this->dateRange = [
            'start' => $this->dateFrom,
            'end' => $this->dateTo,
        ];

        parent::mount();

        $this->mountedActions ??= [];

        $this->orderPlaceFilters = Area::query()
            ->where('is_active', true)
            ->orderBy('sort_order', 'asc')
            ->pluck('code', 'code')
            ->map(fn (string $code): string => $code === 'PROVINCE' ? 'Điểm khác' : $code)
            ->toArray();
    }

    protected function getHeaderActions(): array
    {
        return [
            ExportTripsExcelAction::make(),
            CreateEmptyRunAction::make(),
            // CreateOrderHHHKAction::make(),
            // CreateOrderHNAction::make(),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [];
    }

    /**
     * @var array<string, mixed>
     */
    protected array $memoizedCounts = [];

    protected function getMemoized(string $group, callable $callback): mixed
    {
        $key = $group.':'.md5(json_encode([
            $this->dateFrom,
            $this->dateTo,
            $this->activeStatusFilter,
            $this->vehicleOwner,
            $this->orderType,
            $this->activePlaceFilter,
        ]));

        return $this->memoizedCounts[$key] ??= $callback();
    }

    /**
     * @return array<string, int>
     */
    protected function getTripStatusCounts(): array
    {
        return $this->getMemoized('trip_status_counts', function (): array {
            $baseQuery = $this->applyActiveFilters(Trip::query(), except: 'status');

            $statusCounts = (clone $baseQuery)
                ->selectRaw('trips.status, count(*) as count')
                ->groupBy('trips.status')
                ->pluck('count', 'status')
                ->map(fn ($c): int => (int) $c)
                ->toArray();

            $total = (int) array_sum($statusCounts);
            $pending = (int) ($statusCounts[TripStatus::Pending->value] ?? 0);

            $runningStatuses = [
                TripStatus::Started->value,
                TripStatus::ArrivedPickup->value,
                TripStatus::Delivering->value,
                TripStatus::ArrivedDelivery->value,
                TripStatus::DriverSwap->value,
                TripStatus::ReturnTrip->value,
            ];
            $running = 0;
            foreach ($runningStatuses as $rs) {
                $running += (int) ($statusCounts[$rs] ?? 0);
            }

            $completed = (int) ($statusCounts[TripStatus::Completed->value] ?? 0)
                + (int) ($statusCounts[TripStatus::Delivered->value] ?? 0);
            $cancelled = (int) ($statusCounts[TripStatus::Cancelled->value] ?? 0);
            $returnTrip = (int) ($statusCounts[TripStatus::ReturnTrip->value] ?? 0);
            $driverSwap = (int) ($statusCounts[TripStatus::DriverSwap->value] ?? 0);

            $kmReport = (int) (clone $baseQuery)
                ->where(function (Builder $q) {
                    $q->where('trips.is_paused', true)
                        ->orWhereHas('kmReports', fn (Builder $kr) => $kr->where('status', TripKmReportStatus::Pending->value));
                })
                ->count();

            // Active total excluding completed and cancelled
            $activeTotal = $total - $completed - $cancelled;

            return [
                'total' => $total,
                'all' => $activeTotal,
                'pending' => $pending,
                'running' => $running,
                'started' => (int) ($statusCounts[TripStatus::Started->value] ?? 0),
                'arrived_pickup' => (int) ($statusCounts[TripStatus::ArrivedPickup->value] ?? 0),
                'delivering' => (int) ($statusCounts[TripStatus::Delivering->value] ?? 0),
                'arrived_delivery' => (int) ($statusCounts[TripStatus::ArrivedDelivery->value] ?? 0),
                'delivered' => (int) ($statusCounts[TripStatus::Delivered->value] ?? 0),
                'completed' => $completed,
                'return_trip' => $returnTrip,
                'driver_swap' => $driverSwap,
                'km_report' => $kmReport,
                'cancelled' => $cancelled,
            ];
        });
    }

    /**
     * @return array<int, array{key: string, label: string, value: int, icon: string, color: string, bg: string, border: string, filter: ?string}>
     */
    public function getTripStats(): array
    {
        $counts = $this->getTripStatusCounts();

        return [
            [
                'key' => 'all',
                'label' => 'Tổng chuyến',
                'value' => $counts['total'],
                'icon' => 'heroicon-o-truck',
                'color' => 'text-blue-600 dark:text-blue-400',
                'bg' => 'bg-blue-50 dark:bg-blue-950/40',
                'border' => 'border-blue-100 dark:border-blue-900/40',
                'filter' => 'all',
            ],
            [
                'key' => 'pending',
                'label' => 'Chờ chạy',
                'value' => $counts['pending'],
                'icon' => 'heroicon-o-clock',
                'color' => 'text-amber-600 dark:text-amber-400',
                'bg' => 'bg-amber-50 dark:bg-amber-950/40',
                'border' => 'border-amber-100 dark:border-amber-900/40',
                'filter' => 'pending',
            ],
            [
                'key' => 'running',
                'label' => 'Đang chạy',
                'value' => $counts['running'],
                'icon' => 'heroicon-o-play-circle',
                'color' => 'text-sky-600 dark:text-sky-400',
                'bg' => 'bg-sky-50 dark:bg-sky-950/40',
                'border' => 'border-sky-100 dark:border-sky-900/40',
                'filter' => 'running',
            ],
            [
                'key' => 'completed',
                'label' => 'Hoàn thành',
                'value' => $counts['completed'],
                'icon' => 'heroicon-o-check-circle',
                'color' => 'text-emerald-600 dark:text-emerald-400',
                'bg' => 'bg-emerald-50 dark:bg-emerald-950/40',
                'border' => 'border-emerald-100 dark:border-emerald-900/40',
                'filter' => 'completed',
            ],
        ];
    }

    public function filterStatus(string $status): void
    {
        $this->activeStatusFilter = $status;

        $this->resetPage();
    }

    public function filterVehicleOwner(string $owner): void
    {
        $this->vehicleOwner = $owner;

        $this->resetPage();
    }

    public function filterOrderType(string $type): void
    {
        $this->orderType = $type;

        $this->resetPage();
    }

    public function filterPlace(string $place): void
    {
        $this->activePlaceFilter = $place;

        $this->resetPage();
    }

    public function getTripStatusCount(string $key): int
    {
        $counts = $this->getTripStatusCounts();

        return $counts[$key] ?? 0;
    }

    /**
     * @return array<string, int>
     */
    protected function getOrderTypeCounts(): array
    {
        return $this->getMemoized('order_type_counts', function (): array {
            $base = $this->applyActiveFilters(Trip::query(), except: 'orderType');

            $counts = (clone $base)
                ->join('orders', 'trips.id', '=', 'orders.trip_id')
                ->selectRaw('orders.type, count(distinct trips.id) as count')
                ->groupBy('orders.type')
                ->pluck('count', 'type')
                ->map(fn ($c): int => (int) $c)
                ->toArray();

            $counts['empty'] = (int) (clone $base)->whereDoesntHave('orders')->count();

            $counts['all'] = (int) (clone $base)->count();

            return $counts;
        });
    }

    public function getOrderTypeCount(string $key): int
    {
        $counts = $this->getOrderTypeCounts();

        return $counts[$key] ?? 0;
    }

    /**
     * @return array<string, int>
     */
    protected function getVehicleOwnerCounts(): array
    {
        return $this->getMemoized('vehicle_owner_counts', function (): array {
            $base = $this->applyActiveFilters(Trip::query(), except: 'vehicleOwner');

            $counts = (clone $base)
                ->join('vehicles', 'trips.vehicle_id', '=', 'vehicles.id')
                ->selectRaw('vehicles.type, count(distinct trips.id) as count')
                ->groupBy('vehicles.type')
                ->pluck('count', 'type')
                ->map(fn ($c): int => (int) $c)
                ->toArray();

            $counts['all'] = (int) (clone $base)->count();

            return $counts;
        });
    }

    public function getVehicleOwnerCount(string $key): int
    {
        $counts = $this->getVehicleOwnerCounts();

        return $counts[$key] ?? 0;
    }

    /**
     * @return array<string, int>
     */
    protected function getOrderPlaceCounts(): array
    {
        return $this->getMemoized('order_place_counts', function (): array {
            $base = $this->applyActiveFilters(Trip::query(), except: 'place');

            $counts = (clone $base)
                ->join('orders', 'trips.id', '=', 'orders.trip_id')
                ->join('areas', 'orders.area_id', '=', 'areas.id')
                ->selectRaw('areas.code, count(distinct trips.id) as count')
                ->groupBy('areas.code')
                ->pluck('count', 'code')
                ->map(fn ($c): int => (int) $c)
                ->toArray();

            $counts['all'] = (int) (clone $base)->count();

            return $counts;
        });
    }

    public function getOrderPlaceCount(string $place): int
    {
        $counts = $this->getOrderPlaceCounts();

        return $counts[$place] ?? 0;
    }

    private function applyActiveFilters(Builder $query, string $except = ''): Builder
    {
        return $this->applyDateRangeFilter($query, $this->dateFrom, $this->dateTo)
            ->when($except !== 'status' && $this->activeStatusFilter !== 'all', fn (Builder $query): Builder => $this->applyStatusFilterByKey($query, $this->activeStatusFilter))
            ->when($except !== 'status' && $this->activeStatusFilter === 'all', fn (Builder $query): Builder => $query->whereNotIn('trips.status', [TripStatus::Completed->value, TripStatus::Delivered->value, TripStatus::Cancelled->value]))
            ->when($except !== 'vehicleOwner' && $this->vehicleOwner !== 'all', fn (Builder $query): Builder => $query->whereHas('vehicle', fn (Builder $q) => $q->where('type', $this->vehicleOwner)))
            ->when($except !== 'orderType' && $this->orderType !== 'all', function (Builder $query): Builder {
                if ($this->orderType === 'empty') {
                    return $query->whereDoesntHave('orders');
                }

                return $query->whereHas('orders', fn (Builder $q): Builder => $q->where('type', $this->orderType));
            })
            ->when($except !== 'place' && $this->activePlaceFilter !== 'all', fn (Builder $query): Builder => $query->whereHas('orders.area', fn (Builder $q) => $q->where('code', $this->activePlaceFilter)));
    }

    public function filtersForm(Schema $form): Schema
    {
        return $form
            ->components([
                PillFilter::make('orderType')
                    ->options($this->orderTypeFilters)
                    ->countCallback(fn ($key) => $this->getOrderTypeCount($key))
                    ->activeValue(fn ($livewire) => $livewire->orderType)
                    ->clickAction('filterOrderType'),
                PillFilter::make('activeStatusFilter')
                    ->options($this->tripStatusFilters)
                    ->countCallback(fn ($key) => $this->getTripStatusCount($key))
                    ->activeValue(fn ($livewire) => $livewire->activeStatusFilter)
                    ->clickAction('filterStatus'),
                PillFilter::make('vehicleOwner')
                    ->options($this->vehicleOwnerFilters)
                    ->countCallback(fn ($key) => $this->getVehicleOwnerCount($key))
                    ->activeValue(fn ($livewire) => $livewire->vehicleOwner)
                    ->clickAction('filterVehicleOwner'),
                PillFilter::make('activePlaceFilter')
                    ->options(fn (): array => $this->orderPlaceFilters + ['all' => 'Tất cả'])
                    ->countCallback(fn ($key) => $this->getOrderPlaceCount($key))
                    ->activeValue(fn ($livewire) => $livewire->activePlaceFilter)
                    ->clickAction('filterPlace'),

            ]);
    }

    public function exportExcel(?TripExcelExportService $exportService = null, array $data = []): StreamedResponse
    {
        $exportService ??= app(TripExcelExportService::class);
        $query = empty($data) ? $this->getTableQuery() : $this->getExportTripsQuery($data);

        return $exportService->export($query);
    }

    public function getExportTripsQuery(array $data): Builder
    {
        $status = $data['status'] ?? 'all';
        $orderType = $data['order_type'] ?? 'all';
        $vehicleOwner = $data['vehicle_owner'] ?? 'all';
        $place = $data['place'] ?? 'all';
        $vehicleId = $data['vehicle_id'] ?? null;
        $driverId = $data['driver_id'] ?? null;
        $search = trim((string) ($data['search'] ?? ''));
        $dateFrom = $data['date_from'] ?? null;
        $dateTo = $data['date_to'] ?? null;

        /** @var Builder $query */
        $query = TripResource::getEloquentQuery()
            ->with([
                'vehicle',
                'driver',
                'orders.customer',
                'orders.area',
                'orders.pickupLocation',
                'orders.deliveryPoints.location',
                'orders.tripCheckpoints.deliveryPoint.location',
            ])
            ->when(
                $status === 'active',
                fn (Builder $query): Builder => $query->whereNotIn('trips.status', [TripStatus::Completed->value, TripStatus::Delivered->value, TripStatus::Cancelled->value]),
            )
            ->when(
                $status !== 'all' && $status !== 'active',
                fn (Builder $query): Builder => $this->applyStatusFilterByKey($query, $status),
            )
            ->when(
                $vehicleOwner !== 'all',
                fn (Builder $query): Builder => $query->whereHas('vehicle', fn (Builder $q): Builder => $q->where('type', $vehicleOwner)),
            )
            ->when(
                $orderType !== 'all',
                function (Builder $query) use ($orderType): Builder {
                    if ($orderType === 'empty') {
                        return $query->whereDoesntHave('orders');
                    }

                    return $query->whereHas('orders', fn (Builder $q): Builder => $q->where('type', $orderType));
                },
            )
            ->when(
                $place !== 'all',
                fn (Builder $query): Builder => $query->whereHas('orders.area', fn (Builder $aq): Builder => $aq->where('code', $place)),
            )
            ->when(
                filled($vehicleId),
                fn (Builder $query): Builder => $query->where('trips.vehicle_id', $vehicleId),
            )
            ->when(
                filled($driverId),
                fn (Builder $query): Builder => $query->where('trips.driver_id', $driverId),
            )
            ->when(
                filled($dateFrom) || filled($dateTo),
                fn (Builder $query): Builder => $this->applyDateRangeFilter($query, $dateFrom, $dateTo),
            )
            ->when(filled($search), function (Builder $query) use ($search): Builder {
                return $query->where(function (Builder $query) use ($search): void {
                    $query
                        ->whereHas('vehicle', fn (Builder $q) => $q->where('plate_number', 'like', "%{$search}%"))
                        ->orWhereHas('orders', fn (Builder $q) => $q
                            ->where('order_code', 'like', "%{$search}%")
                            ->orWhere('cargo_name', 'like', "%{$search}%")
                            ->orWhereHas('trip.driver', fn (Builder $qd) => $qd->where('name', 'like', "%{$search}%"))
                            ->orWhereHas('area', fn (Builder $qa) => $qa->where('code', 'like', "%{$search}%"))
                            ->orWhereHas('customer', fn (Builder $qc) => $qc->where('name', 'like', "%{$search}%"))
                        );
                });
            })
            ->orderBy('trips.started_at', 'desc')
            ->orderBy('trips.created_at', 'desc');

        return $query;
    }

    public function searchForm(Schema $form): Schema
    {
        return $form
            ->components([
                TextInput::make('tripSearch')
                    ->hiddenLabel()
                    ->placeholder('Tìm chuyến đi, lái xe, BSX, khu vực...')
                    ->prefixIcon('heroicon-m-magnifying-glass')
                    ->extraInputAttributes(['type' => 'search'])
                    ->live(debounce: 400),
            ]);
    }

    public function dateRangeForm(Schema $form): Schema
    {
        return $form
            ->components([
                OrderDateRangePicker::make()
                    ->syncWithProperties('dateFrom', 'dateTo'),
            ]);
    }

    protected function resolveTableRecord(?string $key): Model|array|null
    {
        if ($key === null) {
            return null;
        }

        return TripResource::getEloquentQuery()
            ->with([
                'vehicle',
                'driver',
                'orders.customer',
                'orders.area',
                'orders.pickupLocation',
                'orders.deliveryPoints.location',
                'latestPendingKmReport',
            ])
            ->find($key);
    }

    protected function getTableQuery(): Builder
    {
        $statusOrder = [
            TripStatus::Pending->value => 0,
            TripStatus::Started->value => 1,
            TripStatus::ArrivedPickup->value => 2,
            TripStatus::Delivering->value => 3,
            TripStatus::ArrivedDelivery->value => 4,
            TripStatus::Delivered->value => 5,
            TripStatus::DriverSwap->value => 6,
            TripStatus::ReturnTrip->value => 7,
            TripStatus::Completed->value => 8,
            TripStatus::Cancelled->value => 9,
        ];

        $caseSql = 'CASE trips.status '
            .collect($statusOrder)->map(fn ($ord, $status) => "WHEN '{$status}' THEN {$ord}")->implode(' ')
            .' ELSE 99 END';

        $actionRecordId = $this->deepLinkTripId;

        return TripResource::getEloquentQuery()
            ->orderByRaw($caseSql)
            ->orderBy('trips.updated_at', 'desc')
            ->with([
                'vehicle',
                'driver',
                'orders.customer',
                'orders.area',
                'orders.pickupLocation',
                'orders.deliveryPoints.location',
            ])
            ->when(
                $this->activeStatusFilter !== 'all',
                function (Builder $query) use ($actionRecordId): Builder {
                    return $query->where(function (Builder $q) use ($actionRecordId) {
                        $this->applyStatusFilterByKey($q, $this->activeStatusFilter);
                        if ($actionRecordId) {
                            $q->orWhere('trips.id', $actionRecordId);
                        }
                    });
                },
                function (Builder $query) use ($actionRecordId): Builder {
                    return $query->where(function (Builder $q) use ($actionRecordId) {
                        $q->whereNotIn('trips.status', [TripStatus::Completed->value, TripStatus::Delivered->value, TripStatus::Cancelled->value]);
                        if ($actionRecordId) {
                            $q->orWhere('trips.id', $actionRecordId);
                        }
                    });
                },
            )
            ->when($this->vehicleOwner !== 'all', function (Builder $query) use ($actionRecordId): Builder {
                return $query->where(function (Builder $q) use ($actionRecordId) {
                    $q->whereHas('vehicle', fn (Builder $sq) => $sq->where('type', $this->vehicleOwner));
                    if ($actionRecordId) {
                        $q->orWhere('trips.id', $actionRecordId);
                    }
                });
            })
            ->when($this->orderType !== 'all', function (Builder $query) use ($actionRecordId): Builder {
                return $query->where(function (Builder $q) use ($actionRecordId) {
                    if ($this->orderType === 'empty') {
                        $q->whereDoesntHave('orders');
                    } else {
                        $q->whereHas('orders', fn (Builder $sq) => $sq->where('type', $this->orderType));
                    }
                    if ($actionRecordId) {
                        $q->orWhere('trips.id', $actionRecordId);
                    }
                });
            })
            ->when(
                $this->activePlaceFilter !== 'all',
                function (Builder $query) use ($actionRecordId): Builder {
                    return $query->where(function (Builder $q) use ($actionRecordId) {
                        $q->whereHas('orders.area', fn (Builder $aq) => $aq->where('code', $this->activePlaceFilter));
                        if ($actionRecordId) {
                            $q->orWhere('trips.id', $actionRecordId);
                        }
                    });
                },
            )
            ->when(filled($this->dateFrom) || filled($this->dateTo), function (Builder $query) use ($actionRecordId): Builder {
                return $query->where(function (Builder $dateQuery) use ($actionRecordId): void {
                    $this->applyDateRangeFilter($dateQuery, $this->dateFrom, $this->dateTo);

                    if ($actionRecordId) {
                        $dateQuery->orWhere('trips.id', $actionRecordId);
                    }
                });
            })
            ->when(filled($this->tripSearch), function (Builder $query) use ($actionRecordId): Builder {
                $search = trim((string) $this->tripSearch);

                return $query->where(function (Builder $query) use ($search, $actionRecordId): void {
                    $query
                        ->where('trips.trip_code', 'like', "%{$search}%")
                        ->orWhereHas('vehicle', fn (Builder $q) => $q->where('plate_number', 'like', "%{$search}%"))
                        ->orWhereHas('orders', fn (Builder $q) => $q
                            ->where('order_code', 'like', "%{$search}%")
                            ->orWhere('cargo_name', 'like', "%{$search}%")
                            ->orWhereHas('trip.driver', fn (Builder $qd) => $qd->where('name', 'like', "%{$search}%"))
                            ->orWhereHas('area', fn (Builder $qa) => $qa->where('code', 'like', "%{$search}%"))
                            ->orWhereHas('customer', fn (Builder $qc) => $qc->where('name', 'like', "%{$search}%"))
                        );

                    if ($actionRecordId) {
                        $query->orWhere('trips.id', $actionRecordId);
                    }
                });
            });
    }

    /**
     * Lọc theo khoảng ngày của chuyến.
     *
     * Chuyến nháp/chờ chạy chưa khởi hành nên `started_at` = NULL. Nếu chỉ so sánh
     * trên `started_at` thì các bản ghi này bị loại sạch khỏi kết quả, nên với
     * chuyến chưa có `started_at` ta dùng `created_at` thay thế.
     */
    private function applyDateRangeFilter(Builder $query, ?string $dateFrom, ?string $dateTo): Builder
    {
        $from = filled($dateFrom) ? Carbon::parse($dateFrom)->startOfDay() : null;
        $to = filled($dateTo) ? Carbon::parse($dateTo)->endOfDay() : null;

        if (! $from && ! $to) {
            return $query;
        }

        return $query->where(function (Builder $dateQuery) use ($from, $to): void {
            $dateQuery
                ->where(function (Builder $started) use ($from, $to): void {
                    $started->whereNotNull('trips.started_at');
                    $this->applyDateBound($started, 'trips.started_at', $from, $to);
                })
                ->orWhere(function (Builder $draft) use ($from, $to): void {
                    $draft->whereNull('trips.started_at');
                    $this->applyDateBound($draft, 'trips.created_at', $from, $to);
                });
        });
    }

    private function applyDateBound(Builder $query, string $column, ?Carbon $from, ?Carbon $to): void
    {
        if ($from && $to) {
            $query->whereBetween($column, [$from, $to]);
        } elseif ($from) {
            $query->where($column, '>=', $from);
        } else {
            $query->where($column, '<=', $to);
        }
    }

    private function applyStatusFilterByKey(Builder $query, string $key): Builder
    {
        return match ($key) {
            'pending' => $query->where('trips.status', TripStatus::Pending->value),
            'running' => $query->whereIn('trips.status', [
                TripStatus::Started->value,
                TripStatus::ArrivedPickup->value,
                TripStatus::Delivering->value,
                TripStatus::ArrivedDelivery->value,
                TripStatus::DriverSwap->value,
                TripStatus::ReturnTrip->value,
            ]),
            'started' => $query->where('trips.status', TripStatus::Started->value),
            'arrived_pickup' => $query->where('trips.status', TripStatus::ArrivedPickup->value),
            'delivering' => $query->where('trips.status', TripStatus::Delivering->value),
            'arrived_delivery' => $query->where('trips.status', TripStatus::ArrivedDelivery->value),
            'delivered' => $query->where('trips.status', TripStatus::Delivered->value),
            'driver_swap' => $query->where('trips.status', TripStatus::DriverSwap->value),
            'return_trip' => $query->where('trips.status', TripStatus::ReturnTrip->value),
            'completed' => $query->whereIn('trips.status', [
                TripStatus::Completed->value,
                TripStatus::Delivered->value,
            ]),
            'cancelled' => $query->where('trips.status', TripStatus::Cancelled->value),
            'km_report' => $query->where(function (Builder $q) {
                $q->where('trips.is_paused', true)
                    ->orWhereHas('kmReports', fn (Builder $kr) => $kr->where('status', TripKmReportStatus::Pending->value));
            }),
            'geo_out_of_range' => $query->whereHas('checkpoints', fn (Builder $cp) => $cp->where(function (Builder $q) {
                $q->where('vehicle_geo_status', GeoCheckStatus::OutOfRange->value)
                    ->orWhere('location_geo_status', GeoCheckStatus::OutOfRange->value);
            })),
            default => $query,
        };
    }

    public function updatedTripSearch(): void
    {
        $this->resetPage();
    }

    public function updatedDateFrom(): void
    {
        $this->resetPage();
    }

    public function updatedDateTo(): void
    {
        $this->resetPage();
    }
}
