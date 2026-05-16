<?php

namespace App\Filament\Resources\OrderTypes\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class OrderTypeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('code')
                    ->label('Mã')
                    ->prefixIcon(Heroicon::OutlinedHashtag)
                    ->required(),
                TextInput::make('name')
                    ->label('Tên')
                    ->prefixIcon(Heroicon::OutlinedPencilSquare)
                    ->required(),
                TextInput::make('color')
                    ->label('Màu'),
                TextInput::make('sort_order')
                    ->label('Thứ tự')
                    ->prefixIcon(Heroicon::OutlinedBarsArrowDown)
                    ->required()
                    ->numeric()
                    ->default(0),
                Toggle::make('is_active')
                    ->label('Kích hoạt')
                    ->required(),
            ]);
    }
}
