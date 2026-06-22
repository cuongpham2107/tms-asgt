<?php

namespace App\Filament\Resources\Trips\Pages;

use App\Filament\Forms\Components\OrderDateRangePicker;
use App\Filament\Resources\Orders\Actions\CreateOrderHHHKAction;
use App\Filament\Resources\Orders\Actions\CreateOrderHNAction;
use App\Filament\Resources\Trips\TripResource;
use App\Filament\Resources\Trips\Widgets\TripStatsOverviewWidget;
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

    public function mount(): void
    {
        if (blank($this->dateFrom)) {
            $this->dateFrom = Carbon::today()->format('Y-m-d');
        }

        if (blank($this->dateTo)) {
            $this->dateTo = Carbon::today()->format('Y-m-d');
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

    public function exportExcel(): void
    {
        // TODO: Implement Excel export for trips
    }

    protected function getForms(): array
    {
        return [
            'searchForm',
            'dateRangeForm',
        ];
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
                'orders.customer',
                'orders.area',
                'orders.pickupLocation',
                'orders.deliveryPoints.location',
                'orders.driver',
                'orders.tripCheckpoints.deliveryPoint.location',
                'orders.driverSwaps.toDriver',
                'orders.shift',
            ])
            ->when(filled($this->dateFrom), fn (Builder $query): Builder => $query->whereDate('created_at', '>=', $this->dateFrom))
            ->when(filled($this->dateTo), fn (Builder $query): Builder => $query->whereDate('created_at', '<=', $this->dateTo))
            ->when(filled($this->tripSearch), function (Builder $query): Builder {
                $search = trim((string) $this->tripSearch);

                return $query->where(function (Builder $query) use ($search): void {
                    $query
                        ->whereHas('vehicle', fn (Builder $q) => $q->where('plate_number', 'like', "%{$search}%"))
                        ->orWhereHas('orders', fn (Builder $q) => $q
                            ->where('order_code', 'like', "%{$search}%")
                            ->orWhere('cargo_name', 'like', "%{$search}%")
                            ->orWhereHas('driver', fn (Builder $qd) => $qd->where('name', 'like', "%{$search}%"))
                            ->orWhereHas('area', fn (Builder $qa) => $qa->where('code', 'like', "%{$search}%"))
                            ->orWhereHas('customer', fn (Builder $qc) => $qc->where('name', 'like', "%{$search}%"))
                        );
                });
            });
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
