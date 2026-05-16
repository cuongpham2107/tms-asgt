<?php

namespace App\Filament\Resources\DriverShifts\Pages;

use App\Filament\Resources\DriverShifts\DriverShiftResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditDriverShift extends EditRecord
{
    protected static string $resource = DriverShiftResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
