<?php

namespace App\Filament\Resources\OrderTypes\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class OrderTypeInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Thông tin loại đơn')
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('code')
                            ->label('Mã')
                            ->badge()
                            ->color('primary'),
                        TextEntry::make('name')
                            ->label('Tên loại')
                            ->weight('bold')
                            ->size('lg'),
                        TextEntry::make('color')
                            ->label('Màu')
                            ->formatStateUsing(fn ($state) => $state ?: '-'),
                        TextEntry::make('sort_order')
                            ->label('Thứ tự')
                            ->numeric(),
                        TextEntry::make('is_active')
                            ->label('Trạng thái')
                            ->badge()
                            ->color(fn ($state) => $state ? 'success' : 'danger')
                            ->formatStateUsing(fn ($state) => $state ? 'Hoạt động' : 'Không hoạt động'),
                    ])
                    ->columns(2),
            ]);
    }
}
