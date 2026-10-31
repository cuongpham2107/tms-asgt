<?php

namespace App\Filament\Resources\WaitingFeeRates\Pages;

use App\Filament\Resources\WaitingFeeRates\WaitingFeeRateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListWaitingFeeRates extends ListRecords
{
    protected static string $resource = WaitingFeeRateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Thêm phí giờ chờ'),
        ];
    }
}
