<?php

namespace App\Filament\Resources\FreightRates\Schemas;

use App\Enums\CargoType;
use App\Enums\VehicleType;
use App\Models\FreightRate;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class FreightRateForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Tuyến & khách hàng')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        Select::make('customer_id')
                            ->label('Khách hàng')
                            ->relationship('customer', 'code')
                            ->searchable(['code', 'name'])
                            ->preload()
                            ->required(),
                        TextInput::make('rate_type')
                            ->label('Loại hình tính cước')
                            ->placeholder('Để trống = giá chuẩn')
                            ->datalist(fn (): array => FreightRate::query()->where('rate_type', '!=', '')->distinct()->pluck('rate_type')->all())
                            ->dehydrateStateUsing(fn (?string $state): string => trim((string) $state)),
                        self::locationSelect('origin_location_id', 'originLocation', 'Điểm đi')->required(),
                        self::locationSelect('destination_location_id', 'destinationLocation', 'Điểm đến')->required(),
                    ]),
                Section::make('Giá')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        Select::make('cargo_type')
                            ->label('Loại hàng')
                            ->options(CargoType::class)
                            ->default(CargoType::Gcr)
                            ->required(),
                        Select::make('vehicle_type')
                            ->label('Loại xe')
                            ->options(VehicleType::class)
                            ->placeholder('Mọi loại xe'),
                        KeyValue::make('prices')
                            ->label('Đơn giá theo trọng tải')
                            ->keyLabel('Trọng tải tính cước (tấn)')
                            ->keyPlaceholder('VD: 1.25')
                            ->valueLabel('Đơn giá (đ/chuyến)')
                            ->valuePlaceholder('VD: 750000')
                            ->addActionLabel('Thêm bậc tải')
                            ->reorderable(false)
                            ->required()
                            ->columnSpanFull(),
                        DatePicker::make('effective_from')
                            ->label('Áp dụng từ ngày'),
                        DatePicker::make('effective_to')
                            ->label('Áp dụng đến ngày')
                            ->afterOrEqual('effective_from'),
                        Textarea::make('notes')
                            ->label('Ghi chú')
                            ->rows(2)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    private static function locationSelect(string $name, string $relationship, string $label): Select
    {
        return Select::make($name)
            ->label($label)
            ->relationship($relationship, 'code')
            ->searchable(['code', 'name'])
            ->preload();
    }
}
