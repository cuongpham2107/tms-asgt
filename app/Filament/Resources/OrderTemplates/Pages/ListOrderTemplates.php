<?php

namespace App\Filament\Resources\OrderTemplates\Pages;

use App\Filament\Resources\OrderTemplates\OrderTemplateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListOrderTemplates extends ListRecords
{
    protected static string $resource = OrderTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
