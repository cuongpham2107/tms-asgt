<?php

namespace App\Filament\Resources\OrderPlans\Pages;

use App\Filament\Resources\OrderPlans\OrderPlanResource;
use App\Filament\Resources\Orders\Actions\CreateBulkOrdersAction;
use App\Filament\Resources\Orders\Actions\CreateOrderHHHKAction;
use App\Filament\Resources\Orders\Actions\CreateOrderHNAction;
use App\Models\OrderCategory;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListOrderPlans extends ListRecords
{
    protected static string $resource = OrderPlanResource::class;

    protected string $view = 'filament.resources.order-plans.pages.list-order-plans';

    public ?string $activeOrderTypeFilter = 'all';

    public ?string $activePlaceFilter = 'all';

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
        parent::mount();

        $this->orderPlaceFilters = OrderCategory::query()
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

    public function filterOrderType(string $type): void
    {
        $this->activeOrderTypeFilter = $type;

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

    public function getOrderTypeCount(string $type): int
    {
        return $this->baseCountQuery()
            ->when(
                $type !== 'all',
                fn (Builder $query): Builder => $query->where('type', $type),
            )
            ->count();
    }

    public function getOrderPlaceCount(string $place): int
    {
        return $this->baseCountQuery()
            ->when(
                $place !== 'all',
                fn (Builder $query): Builder => $query->whereHas(
                    'orderCategory',
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
                'driver',
                'orderCategory',
                'pickupLocation',
                'vehicle',
            ])
            ->where('status', 'draft')
            ->when(
                $this->activeOrderTypeFilter !== 'all',
                fn (Builder $query): Builder => $query->where('type', $this->activeOrderTypeFilter),
            )
            ->when(
                $this->activePlaceFilter !== 'all',
                fn (Builder $query): Builder => $query->whereHas(
                    'orderCategory',
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
            });
    }

    private function baseCountQuery(): Builder
    {
        return OrderPlanResource::getEloquentQuery()
            ->where('status', 'draft');
    }
}
