<?php

namespace App\Filament\Resources\FreightRates\Schemas;

use App\Enums\CargoType;
use App\Enums\FreightRateType;
use App\Enums\VehicleType;
use App\Models\Location;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
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
                        FreightRateType::select('rate_type')
                            ->label('Loại hình tính cước'),
                        self::zoneSelect('origin_zone_id', 'originZone', 'Khu vực điểm đi'),
                        self::zoneSelect('destination_zone_id', 'destinationZone', 'Khu vực điểm đến'),
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

    /**
     * Khu vực tính cước, gợi ý các địa điểm thuộc khu vực để kiểm tra chọn đúng.
     */
    private static function zoneSelect(string $name, string $relationship, string $label): Select
    {
        return Select::make($name)
            ->label($label)
            ->relationship($relationship, 'name')
            ->searchable(['code', 'name'])
            ->preload()
            ->required()
            ->live()
            ->helperText(function (?string $state): ?string {
                if (blank($state)) {
                    return null;
                }
                $codes = Location::query()->where('billing_zone_id', $state)->orderBy('code')->pluck('code');

                return $codes->isEmpty()
                    ? 'Chưa có địa điểm nào thuộc khu vực này'
                    : 'Gồm: '.$codes->implode(', ');
            });
    }
}
