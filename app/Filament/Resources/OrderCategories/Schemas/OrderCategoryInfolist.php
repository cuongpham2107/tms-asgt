<?php

namespace App\Filament\Resources\OrderCategories\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class OrderCategoryInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Thông tin phân loại đơn')
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('type')
                            ->label('Loại đơn')
                            ->badge()
                            ->formatStateUsing(fn ($state) => $state?->getLabel() ?? '-')
                            ->color(fn ($state) => $state?->getColor() ?? 'gray'),
                        TextEntry::make('code')
                            ->label('Mã')
                            ->badge()
                            ->color('primary'),
                        TextEntry::make('name')
                            ->label('Tên phân loại')
                            ->weight('bold'),
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
