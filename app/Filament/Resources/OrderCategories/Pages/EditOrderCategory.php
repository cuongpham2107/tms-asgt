<?php

namespace App\Filament\Resources\OrderCategories\Pages;

use App\Filament\Resources\OrderCategories\OrderCategoryResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditOrderCategory extends EditRecord
{
    protected static string $resource = OrderCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
