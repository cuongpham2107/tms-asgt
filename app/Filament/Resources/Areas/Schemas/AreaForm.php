<?php

namespace App\Filament\Resources\Areas\Schemas;

use App\Enums\OrderType;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Support\RawJs;

class AreaForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Thông tin khu vực')
                    ->description('Quản lý thông tin chi tiết của khu vực')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        Select::make('type')
                            ->label('Loại đơn')
                            ->options(OrderType::class)
                            ->prefixIcon(Heroicon::OutlinedTag)
                            ->required(),
                        TextInput::make('code')
                            ->label('Mã khu vực')
                            ->prefixIcon(Heroicon::OutlinedHashtag)
                            ->required(),
                        TextInput::make('name')
                            ->label('Tên khu vực')
                            ->prefixIcon(Heroicon::OutlinedPencilSquare)
                            ->required()
                            ->columnSpanFull(),
                        ColorPicker::make('color')
                            ->label('Màu sắc'),
                        TextInput::make('sort_order')
                            ->label('Thứ tự hiển thị')
                            ->prefixIcon(Heroicon::OutlinedBarsArrowDown)
                            ->required()
                            ->mask(RawJs::make('$money($input)'))
                            ->stripCharacters(',')
                            ->numeric()
                            ->default(0),
                        Toggle::make('is_active')
                            ->label('Trạng thái kích hoạt')
                            ->default(true)
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
