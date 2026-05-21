<?php

namespace App\Filament\Resources\Customers\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CustomerInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Thông tin khách hàng')
                    ->schema([
                        TextEntry::make('name')
                            ->label('Khách hàng')
                            ->weight('bold')
                            ->size('lg')
                            ->icon('heroicon-m-user'),

                        TextEntry::make('code')
                            ->label('Mã KH')
                            ->badge()
                            ->color('primary'),

                        TextEntry::make('phone')
                            ->label('Điện thoại')
                            ->icon('heroicon-m-phone')
                            ->copyable(),

                        TextEntry::make('email')
                            ->label('Email')
                            ->icon('heroicon-m-envelope')
                            ->copyable(),

                        TextEntry::make('contact_person')
                            ->label('Người liên hệ')
                            ->icon('heroicon-m-identification')
                            ->columnSpanFull(),

                        TextEntry::make('address')
                            ->label('Địa chỉ đầy đủ')
                            ->icon('heroicon-m-map-pin')
                            ->columnSpanFull(),

                        TextEntry::make('is_active')
                            ->label('Trạng thái hoạt động')
                            ->badge()
                            ->color(fn ($state) => $state ? 'success' : 'danger')
                            ->formatStateUsing(fn ($state) => $state ? 'Hoạt động' : 'Không hoạt động'),

                        TextEntry::make('orders_count')
                            ->label('Tổng đơn hàng')
                            ->numeric()
                            ->prefix('Đơn ')
                            ->weight('bold')
                            ->color('warning'),
                    ])
                    ->columnSpanFull()
                    ->columns(2),
            ]);
    }
}
