<?php

namespace App\Filament\Resources\Customers\Schemas;

use App\Filament\Resources\Locations\Schemas\LocationForm;
use App\Models\Location;
use Filament\Forms\Components\Select;
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
                        Select::make('address')
                            ->label('Địa chỉ đầy đủ')
                            ->prefixIcon(Heroicon::OutlinedMapPin)
                            ->options(fn (): array => Location::query()
                                ->whereNotNull('address')
                                ->where('address', '!=', '')
                                ->get(['id', 'name', 'address'])
                                ->mapWithKeys(fn (Location $location): array => [
                                    $location->id => "{$location->name} - {$location->address}",
                                ])
                                ->toArray()
                            )
                            ->searchable()
                            ->preload()
                            ->formatStateUsing(fn ($state) => Location::query()->where('address', $state)->first()?->id)
                            ->dehydrateStateUsing(fn ($state) => Location::query()->find($state)?->address)
                            ->createOptionForm(fn (Schema $schema): array => LocationForm::configure($schema)->getComponents())
                            ->createOptionUsing(function (array $data): int {
                                $location = Location::create($data);

                                return $location->id;
                            })
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
