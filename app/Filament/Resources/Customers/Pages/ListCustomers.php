<?php

namespace App\Filament\Resources\Customers\Pages;

use App\Filament\Resources\Customers\CustomerResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCustomers extends ListRecords
{
    protected static string $resource = CustomerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Thêm mới KH')
                ->icon('heroicon-o-plus')
                ->extraAttributes([
                    'class' => 'font-bold text-white [&_.fi-icon]:text-white bg-[#f97316] cursor-pointer hover:bg-[#ea6c0a] transition-colors',
                ])
                ->modalHeading('Tạo khách hàng')
                ->modalDescription('Vui lòng nhập thông tin khách hàng'),
        ];
    }
}
