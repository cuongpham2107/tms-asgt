<?php

namespace App\Filament\Resources\VehicleMaintenanceJobs\Pages;

use App\Filament\Resources\VehicleMaintenanceJobs\VehicleMaintenanceJobResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListVehicleMaintenanceJobs extends ListRecords
{
    protected static string $resource = VehicleMaintenanceJobResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
