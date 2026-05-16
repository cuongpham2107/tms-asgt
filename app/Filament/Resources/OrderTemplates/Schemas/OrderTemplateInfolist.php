<?php

namespace App\Filament\Resources\OrderTemplates\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class OrderTemplateInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Thông tin mẫu đơn')
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('name')
                            ->label('Tên mẫu')
                            ->weight('bold')
                            ->size('lg'),
                        TextEntry::make('description')
                            ->label('Mô tả')
                            ->columnSpanFull(),
                        TextEntry::make('is_active')
                            ->label('Trạng thái')
                            ->badge()
                            ->color(fn ($state) => $state ? 'success' : 'danger')
                            ->formatStateUsing(fn ($state) => $state ? 'Hoạt động' : 'Không hoạt động'),
                        TextEntry::make('created_at')
                            ->label('Tạo lúc')
                            ->dateTime(),
                        TextEntry::make('updated_at')
                            ->label('Cập nhật')
                            ->dateTime(),
                    ])
                    ->columns(2),
            ]);
    }
}
