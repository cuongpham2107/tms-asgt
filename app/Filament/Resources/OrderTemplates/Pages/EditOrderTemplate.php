<?php

namespace App\Filament\Resources\OrderTemplates\Pages;

use App\Filament\Resources\OrderTemplates\OrderTemplateResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditOrderTemplate extends EditRecord
{
    protected static string $resource = OrderTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
