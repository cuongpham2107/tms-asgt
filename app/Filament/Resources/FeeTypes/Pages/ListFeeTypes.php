<?php

namespace App\Filament\Resources\FeeTypes\Pages;

use App\Filament\Resources\FeeTypes\FeeTypeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFeeTypes extends ListRecords
{
    protected static string $resource = FeeTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Thêm loại phí'),
        ];
    }
}
