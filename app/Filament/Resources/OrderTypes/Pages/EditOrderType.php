<?php

namespace App\Filament\Resources\OrderTypes\Pages;

use App\Filament\Resources\OrderTypes\OrderTypeResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditOrderType extends EditRecord
{
    protected static string $resource = OrderTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
