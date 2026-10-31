<?php

namespace App\Filament\Resources\BillingZones\Pages;

use App\Filament\Resources\BillingZones\BillingZoneResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBillingZones extends ListRecords
{
    protected static string $resource = BillingZoneResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Thêm khu vực')
                ->modalHeading('Thêm khu vực tính cước'),
        ];
    }
}
