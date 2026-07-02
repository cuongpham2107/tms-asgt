<?php

namespace App\Filament\Resources\Trips\Pages;

use App\Enums\TripStatus;
use App\Filament\Forms\Components\OrderDateRangePicker;
use App\Filament\Forms\Components\PillFilter;
use App\Filament\Resources\Trips\TripResource;
use App\Filament\Resources\Trips\Widgets\TripStatsOverviewWidget;
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

    public ?string $dateTo = null;

    public ?array $dateRange = null;

    #[Url]
    public ?string $tripSearch = null;

    #[Url]
    public ?string $activeStatusFilter = 'all';

    public array $tripStatusFilters = [
        'all' => ['label' => 'Tất cả', 'color' => 'bg-gray-900'],
        'pending' => ['label' => 'Chờ chạy', 'color' => 'bg-gray-500'],
        'started' => ['label' => 'Đã bắt đầu', 'color' => 'bg-blue-500'],
        'arrived_pickup' => ['label' => 'Đến lấy hàng', 'color' => 'bg-orange-500'],
        'delivering' => ['label' => 'Đang giao', 'color' => 'bg-sky-500'],
        'arrived_delivery' => ['label' => 'Đến giao hàng', 'color' => 'bg-amber-500'],
        'delivered' => ['label' => 'Đã giao', 'color' => 'bg-teal-500'],
        'driver_swap' => ['label' => 'Đảo lái', 'color' => 'bg-red-600'],
        'completed' => ['label' => 'Hoàn thành', 'color' => 'bg-emerald-500'],
    ];

    public function mount(): void
    {
        if (blank($this->dateFrom)) {
            $this->dateFrom = Carbon::today()->format('Y-m-d');
        }

        if (blank($this->dateTo)) {
            $this->dateTo = Carbon::today()->addDay()->format('Y-m-d');
        }

        $this->dateRange = [
            'start' => $this->dateFrom,
            'end' => $this->dateTo,
        ];

        parent::mount();
    }

    protected function getHeaderActions(): array
    {
        return [
            // CreateOrderHHHKAction::make(),
            // CreateOrderHNAction::make(),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            TripStatsOverviewWidget::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 5;
    }

    public function filterStatus(string $status): void
    {
        $this->activeStatusFilter = $status;

        $this->resetPage();
    }

    public function getTripStatusCount(string $key): int
    {
        return $this->baseCountQuery()
            ->when(
                $key !== 'all',
                fn (Builder $query): Builder => $this->applyStatusFilterByKey($query, $key),
            )
            ->count();
    }

    private function baseCountQuery(): Builder
    {
        return Trip::query()
            ->when(filled($this->dateFrom), fn (Builder $query): Builder => $query->where('started_at', '>=', Carbon::parse($this->dateFrom)->hour(8)))
            ->when(filled($this->dateTo), fn (Builder $query): Builder => $query->where('started_at', '<', Carbon::parse($this->dateTo)->hour(8)));
    }

    public function filtersForm(Schema $form): Schema
    {
        return $form
            ->components([
                PillFilter::make('activeStatusFilter')
                    ->options($this->tripStatusFilters)
                    ->countCallback(fn ($key) => $this->getTripStatusCount($key))
                    ->activeValue(fn ($livewire) => $livewire->activeStatusFilter)
                    ->clickAction('filterStatus'),
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
        return TripResource::getEloquentQuery()
            ->with([
                'vehicle',
                'driver',
                'orders.customer',
                'orders.area',
                'orders.pickupLocation',
                'orders.deliveryPoints.location',
                'orders.tripCheckpoints.deliveryPoint.location',
            ])
            ->when($this->activeStatusFilter !== 'all', fn (Builder $query): Builder => $this->applyStatusFilterByKey($query, $this->activeStatusFilter))
            ->when(filled($this->dateFrom), fn (Builder $query): Builder => $query->where('started_at', '>=', Carbon::parse($this->dateFrom)->hour(8)))
            ->when(filled($this->dateTo), fn (Builder $query): Builder => $query->where('started_at', '<', Carbon::parse($this->dateTo)->hour(8)))
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
            'pending' => $query->where('status', TripStatus::Pending->value),
            'started' => $query->where('status', TripStatus::Started->value),
            'arrived_pickup' => $query->where('status', TripStatus::ArrivedPickup->value),
            'delivering' => $query->where('status', TripStatus::Delivering->value),
            'arrived_delivery' => $query->where('status', TripStatus::ArrivedDelivery->value),
            'delivered' => $query->where('status', TripStatus::Delivered->value),
            'driver_swap' => $query->where('status', TripStatus::DriverSwap->value),
            'completed' => $query->where('status', TripStatus::Completed->value),
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
