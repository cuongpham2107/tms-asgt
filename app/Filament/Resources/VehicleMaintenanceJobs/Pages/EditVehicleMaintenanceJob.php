<?php

namespace App\Filament\Resources\VehicleMaintenanceJobs\Pages;

use App\Filament\Resources\VehicleMaintenanceJobs\VehicleMaintenanceJobResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditVehicleMaintenanceJob extends EditRecord
{
    protected static string $resource = VehicleMaintenanceJobResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
