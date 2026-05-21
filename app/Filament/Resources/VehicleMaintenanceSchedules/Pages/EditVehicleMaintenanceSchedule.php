<?php

namespace App\Filament\Resources\VehicleMaintenanceSchedules\Pages;

use App\Filament\Resources\VehicleMaintenanceSchedules\VehicleMaintenanceScheduleResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditVehicleMaintenanceSchedule extends EditRecord
{
    protected static string $resource = VehicleMaintenanceScheduleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
