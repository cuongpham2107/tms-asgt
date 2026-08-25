<?php

namespace App\Filament\Resources\Trips\Pages;

use App\Enums\OrderStatus;
use App\Enums\TripStatus;
use App\Filament\Forms\Components\OrderDateRangePicker;
use App\Filament\Forms\Components\PillFilter;
use App\Filament\Resources\Trips\Actions\CreateEmptyRunAction;
use App\Filament\Resources\Trips\TripResource;
use App\Models\Area;
use App\Models\Trip;
use Carbon\Carbon;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;

class ListTrips extends ListRecords
{
    protected static string $resource = TripResource::class;

    protected string $view = 'filament.resources.trips.pages.list-trips';

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
     * @var array<string, string>
     */
    public array $orderPlaceFilters = [];

    public array $orderTypeFilters = [

        'HHHK' => ['label' => 'HHHK', 'color' => 'bg-blue-500'],
        'external' => ['label' => 'Hàng ngoài', 'color' => 'bg-amber-500'],
        'all' => ['label' => 'Tất cả', 'color' => 'bg-blue-600'],
    ];

    public array $tripStatusFilters = [
        'all' => ['label' => 'Tất cả', 'color' => 'bg-blue-600'],
        'unsent' => ['label' => 'Chưa gửi', 'color' => 'bg-amber-500'],
        'pending' => ['label' => 'Chờ chạy', 'color' => 'bg-sky-500'],
        'running' => ['label' => 'Đang chạy', 'color' => 'bg-sky-500'],
        'started' => ['label' => 'Đã bắt đầu', 'color' => 'bg-blue-500'],
        'arrived_pickup' => ['label' => 'Đến lấy hàng', 'color' => 'bg-orange-500'],
        'delivering' => ['label' => 'Đang giao', 'color' => 'bg-sky-500'],
        'arrived_delivery' => ['label' => 'Đến giao hàng', 'color' => 'bg-amber-500'],
        'delivered' => ['label' => 'Đã giao', 'color' => 'bg-teal-500'],
        'completed' => ['label' => 'Hoàn thành', 'color' => 'bg-emerald-500'],
        'return_trip' => ['label' => 'Chuyến không hàng', 'color' => 'bg-violet-500'],
        'driver_swap' => ['label' => 'Đảo lái', 'color' => 'bg-red-600'],
        'delayed' => ['label' => 'Trễ giờ', 'color' => 'bg-rose-500'],
        'cancelled' => ['label' => 'Đã huỷ', 'color' => 'bg-red-500'],
    ];

    public array $vehicleOwnerFilters = [
        'all' => ['label' => 'Tất cả', 'color' => 'bg-blue-600'],
        'company' => ['label' => 'Xe công ty', 'color' => 'bg-blue-500'],
        'rent' => ['label' => 'Xe thuê ngoài', 'color' => 'bg-amber-500'],
    ];

    public function mount(): void
    {
        $this->dateRange = [
            'start' => $this->dateFrom,
            'end' => $this->dateTo,
        ];

        parent::mount();

        $this->orderPlaceFilters = Area::query()
            ->orderBy('sort_order', 'asc')
            ->pluck('code', 'code')
            ->map(fn (string $code): string => $code === 'PROVINCE' ? 'Điểm khác' : $code)
            ->toArray();
    }

    protected function getHeaderActions(): array
    {
        return [
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
     * @return array<int, array{key: string, label: string, value: int, icon: string, color: string, bg: string, border: string, filter: ?string}>
     */
    public function getTripStats(): array
    {
        $baseQuery = $this->applyActiveFilters(Trip::query(), except: 'status');

        $total = (clone $baseQuery)->count();

        $unsent = (clone $baseQuery)
            ->where('status', TripStatus::Pending->value)
            ->whereHas('orders', fn (Builder $q) => $q->whereIn('status', [OrderStatus::Assigned->value, OrderStatus::Draft->value]))
            ->count();

        $runningStatuses = [
            TripStatus::Started->value,
            TripStatus::ArrivedPickup->value,
            TripStatus::Delivering->value,
            TripStatus::ArrivedDelivery->value,
            TripStatus::Delivered->value,
            TripStatus::DriverSwap->value,
            TripStatus::ReturnTrip->value,
        ];

        $running = (clone $baseQuery)
            ->whereIn('status', $runningStatuses)
            ->count();

        $completed = (clone $baseQuery)
            ->where('status', TripStatus::Completed->value)
            ->count();

        $delayed = (clone $baseQuery)
            ->whereIn('status', [
                TripStatus::Started->value,
                TripStatus::ArrivedPickup->value,
                TripStatus::Delivering->value,
                TripStatus::ArrivedDelivery->value,
            ])
            ->whereHas('orders', fn (Builder $q) => $q
                ->where('planned_loading_at', '<', now())
            )->count();

        return [
            [
                'key' => 'all',
                'label' => 'Tổng chuyến',
                'value' => $total,
                'icon' => 'heroicon-o-truck',
                'color' => 'text-blue-600 dark:text-blue-400',
                'bg' => 'bg-blue-50 dark:bg-blue-950/40',
                'border' => 'border-blue-100 dark:border-blue-900/40',
                'filter' => 'all',
            ],
            [
                'key' => 'unsent',
                'label' => 'Chưa gửi',
                'value' => $unsent,
                'icon' => 'heroicon-o-paper-airplane',
                'color' => 'text-amber-600 dark:text-amber-400',
                'bg' => 'bg-amber-50 dark:bg-amber-950/40',
                'border' => 'border-amber-100 dark:border-amber-900/40',
                'filter' => 'unsent',
            ],
            [
                'key' => 'running',
                'label' => 'Đang chạy',
                'value' => $running,
                'icon' => 'heroicon-o-play-circle',
                'color' => 'text-sky-600 dark:text-sky-400',
                'bg' => 'bg-sky-50 dark:bg-sky-950/40',
                'border' => 'border-sky-100 dark:border-sky-900/40',
                'filter' => 'running',
            ],
            [
                'key' => 'completed',
                'label' => 'Hoàn thành',
                'value' => $completed,
                'icon' => 'heroicon-o-check-circle',
                'color' => 'text-emerald-600 dark:text-emerald-400',
                'bg' => 'bg-emerald-50 dark:bg-emerald-950/40',
                'border' => 'border-emerald-100 dark:border-emerald-900/40',
                'filter' => 'completed',
            ],
            [
                'key' => 'delayed',
                'label' => 'Trễ giờ',
                'value' => $delayed,
                'icon' => 'heroicon-o-exclamation-triangle',
                'color' => 'text-rose-600 dark:text-rose-400',
                'bg' => 'bg-rose-50 dark:bg-rose-950/40',
                'border' => 'border-rose-100 dark:border-rose-900/40',
                'filter' => 'delayed',
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

    public function getOrderTypeCount(string $key): int
    {
        return $this->applyActiveFilters(Trip::query(), except: 'orderType')
            ->when(
                $key !== 'all',
                fn (Builder $query): Builder => $query->whereHas(
                    'orders',
                    fn (Builder $q) => $q->where('type', $key),
                ),
            )
            ->count();
    }

    public function getTripStatusCount(string $key): int
    {
        return $this->applyActiveFilters(Trip::query(), except: 'status')
            ->when(
                $key !== 'all',
                fn (Builder $query): Builder => $this->applyStatusFilterByKey($query, $key),
                fn (Builder $query): Builder => $query->whereNotIn('status', [TripStatus::Completed->value, TripStatus::Cancelled->value]),
            )
            ->count();
    }

    public function getVehicleOwnerCount(string $key): int
    {
        return $this->applyActiveFilters(Trip::query(), except: 'vehicleOwner')
            ->when(
                $key !== 'all',
                fn (Builder $query): Builder => $query->whereHas('vehicle', fn (Builder $q) => $q->where('type', $key)),
            )
            ->count();
    }

    public function getOrderPlaceCount(string $place): int
    {
        return $this->applyActiveFilters(Trip::query(), except: 'place')
            ->when(
                $place !== 'all',
                fn (Builder $query): Builder => $query->whereHas(
                    'orders.area',
                    fn (Builder $aq) => $aq->where('code', $place),
                ),
            )
            ->count();
    }

    private function applyActiveFilters(Builder $query, string $except = ''): Builder
    {
        return $query
            ->when(filled($this->dateFrom) || filled($this->dateTo), function (Builder $query): Builder {
                if (filled($this->dateFrom) && filled($this->dateTo)) {
                    return $query->whereBetween('started_at', [
                        Carbon::parse($this->dateFrom)->startOfDay(),
                        Carbon::parse($this->dateTo)->endOfDay(),
                    ]);
                }

                if (filled($this->dateFrom)) {
                    return $query->where('started_at', '>=', Carbon::parse($this->dateFrom)->startOfDay());
                }

                return $query->where('started_at', '<=', Carbon::parse($this->dateTo)->endOfDay());
            })
            ->when($except !== 'status' && $this->activeStatusFilter !== 'all', fn (Builder $query): Builder => $this->applyStatusFilterByKey($query, $this->activeStatusFilter))
            ->when($except !== 'status' && $this->activeStatusFilter === 'all', fn (Builder $query): Builder => $query->whereNotIn('status', [TripStatus::Completed->value, TripStatus::Cancelled->value]))
            ->when($except !== 'vehicleOwner' && $this->vehicleOwner !== 'all', fn (Builder $query): Builder => $query->whereHas('vehicle', fn (Builder $q) => $q->where('type', $this->vehicleOwner)))
            ->when($except !== 'orderType' && $this->orderType !== 'all', fn (Builder $query): Builder => $query->whereHas('orders', fn (Builder $q) => $q->where('type', $this->orderType)))
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
                    ->options(fn (): array => Area::query()
                        ->where('is_active', true)
                        ->orderBy('sort_order', 'asc')
                        ->pluck('code', 'code')
                        ->map(fn (string $code): string => $code === 'PROVINCE' ? 'Điểm khác' : $code)
                        ->toArray() + ['all' => 'Tất cả'])
                    ->countCallback(fn ($key) => $this->getOrderPlaceCount($key))
                    ->activeValue(fn ($livewire) => $livewire->activePlaceFilter)
                    ->clickAction('filterPlace'),

            ]);
    }

    public function exportExcel(): void
    {
        // TODO: Implement Excel export for trips
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
                'orders.tripCheckpoints.deliveryPoint.location',
            ])
            ->when(
                $this->activeStatusFilter !== 'all',
                fn (Builder $query): Builder => $this->applyStatusFilterByKey($query, $this->activeStatusFilter),
                fn (Builder $query): Builder => $query->whereNotIn('trips.status', [TripStatus::Completed->value, TripStatus::Cancelled->value]),
            )
            ->when($this->vehicleOwner !== 'all', fn (Builder $query): Builder => $query->whereHas('vehicle', fn (Builder $q) => $q->where('type', $this->vehicleOwner)))
            ->when($this->orderType !== 'all', fn (Builder $query): Builder => $query->whereHas('orders', fn (Builder $q) => $q->where('type', $this->orderType)))
            ->when(
                $this->activePlaceFilter !== 'all',
                fn (Builder $query): Builder => $query->whereHas('orders.area', fn (Builder $aq) => $aq->where('code', $this->activePlaceFilter)),
            )
            ->when(filled($this->dateFrom) || filled($this->dateTo), function (Builder $query): Builder {
                if (filled($this->dateFrom) && filled($this->dateTo)) {
                    return $query->whereBetween('started_at', [
                        Carbon::parse($this->dateFrom)->startOfDay(),
                        Carbon::parse($this->dateTo)->endOfDay(),
                    ]);
                }

                if (filled($this->dateFrom)) {
                    return $query->where('started_at', '>=', Carbon::parse($this->dateFrom)->startOfDay());
                }

                return $query->where('started_at', '<=', Carbon::parse($this->dateTo)->endOfDay());
            })
            ->when(filled($this->tripSearch), function (Builder $query): Builder {
                $search = trim((string) $this->tripSearch);

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
            });
    }

    private function applyStatusFilterByKey(Builder $query, string $key): Builder
    {
        return match ($key) {
            'unsent' => $query->where('status', TripStatus::Pending->value)
                ->whereHas('orders', fn (Builder $q) => $q->whereIn('status', [OrderStatus::Assigned->value, OrderStatus::Draft->value])),
            'pending' => $query->where('status', TripStatus::Pending->value)
                ->where(fn (Builder $q) => $q
                    ->where('is_empty_run', true)
                    ->orWhereDoesntHave('orders', fn (Builder $sq) => $sq->whereIn('status', [OrderStatus::Assigned->value, OrderStatus::Draft->value]))
                ),
            'running' => $query->whereIn('status', [
                TripStatus::Started->value,
                TripStatus::ArrivedPickup->value,
                TripStatus::Delivering->value,
                TripStatus::ArrivedDelivery->value,
                TripStatus::Delivered->value,
                TripStatus::DriverSwap->value,
                TripStatus::ReturnTrip->value,
            ]),
            'started' => $query->where('status', TripStatus::Started->value),
            'arrived_pickup' => $query->where('status', TripStatus::ArrivedPickup->value),
            'delivering' => $query->where('status', TripStatus::Delivering->value),
            'arrived_delivery' => $query->where('status', TripStatus::ArrivedDelivery->value),
            'delivered' => $query->where('status', TripStatus::Delivered->value),
            'driver_swap' => $query->where('status', TripStatus::DriverSwap->value),
            'return_trip' => $query->where('status', TripStatus::ReturnTrip->value),
            'completed' => $query->where('status', TripStatus::Completed->value),
            'cancelled' => $query->where('status', TripStatus::Cancelled->value),
            'delayed' => $query->whereIn('status', [
                TripStatus::Started->value,
                TripStatus::ArrivedPickup->value,
                TripStatus::Delivering->value,
                TripStatus::ArrivedDelivery->value,
            ])->whereHas('orders', fn (Builder $q) => $q->where('planned_loading_at', '<', now())),
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
