<?php

namespace App\Filament\Resources\Customers\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class CustomerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->schema([
                        TextInput::make('name')
                            ->label('Khách hàng')
                            ->prefixIcon(Heroicon::OutlinedUser)
                            ->required()
                            ->maxLength(255),

                        TextInput::make('code')
                            ->label('Mã KH')
                            ->prefixIcon(Heroicon::OutlinedHashtag)
                            ->unique(ignoreRecord: true)
                            ->maxLength(50)
                            ->default(fn () => 'KH-'.strtoupper(uniqid())),
                        TextInput::make('phone')
                            ->label('Điện thoại')
                            ->prefixIcon(Heroicon::OutlinedPhone)
                            ->tel()
                            ->maxLength(20),

                        TextInput::make('email')
                            ->label('Email')
                            ->prefixIcon(Heroicon::OutlinedEnvelope)
                            ->email()
                            ->maxLength(255),

                        TextInput::make('contact_person')
                            ->label('Người liên hệ')
                            ->prefixIcon(Heroicon::OutlinedIdentification)
                            ->maxLength(255)
                            ->columnSpanFull(),
                        TextInput::make('address')
                            ->label('Địa chỉ đầy đủ')
                            ->prefixIcon(Heroicon::OutlinedMapPin)
                            ->maxLength(500)
                            ->columnSpanFull(),
                        Toggle::make('is_active')
                            ->label('Trạng thái hoạt động')
                            ->default(true)
                            ->inline(true)
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull()
                    ->columns(2),
            ]);
    }
}
