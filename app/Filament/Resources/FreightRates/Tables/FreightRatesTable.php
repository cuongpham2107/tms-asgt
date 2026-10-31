<?php

namespace App\Filament\Resources\FreightRates\Tables;

use App\Enums\CargoType;
use App\Enums\FreightRateType;
use App\Filament\BaseTable;
use App\Models\FreightRate;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class FreightRatesTable extends BaseTable
{
    public static function configure(Table $table): Table
    {
        return parent::applyDefaults($table)
            ->defaultSort('customer_id')
            ->columns([
                TextColumn::make('customer.code')
                    ->label('Khách hàng')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                TextColumn::make('originZone.name')
                    ->label('Khu vực đi')
                    ->searchable(['code', 'name']),
                TextColumn::make('destinationZone.name')
                    ->label('Khu vực đến')
                    ->searchable(['code', 'name']),
                TextColumn::make('cargo_type')
                    ->label('Loại hàng')
                    ->badge(),
                TextColumn::make('rate_type')
                    ->label('Loại hình')
                    ->placeholder('Chuẩn')
                    ->searchable(),
                TextColumn::make('vehicle_type')
                    ->label('Loại xe')
                    ->placeholder('Mọi loại')
                    ->toggleable(),
                TextColumn::make('prices')
                    ->label('Đơn giá theo trọng tải')
                    ->state(fn (FreightRate $record): array => collect($record->prices)
                        ->map(fn ($price, $tonnage): string => str_replace('.', ',', (string) $tonnage).'T: '.number_format((float) $price, 0, ',', '.'))
                        ->values()
                        ->all())
                    ->badge()
                    ->color('gray')
                    ->wrap()
                    ->width('22rem'),
                TextColumn::make('effective_from')
                    ->label('Từ ngày')
                    ->date('d/m/Y')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('effective_to')
                    ->label('Đến ngày')
                    ->date('d/m/Y')
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('customer_id')
                    ->label('Khách hàng')
                    ->relationship('customer', 'code')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('cargo_type')
                    ->label('Loại hàng')
                    ->options(CargoType::class),
                SelectFilter::make('rate_type')
                    ->label('Loại hình')
                    ->options(collect(FreightRateType::cases())->mapWithKeys(fn (FreightRateType $type): array => [$type->value => $type->getLabel()])->all()),
                SelectFilter::make('origin_zone_id')
                    ->label('Khu vực đi')
                    ->relationship('originZone', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('destination_zone_id')
                    ->label('Khu vực đến')
                    ->relationship('destinationZone', 'name')
                    ->searchable()
                    ->preload(),
            ]);
    }
}
