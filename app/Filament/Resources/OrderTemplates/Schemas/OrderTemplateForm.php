<?php

namespace App\Filament\Resources\OrderTemplates\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class OrderTemplateForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Tên mẫu')
                    ->prefixIcon(Heroicon::OutlinedPencilSquare)
                    ->required(),
                Textarea::make('order_data')
                    ->label('Dữ liệu mẫu')
                    ->required()
                    ->columnSpanFull(),
                TextInput::make('quantity')
                    ->label('Số lượng')
                    ->prefixIcon(Heroicon::OutlinedCube)
                    ->required()
                    ->numeric()
                    ->default(1),
                TextInput::make('cron_expression')
                    ->label('Cron')
                    ->prefixIcon(Heroicon::OutlinedClock),
                TimePicker::make('daily_run_at')
                    ->label('Chạy lúc')
                    ->prefixIcon(Heroicon::OutlinedClock),
                Toggle::make('is_active')
                    ->label('Kích hoạt')
                    ->required(),
            ]);
    }
}
