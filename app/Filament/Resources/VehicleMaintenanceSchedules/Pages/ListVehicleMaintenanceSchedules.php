<?php

namespace App\Filament\Resources\VehicleMaintenanceSchedules\Pages;

use App\Filament\Resources\VehicleMaintenanceSchedules\VehicleMaintenanceScheduleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListVehicleMaintenanceSchedules extends ListRecords
{
    protected static string $resource = VehicleMaintenanceScheduleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
