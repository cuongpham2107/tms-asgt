<?php

namespace App\Filament\Resources\Customers\Schemas;

use App\Filament\Resources\Locations\Schemas\LocationForm;
use App\Models\Location;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class CustomerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Thông tin khách hàng')
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
                            ->maxLength(255),
                        TextInput::make('tax_code')
                            ->label('Mã số thuế')
                            ->prefixIcon(Heroicon::OutlinedDocumentText)
                            ->maxLength(50),
                        Select::make('location_id')
                            ->label('Địa điểm')
                            ->prefixIcon(Heroicon::OutlinedMapPin)
                            ->options(fn (): array => Location::query()
                                ->orderBy('code', 'asc')
                                ->get(['id', 'code', 'name', 'address'])
                                ->mapWithKeys(fn (Location $location, $key): array => [
                                    $location->id => trim(implode(' - ', array_filter([
                                        $location->code,
                                        $location->name !== $location->code ? $location->name : null,
                                        $location->address,
                                    ])), ' -'),
                                ])
                                ->toArray()
                            )
                            ->searchable()
                            ->preload()
                            ->live()
                            ->afterStateUpdated(function ($state, Set $set): void {
                                $location = $state ? Location::query()->find($state) : null;
                                $set('address', $location?->address);
                            })
                            ->createOptionForm(fn (Schema $schema): array => LocationForm::configure($schema)->getComponents())
                            ->createOptionUsing(fn (array $data, Schema $schema): int => LocationForm::createFromOptionForm($data, $schema))
                            ->columnSpanFull(),
                        TextInput::make('address')
                            ->label('Địa chỉ đầy đủ')
                            ->prefixIcon(Heroicon::OutlinedMapPin)
                            ->columnSpanFull(),
                        Toggle::make('is_active')
                            ->label('Trạng thái hoạt động')
                            ->default(true)
                            ->inline(true)
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull()
                    ->columns(2),
                Section::make('Thông số tính cước')
                    ->description('Chỉ áp cho cước tính mới; cước đã tính giữ nguyên.')
                    ->schema([
                        TextInput::make('fuel_adjustment_percent')
                            ->label('% tăng/giảm giá dầu')
                            ->hintIcon(Heroicon::OutlinedInformationCircle, 'Số âm là giảm giá. Đơn giá sau điều chỉnh làm tròn lên hàng nghìn.')
                            ->required()
                            ->numeric()
                            ->minValue(-100)
                            ->maxValue(100)
                            ->default(0)
                            ->suffix('%'),
                        TextInput::make('cancel_trip_percent')
                            ->label('% đơn giá khi huỷ chuyến')
                            ->required()
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->default(0)
                            ->suffix('%'),
                    ])
                    ->columnSpanFull()
                    ->columns(2),
            ]);
    }
}
