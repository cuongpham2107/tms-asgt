<?php

namespace App\Filament\Resources\OrderTypes\Pages;

use App\Filament\Resources\OrderTypes\OrderTypeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListOrderTypes extends ListRecords
{
    protected static string $resource = OrderTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
