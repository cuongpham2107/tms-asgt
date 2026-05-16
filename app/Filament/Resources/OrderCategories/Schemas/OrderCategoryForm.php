<?php

namespace App\Filament\Resources\OrderCategories\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class OrderCategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('order_type_id')
                    ->label('Loại đơn')
                    ->relationship('orderType', 'name')
                    ->prefixIcon(Heroicon::OutlinedTag)
                    ->required(),
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
