<?php

namespace App\Filament\Resources\OrderPlans\Pages;

use App\Filament\Forms\Components\OrderDateRangePicker;
use App\Filament\Forms\Components\PillFilter;
use App\Filament\Resources\OrderPlans\OrderPlanResource;
use App\Filament\Resources\Orders\Actions\CreateBulkOrdersAction;
use App\Filament\Resources\Orders\Actions\CreateOrderHHHKAction;
use App\Filament\Resources\Orders\Actions\CreateOrderHNAction;
use App\Models\Area;
use Carbon\Carbon;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;

class ListOrderPlans extends ListRecords
{
    protected static string $resource = OrderPlanResource::class;

    protected string $view = 'filament.resources.order-plans.pages.list-order-plans';

    #[Url]
    public ?string $startDate = null;

    #[Url]
    public ?string $endDate = null;

    public ?array $dateRange = null;

    #[Url]
    public ?string $activeOrderTypeFilter = 'all';

    #[Url]
    public ?string $activePlaceFilter = 'all';

    #[Url]
    public ?string $orderSearch = null;

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

    public function mount(): void
    {
        if (blank($this->startDate)) {
            $this->startDate = today()->toDateString();
        }

        if (blank($this->endDate)) {
            $this->endDate = today()->addDay()->toDateString();
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
            // Keep created orders as drafts when created from the Plan page
            CreateOrderHHHKAction::make(false),
            CreateOrderHNAction::make(false),
            CreateBulkOrdersAction::make(false),
        ];
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
                    ->placeholder('Tìm theo mã đơn, khách hàng, hàng hóa, biển số, lái xe...')
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

                PillFilter::make('activePlaceFilter')
                    // ->labelPrefix('Khu vực')
                    ->options(fn (): array => ['all' => 'Tất cả điểm'] + Area::query()
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

    public function filterOrderType(string $type): void
    {
        $this->activeOrderTypeFilter = $type;
        $this->activePlaceFilter = 'all';

        $this->resetPage();
    }

    public function filterPlace(string $place): void
    {
        $this->activePlaceFilter = $place;

        $this->resetPage();
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
     * @param  array<int, string>  $excludeFilters
     */
    private function crossFilteredQuery(array $excludeFilters = []): Builder
    {
        return OrderPlanResource::getEloquentQuery()
            ->where('status', 'draft')
            ->when(
                ! in_array('type', $excludeFilters) && $this->activeOrderTypeFilter !== 'all',
                fn (Builder $q): Builder => $q->where('type', $this->activeOrderTypeFilter),
            )
            ->when(
                ! in_array('place', $excludeFilters) && $this->activePlaceFilter !== 'all',
                fn (Builder $q): Builder => $q->whereHas('area', fn (Builder $aq): Builder => $aq->where('code', $this->activePlaceFilter)),
            )
            ->when(filled($this->startDate) || filled($this->endDate), function (Builder $q): Builder {
                if (filled($this->startDate) && filled($this->endDate)) {
                    $start = Carbon::parse($this->startDate)->hour(8);
                    $end = Carbon::parse($this->endDate)->hour(8);

                    return $q->where('created_at', '>=', $start)->where('created_at', '<', $end);
                }

                if (filled($this->startDate)) {
                    $start = Carbon::parse($this->startDate)->hour(8);

                    return $q->where('created_at', '>=', $start);
                }

                $end = Carbon::parse($this->endDate)->hour(8);

                return $q->where('created_at', '<', $end);
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
        return OrderPlanResource::getEloquentQuery()
            ->with([
                'customer',
                'deliveryPoints.location',
                'area',
                'pickupLocation',
                'trip.vehicle',
                'trip.driver',
            ])
            ->where('status', 'draft')
            ->when(
                $this->activeOrderTypeFilter !== 'all',
                fn (Builder $query): Builder => $query->where('type', $this->activeOrderTypeFilter),
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
                        ->orWhereHas('trip.vehicle', fn (Builder $vehicleQuery): Builder => $vehicleQuery->where('plate_number', 'like', "%{$search}%"))
                        ->orWhereHas('trip.driver', fn (Builder $driverQuery): Builder => $driverQuery->where('name', 'like', "%{$search}%"));
                });
            })
            ->when(filled($this->startDate) || filled($this->endDate), function (Builder $query): Builder {
                if (filled($this->startDate) && filled($this->endDate)) {
                    $start = Carbon::parse($this->startDate)->hour(8);
                    $end = Carbon::parse($this->endDate)->hour(8);

                    return $query->where('created_at', '>=', $start)->where('created_at', '<', $end);
                }

                if (filled($this->startDate)) {
                    $start = Carbon::parse($this->startDate)->hour(8);

                    return $query->where('created_at', '>=', $start);
                }

                $end = Carbon::parse($this->endDate)->hour(8);

                return $query->where('created_at', '<', $end);
            });
    }
}
