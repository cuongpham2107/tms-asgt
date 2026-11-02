<?php

namespace App\Filament\Resources\Vehicles\Pages;

use App\Filament\Resources\Vehicles\VehicleResource;
use App\Models\Vehicle;
use App\Services\Notification\DriverNotificationService;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditVehicle extends EditRecord
{
    protected static string $resource = VehicleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }

    protected function afterSave(): void
    {
        /** @var Vehicle $vehicle */
        $vehicle = $this->record;

        if (! $vehicle->wasChanged('current_mileage')) {
            return;
        }

        $activeTrip = $vehicle->active_trip;

        if ($activeTrip === null) {
            return;
        }

        try {
            app(DriverNotificationService::class)->sendTripKmUpdated(
                $activeTrip,
                $vehicle->current_mileage !== null ? (float) $vehicle->current_mileage : null,
            );
        } catch (\Throwable) {
        }
    }
}
