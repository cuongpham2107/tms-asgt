<?php

namespace App\Filament\Resources\Trips\Pages;

use App\Filament\Resources\Orders\Actions\CreateOrderHHHKAction;
use App\Filament\Resources\Orders\Actions\CreateOrderHNAction;
use App\Filament\Resources\Trips\TripResource;
use App\Filament\Resources\Trips\Widgets\TripStatsOverviewWidget;
use Carbon\Carbon;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListTrips extends ListRecords
{
    protected static string $resource = TripResource::class;

    protected string $view = 'filament.resources.trips.pages.list-trips';

    public ?string $dateFrom = null;

    public ?string $dateTo = null;

    public ?string $tripSearch = null;

    public function mount(): void
    {
        parent::mount();

        $this->dateFrom = Carbon::today()->startOfWeek()->format('Y-m-d');
        $this->dateTo = Carbon::today()->format('Y-m-d');
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

    protected function getTableQuery(): Builder
    {
        return TripResource::getEloquentQuery()
            ->with([
                'customer',
                'deliveryPoints.location',
                'driver.driverShifts',
                'orderCategory',
                'pickupLocation',
                'tripCheckpoints.deliveryPoint.location',
                'vehicle',
                'createdBy',
            ])
            ->when(filled($this->dateFrom), fn (Builder $query): Builder => $query->whereDate('planned_loading_at', '>=', $this->dateFrom))
            ->when(filled($this->dateTo), fn (Builder $query): Builder => $query->whereDate('planned_loading_at', '<=', $this->dateTo))
            ->when(filled($this->tripSearch), function (Builder $query): Builder {
                $search = trim((string) $this->tripSearch);

                return $query->where(function (Builder $query) use ($search): void {
                    $query
                        ->where('order_code', 'like', "%{$search}%")
                        ->orWhere('cargo_name', 'like', "%{$search}%")
                        ->orWhereHas('vehicle', fn (Builder $q): Builder => $q->where('plate_number', 'like', "%{$search}%"))
                        ->orWhereHas('driver', fn (Builder $q): Builder => $q->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('orderCategory', fn (Builder $q): Builder => $q->where('code', 'like', "%{$search}%"))
                        ->orWhereHas('customer', fn (Builder $q): Builder => $q->where('name', 'like', "%{$search}%"));
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
