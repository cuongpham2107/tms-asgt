<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Enums\OrderStatus;
use App\Filament\Forms\Components\OrderDateRangePicker;
use App\Filament\Forms\Components\PillFilter;
use App\Filament\Resources\Orders\Actions\CreateBulkOrdersAction;
use App\Filament\Resources\Orders\Actions\CreateOrderHHHKAction;
use App\Filament\Resources\Orders\Actions\CreateOrderHNAction;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Orders\Widgets\CompletedOrdersWidget;
use App\Filament\Resources\Orders\Widgets\PendingOrdersWidget;
use App\Filament\Resources\Orders\Widgets\TotalOrdersWidget;
use App\Filament\Resources\Orders\Widgets\TransportingOrdersWidget;
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
    public ?string $activeOrderTypeFilter = 'all';

    #[Url]
    public ?string $activeStatusFilter = 'all';

    #[Url]
    public ?string $activePlaceFilter = 'all';

    #[Url]
    public ?string $orderSearch = null;

    #[Url]
    public bool $showMineOnly = true;

    #[Url(keep: true)]
    public ?string $startDate = null;

    #[Url(keep: true)]
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
        'all' => [
            'label' => 'Tất cả',
            'color' => 'bg-[#008fd5]',
        ],
        'HHHK' => [
            'label' => 'Hàng hóa hàng không',
            'color' => 'bg-[#008fd5]',
        ],
        'external' => [
            'label' => 'Hàng ngoài',
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
        'planned' => [
            'label' => 'Kế hoạch',
            'color' => 'bg-blue-600',
        ],
        'unsent' => [
            'label' => 'Chưa gửi',
            'color' => 'bg-orange-400',
        ],
        'sent' => [
            'label' => 'Đã gửi',
            'color' => 'bg-sky-500',
        ],
        'started' => [
            'label' => 'Bắt đầu',
            'color' => 'bg-violet-500',
        ],
        'arrived_pickup' => [
            'label' => 'Đến lấy hàng',
            'color' => 'bg-fuchsia-500',
        ],
        'running' => [
            'label' => 'Đang giao hàng',
            'color' => 'bg-blue-500',
        ],
        'arrived_delivery' => [
            'label' => 'Đến giao hàng',
            'color' => 'bg-orange-500',
        ],
        'delivered' => [
            'label' => 'Đã giao hàng',
            'color' => 'bg-lime-500',
        ],
        'completed' => [
            'label' => 'Hoàn thành',
            'color' => 'bg-emerald-500',
        ],
        'driver_swap' => [
            'label' => 'Đảo lái',
            'color' => 'bg-purple-500',
        ],
        'cancelled' => [
            'label' => 'Hủy',
            'color' => 'bg-red-500',
        ],
        'trashed' => [
            'label' => 'Thùng rác',
            'color' => 'bg-slate-500',
        ],
    ];

    public function mount(): void
    {
        if (blank($this->startDate)) {
            $this->startDate = today()->toDateString();
        }

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

    // protected function getHeaderWidgets(): array
    // {
    //     return [
    //         TotalOrdersWidget::class,
    //         PendingOrdersWidget::class,
    //         TransportingOrdersWidget::class,
    //         CompletedOrdersWidget::class,
    //     ];
    // }

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
                    ->options(fn (): array => ['all' => 'Tất cả'] + Area::query()
                        ->when(
                            $this->activeOrderTypeFilter !== 'all',
                            fn ($query) => $query->where('type', $this->activeOrderTypeFilter)
                        )
                        ->orderBy('sort_order', 'asc')
                        ->pluck('code', 'code')
                        ->map(fn (string $code): string => $code === 'PROVINCE' ? 'Điểm khác' : $code)
                        ->toArray())
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

    public function getOrderTypeCount(string $type): int
    {
        return $this->baseCountQuery()
            ->when(
                $type !== 'all',
                fn (Builder $query): Builder => $query->where('type', $type),
            )
            ->count();
    }

    public function getOrderStatusCount(string $status): int
    {
        return Order::query()
            ->when(
                $status === 'planned',
                fn (Builder $query): Builder => $query->where('status', OrderStatus::Draft->value)->where('planned_loading_at', '>', now()),
            )
            ->when(
                $status !== 'planned',
                fn (Builder $query): Builder => $query->where('status', '!=', OrderStatus::Draft->value)
                    ->when(
                        $status !== 'all',
                        fn (Builder $query): Builder => $query->whereIn('status', $this->resolveStatusValues($status)),
                    ),
            )
            ->when($this->showMineOnly, fn (Builder $query): Builder => $query->where('created_by', Auth::id()))
            ->when(filled($this->startDate) || filled($this->endDate), function (Builder $query): Builder {
                if (filled($this->startDate) && filled($this->endDate)) {
                    $start = Carbon::parse($this->startDate)->startOfDay();
                    $end = Carbon::parse($this->endDate)->endOfDay();

                    return $query->whereBetween('created_at', [$start, $end]);
                }

                if (filled($this->startDate)) {
                    $start = Carbon::parse($this->startDate)->startOfDay();

                    return $query->where('created_at', '>=', $start);
                }

                $end = Carbon::parse($this->endDate)->endOfDay();

                return $query->where('created_at', '<=', $end);
            })
            ->count();
    }

    public function getOrderPlaceCount(string $place): int
    {
        return $this->baseCountQuery()
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
        return OrderResource::getEloquentQuery()
            ->with([
                'customer',
                'deliveryPoints.location',
                'driver',
                'area',
                'pickupLocation',
                'vehicle',
            ])
            ->when(
                $this->activeStatusFilter === 'planned',
                fn (Builder $query): Builder => $query->where('status', OrderStatus::Draft->value),
                fn (Builder $query): Builder => $query->where('status', '!=', OrderStatus::Draft->value),
            )
            ->when($this->showMineOnly, fn (Builder $query): Builder => $query->where('created_by', Auth::id()))
            ->when(
                $this->activeOrderTypeFilter !== 'all',
                fn (Builder $query): Builder => $query->where('type', $this->activeOrderTypeFilter),
            )
            ->when(
                $this->activeStatusFilter === 'planned',
                fn (Builder $query): Builder => $query->where('planned_loading_at', '>', now()),
            )
            ->when(
                $this->activeStatusFilter !== 'all' && $this->activeStatusFilter !== 'planned',
                fn (Builder $query): Builder => $query->whereIn('status', $this->resolveStatusValues($this->activeStatusFilter)),
            )
            ->when(
                $this->activePlaceFilter !== 'all',
                fn (Builder $query): Builder => $query->whereHas(
                    'area',
                    fn (Builder $categoryQuery): Builder => $categoryQuery->where('code', $this->activePlaceFilter),
                ),
            )
            ->when(filled($this->orderSearch), function (Builder $query): Builder {
                $search = trim((string) $this->orderSearch);

                return $query->where(function (Builder $query) use ($search): void {
                    $query
                        ->where('order_code', 'like', "%{$search}%")
                        ->orWhere('cargo_name', 'like', "%{$search}%")
                        ->orWhere('pickup_address', 'like', "%{$search}%")
                        ->orWhereHas('customer', fn (Builder $customerQuery): Builder => $customerQuery->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('vehicle', fn (Builder $vehicleQuery): Builder => $vehicleQuery->where('plate_number', 'like', "%{$search}%"))
                        ->orWhereHas('driver', fn (Builder $driverQuery): Builder => $driverQuery->where('name', 'like', "%{$search}%"));
                });
            })

            // Date range filter (by created_at). Supports start, end or both.
            ->when(filled($this->startDate) || filled($this->endDate), function (Builder $query): Builder {
                if (filled($this->startDate) && filled($this->endDate)) {
                    $start = Carbon::parse($this->startDate)->startOfDay();
                    $end = Carbon::parse($this->endDate)->endOfDay();

                    return $query->whereBetween('created_at', [$start, $end]);
                }

                if (filled($this->startDate)) {
                    $start = Carbon::parse($this->startDate)->startOfDay();

                    return $query->where('created_at', '>=', $start);
                }

                $end = Carbon::parse($this->endDate)->endOfDay();

                return $query->where('created_at', '<=', $end);
            });
    }

    /**
     * @return array<int, string>
     */
    private function resolveStatusValues(string $status): array
    {
        return match ($status) {
            'draft' => [OrderStatus::Draft->value],
            'unsent' => [OrderStatus::Assigned->value],
            'sent' => [OrderStatus::Sent->value],
            'started' => [OrderStatus::Started->value],
            'arrived_pickup' => [OrderStatus::ArrivedPickup->value],
            'running' => [
                OrderStatus::Delivering->value,
                OrderStatus::ArrivedDelivery->value,
                OrderStatus::Delivered->value,
                OrderStatus::DriverSwap->value,
            ],
            'arrived_delivery' => [OrderStatus::ArrivedDelivery->value],
            'delivered' => [OrderStatus::Delivered->value],
            'completed' => [OrderStatus::Completed->value],
            'driver_swap' => [OrderStatus::DriverSwap->value],
            'cancelled' => [OrderStatus::Cancelled->value],
            'trashed' => [OrderStatus::Trashed->value],
            default => [],
        };
    }

    private function baseCountQuery(): Builder
    {
        return Order::query()
            ->when(
                $this->activeStatusFilter === 'planned',
                fn (Builder $query): Builder => $query->where('status', OrderStatus::Draft->value),
                fn (Builder $query): Builder => $query->where('status', '!=', OrderStatus::Draft->value),
            )
            ->when($this->showMineOnly, fn (Builder $query): Builder => $query->where('created_by', Auth::id()))
            ->when(filled($this->startDate) || filled($this->endDate), function (Builder $query): Builder {
                if (filled($this->startDate) && filled($this->endDate)) {
                    $start = Carbon::parse($this->startDate)->startOfDay();
                    $end = Carbon::parse($this->endDate)->endOfDay();

                    return $query->whereBetween('created_at', [$start, $end]);
                }

                if (filled($this->startDate)) {
                    $start = Carbon::parse($this->startDate)->startOfDay();

                    return $query->where('created_at', '>=', $start);
                }

                $end = Carbon::parse($this->endDate)->endOfDay();

                return $query->where('created_at', '<=', $end);
            });
    }
}
