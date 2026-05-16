<?php

namespace App\Filament\Resources\DriverShifts\Pages;

use App\Filament\Resources\DriverShifts\DriverShiftResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDriverShifts extends ListRecords
{
    protected static string $resource = DriverShiftResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
