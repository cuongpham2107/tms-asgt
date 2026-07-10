<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Enums\OrderStatus;
use App\Filament\Forms\Components\OrderDateRangePicker;
use App\Filament\Forms\Components\PillFilter;
use App\Filament\Resources\Orders\Actions\CreateBulkOrdersAction;
use App\Filament\Resources\Orders\Actions\CreateOrderHHHKAction;
use App\Filament\Resources\Orders\Actions\CreateOrderHNAction;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Area;
use App\Models\Order;
use Carbon\Carbon;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;

class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;

    protected string $view = 'filament.resources.orders.pages.list-orders';

    #[Url]
    public ?string $activeOrderTypeFilter = 'HHHK';

    #[Url]
    public ?string $activeStatusFilter = 'all';

    #[Url]
    public ?string $activePlaceFilter = 'NBA';

    #[Url]
    public ?string $orderSearch = null;

    #[Url]
    public bool $showMineOnly = true;

    #[Url]
    public ?string $startDate = null;

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

    #[Url]
    public ?string $endDate = null;

    public ?array $dateRange = null;

    /**
     * @var array<string, string>
     */
    public array $orderPlaceFilters = [];

    /**
     * @var array<string, array{label: string, color: string}>
     */
    public array $orderTypeFilters = [
        'HHHK' => [
            'label' => 'Hàng hóa hàng không',
            'color' => 'bg-[#008fd5]',
        ],
        'external' => [
            'label' => 'Hàng ngoài',
            'color' => 'bg-[#008fd5]',
        ],
        'all' => [
            'label' => 'Tất cả',
            'color' => 'bg-[#008fd5]',
        ],
    ];

    /**
     * @var array<string, array{label: string, color: string}>
     */
    public array $orderStatusFilters = [
        'all' => [
            'label' => 'Tất cả trạng thái',
            'color' => 'bg-gray-900',
        ],
        'draft' => [
            'label' => 'Nháp',
            'color' => 'bg-gray-400',
        ],
        'assigned' => [
            'label' => 'Đã gán xe / Đã gửi',
            'color' => 'bg-sky-500',
        ],
        'in_transit' => [
            'label' => 'Đang vận chuyển',
            'color' => 'bg-amber-500',
        ],
        'driver_swap' => [
            'label' => 'Đảo lái',
            'color' => 'bg-red-600',
        ],
        'completed' => [
            'label' => 'Hoàn thành',
            'color' => 'bg-emerald-500',
        ],
        'cancelled' => [
            'label' => 'Hủy',
            'color' => 'bg-red-500',
        ],
    ];

    public function mount(): void
    {
        $this->dateRange = [
            'start' => $this->startDate,
            'end' => $this->endDate,
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
            //  HHHK hoặc Hàng ngoài
            CreateOrderHHHKAction::make(),
            CreateOrderHNAction::make(),
            CreateBulkOrdersAction::make(),
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 4;
    }

    public function filterOrderType(string $type): void
    {
        $this->activeOrderTypeFilter = $type;
        $this->activePlaceFilter = 'all';

        $this->resetPage();
    }

    public function filterStatus(string $status): void
    {
        $this->activeStatusFilter = $status;

        $this->resetPage();
    }

    public function filterPlace(string $place): void
    {
        $this->activePlaceFilter = $place;

        $this->resetPage();
    }

    protected function getForms(): array
    {
        return [
            'searchForm',
            'dateRangeForm',
            'filtersForm',
        ];
    }

    public function searchForm(Schema $form): Schema
    {
        return $form
            ->components([
                TextInput::make('orderSearch')
                    ->hiddenLabel()
                    ->placeholder('Tìm mã đơn, khách hàng, hàng hóa, biển số, lái xe...')
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
                    ->syncWithProperties('startDate', 'endDate'),
            ]);
    }

    public function filtersForm(Schema $form): Schema
    {
        return $form
            ->components([
                PillFilter::make('activeOrderTypeFilter')
                    // ->labelPrefix('Loại đơn')
                    ->options($this->orderTypeFilters)
                    ->countCallback(fn ($key) => $this->getOrderTypeCount($key))
                    ->activeValue(fn ($livewire) => $livewire->activeOrderTypeFilter)
                    ->clickAction('filterOrderType'),

                PillFilter::make('activeStatusFilter')
                    // ->labelPrefix('Trạng thái')
                    ->options($this->orderStatusFilters)
                    ->countCallback(fn ($key) => $this->getOrderStatusCount($key))
                    ->activeValue(fn ($livewire) => $livewire->activeStatusFilter)
                    ->clickAction('filterStatus'),

                PillFilter::make('activePlaceFilter')
                    // ->labelPrefix('Khu vực')
                    ->options(fn (): array => Area::query()
                        ->when(
                            $this->activeOrderTypeFilter !== 'all',
                            fn ($query) => $query->where('type', $this->activeOrderTypeFilter)
                        )
                        ->orderBy('sort_order', 'asc')
                        ->pluck('code', 'code')
                        ->map(fn (string $code): string => $code === 'PROVINCE' ? 'Điểm khác' : $code)
                        ->toArray() + ['all' => 'Tất cả'])
                    ->countCallback(fn ($key) => $this->getOrderPlaceCount($key))
                    ->activeValue(fn ($livewire) => $livewire->activePlaceFilter)
                    ->clickAction('filterPlace'),
            ]);
    }

    public function updatedOrderSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStartDate(): void
    {
        $this->resetPage();
    }

    public function updatedEndDate(): void
    {
        $this->resetPage();
    }

    /**
     * Base query with cross-filters for a specific pill type.
     * Includes all active filters EXCEPT the one being counted.
     *
     * @param  array<int, string>  $excludeFilters  e.g. ['type'], ['status'], ['place']
     */
    private function crossFilteredQuery(array $excludeFilters = []): Builder
    {
        return Order::query()
            ->when(
                ! in_array('status', $excludeFilters) && $this->activeStatusFilter === 'all',
                fn (Builder $q): Builder => $q
                    ->where('status', '!=', OrderStatus::Completed->value),
            )
            ->when(
                ! in_array('type', $excludeFilters) && $this->activeOrderTypeFilter !== 'all',
                fn (Builder $q): Builder => $q->where('type', $this->activeOrderTypeFilter),
            )
            ->when(
                ! in_array('status', $excludeFilters) && $this->activeStatusFilter !== 'all',
                fn (Builder $q): Builder => $q->whereIn('status', $this->resolveStatusValues($this->activeStatusFilter)),
            )
            ->when(
                ! in_array('place', $excludeFilters) && $this->activePlaceFilter !== 'all',
                fn (Builder $q): Builder => $q->whereHas('area', fn (Builder $aq): Builder => $aq->where('code', $this->activePlaceFilter)),
            )
            ->when($this->showMineOnly, fn (Builder $q): Builder => $q->where('created_by', Auth::id()))
            ->when(filled($this->startDate) || filled($this->endDate), function (Builder $q): Builder {
                if (filled($this->startDate) && filled($this->endDate)) {
                    $start = Carbon::parse($this->startDate)->hour(8);
                    $end = Carbon::parse($this->endDate)->hour(8);

                    return $q->where(function (Builder $q) use ($start, $end): void {
                        $q->whereBetween('planned_loading_at', [$start, $end])
                            ->orWhereNull('planned_loading_at');
                    });
                }

                if (filled($this->startDate)) {
                    $start = Carbon::parse($this->startDate)->hour(8);

                    return $q->where(function (Builder $q) use ($start): void {
                        $q->where('planned_loading_at', '>=', $start)
                            ->orWhereNull('planned_loading_at');
                    });
                }

                $end = Carbon::parse($this->endDate)->hour(8);

                return $q->where(function (Builder $q) use ($end): void {
                    $q->where('planned_loading_at', '<', $end)
                        ->orWhereNull('planned_loading_at');
                });
            });
    }

    public function getOrderTypeCount(string $type): int
    {
        return $this->crossFilteredQuery(['type'])
            ->when(
                $type !== 'all',
                fn (Builder $query): Builder => $query->where('type', $type),
            )
            ->count();
    }

    public function getOrderStatusCount(string $status): int
    {
        return $this->crossFilteredQuery(['status'])
            ->when(
                $status !== 'all',
                fn (Builder $query): Builder => $query->whereIn('status', $this->resolveStatusValues($status)),
            )
            ->count();
    }

    public function getOrderPlaceCount(string $place): int
    {
        return $this->crossFilteredQuery(['place'])
            ->when(
                $place !== 'all',
                fn (Builder $query): Builder => $query->whereHas(
                    'area',
                    fn (Builder $categoryQuery): Builder => $categoryQuery->where('code', $place),
                ),
            )
            ->count();
    }

    protected function getTableQuery(): Builder
    {
        $statusOrder = [
            OrderStatus::Draft->value => 0,
            OrderStatus::Assigned->value => 1,
            OrderStatus::Sent->value => 2,
            OrderStatus::InTransit->value => 3,
            OrderStatus::DriverSwap->value => 4,
            OrderStatus::Completed->value => 5,
            OrderStatus::Cancelled->value => 6,
        ];

        $completedExcluded = true;

        $caseSql = 'CASE orders.status '
            .collect($statusOrder)->map(fn ($ord, $status) => "WHEN '{$status}' THEN {$ord}")->implode(' ')
            .' END';

        return OrderResource::getEloquentQuery()
            ->leftJoin('areas', 'orders.area_id', '=', 'areas.id')
            ->orderByRaw($caseSql)
            ->orderBy('orders.created_at', 'desc')
            ->select('orders.*')
            ->with([
                'customer',
                'deliveryPoints.location',
                'area',
                'pickupLocation',
                'trip.vehicle',
                'trip.driver',
            ])
            ->when($this->showMineOnly, fn (Builder $query): Builder => $query->where('created_by', Auth::id()))
            ->when(
                $this->activeOrderTypeFilter !== 'all',
                fn (Builder $query): Builder => $query->where('orders.type', $this->activeOrderTypeFilter),
            )
            ->when(
                $this->activeStatusFilter !== 'all',
                fn (Builder $query): Builder => $query->whereIn('status', $this->resolveStatusValues($this->activeStatusFilter)),
            )
            ->when(
                $this->activePlaceFilter !== 'all',
                fn (Builder $query): Builder => $query->whereHas(
                    'area',
                    fn (Builder $categoryQuery): Builder => $categoryQuery->where('code', $this->activePlaceFilter),
                ),
            )
            ->when(
                $this->activeStatusFilter === 'all',
                fn (Builder $query): Builder => $query
                    ->where('orders.status', '!=', OrderStatus::Completed->value),
            )
            ->when(filled($this->orderSearch), function (Builder $query): Builder {
                $search = trim((string) $this->orderSearch);

                return $query->where(function (Builder $query) use ($search): void {
                    $query
                        ->where('order_code', 'like', "%{$search}%")
                        ->orWhere('cargo_name', 'like', "%{$search}%")
                        ->orWhere('pickup_address', 'like', "%{$search}%")
                        ->orWhereHas('customer', fn (Builder $customerQuery): Builder => $customerQuery->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('trip.vehicle', fn (Builder $vehicleQuery): Builder => $vehicleQuery->where('plate_number', 'like', "%{$search}%"))
                        ->orWhereHas('trip.driver', fn (Builder $driverQuery): Builder => $driverQuery->where('name', 'like', "%{$search}%"));
                });
            })

            // Date range filter (by planned_loading_at). Supports start, end or both.
            ->when(filled($this->startDate) || filled($this->endDate), function (Builder $query): Builder {
                if (filled($this->startDate) && filled($this->endDate)) {
                    $start = Carbon::parse($this->startDate)->hour(8);
                    $end = Carbon::parse($this->endDate)->hour(8);

                    return $query->where(function (Builder $query) use ($start, $end): void {
                        $query->whereBetween('planned_loading_at', [$start, $end])
                            ->orWhereNull('planned_loading_at');
                    });
                }

                if (filled($this->startDate)) {
                    $start = Carbon::parse($this->startDate)->hour(8);

                    return $query->where(function (Builder $query) use ($start): void {
                        $query->where('planned_loading_at', '>=', $start)
                            ->orWhereNull('planned_loading_at');
                    });
                }

                $end = Carbon::parse($this->endDate)->hour(8);

                return $query->where(function (Builder $query) use ($end): void {
                    $query->where('planned_loading_at', '<', $end)
                        ->orWhereNull('planned_loading_at');
                });
            });
    }

    /**
     * @return array<int, string>
     */
    private function resolveStatusValues(string $status): array
    {
        return match ($status) {
            'draft' => [OrderStatus::Draft->value],
            'assigned' => [OrderStatus::Assigned->value, OrderStatus::Sent->value],
            'in_transit' => [OrderStatus::InTransit->value],
            'driver_swap' => [OrderStatus::DriverSwap->value],
            'completed' => [OrderStatus::Completed->value],
            'cancelled' => [OrderStatus::Cancelled->value],
            default => [],
        };
    }

    private function baseCountQuery(): Builder
    {
        return Order::query()
            ->when($this->showMineOnly, fn (Builder $query): Builder => $query->where('created_by', Auth::id()))
            ->when(filled($this->startDate) || filled($this->endDate), function (Builder $query): Builder {
                if (filled($this->startDate) && filled($this->endDate)) {
                    $start = Carbon::parse($this->startDate)->hour(8);
                    $end = Carbon::parse($this->endDate)->hour(8);

                    return $query->where(function (Builder $query) use ($start, $end): void {
                        $query->whereBetween('planned_loading_at', [$start, $end])
                            ->orWhereNull('planned_loading_at');
                    });
                }

                if (filled($this->startDate)) {
                    $start = Carbon::parse($this->startDate)->hour(8);

                    return $query->where(function (Builder $query) use ($start): void {
                        $query->where('planned_loading_at', '>=', $start)
                            ->orWhereNull('planned_loading_at');
                    });
                }

                $end = Carbon::parse($this->endDate)->hour(8);

                return $query->where(function (Builder $query) use ($end): void {
                    $query->where('planned_loading_at', '<', $end)
                        ->orWhereNull('planned_loading_at');
                });
            });
    }
}
