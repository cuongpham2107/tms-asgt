<?php

namespace App\Filament\Resources\FeeTypes\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class FeeTypeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Loại phí')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('name')
                            ->label('Tên phí')
                            ->required(),
                        TextInput::make('code')
                            ->label('Mã')
                            ->unique(ignoreRecord: true)
                            ->required(),
                        TextInput::make('sort')
                            ->label('Thứ tự (cột trong bảng kê)')
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->required(),
                        Toggle::make('is_active')
                            ->label('Đang dùng')
                            ->default(true)
                            ->inline(false),
                    ]),
            ]);
    }
}
