<?php

namespace App\Filament\Resources\OrderCategories\Pages;

use App\Filament\Resources\OrderCategories\OrderCategoryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListOrderCategories extends ListRecords
{
    protected static string $resource = OrderCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Thêm loại đơn hàng')
                ->modalHeading('Tạo loại đơn hàng mới')
                ->modalButton('Tạo'),
        ];
    }
}
