<?php

namespace App\Filament\Resources\FreightRates\Tables;

use App\Enums\CargoType;
use App\Enums\FreightPricingMethod;
use App\Enums\FreightRateType;
use App\Filament\BaseTable;
use App\Models\FreightRate;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
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
                TextColumn::make('pricing_method')
                    ->label('Phương thức')
                    ->badge()
                    ->toggleable(),
                TextColumn::make('rate_type')
                    ->label('Loại hình')
                    ->placeholder('Chuẩn')
                    ->searchable(),
                TextColumn::make('vehicle_type')
                    ->label('Loại xe')
                    ->placeholder('Mọi loại')
                    ->toggleable(),
                TextColumn::make('prices_display')
                    ->label('Đơn giá áp dụng')
                    ->state(function (FreightRate $record): array {
                        if ($record->pricing_method === FreightPricingMethod::ByWeightRange && ! empty($record->range_prices)) {
                            return collect($record->range_prices)
                                ->map(fn ($tier): string => ($tier['label'] ?? "{$tier['from']}-{$tier['to']}T").': '.number_format((float) ($tier['price'] ?? 0), 0, ',', '.'))
                                ->all();
                        }

                        return collect($record->prices)
                            ->map(fn ($price, $tonnage): string => str_replace('.', ',', (string) $tonnage).'T: '.number_format((float) $price, 0, ',', '.'))
                            ->values()
                            ->all();
                    })
                    ->badge()
                    ->color(fn (FreightRate $record): string => $record->pricing_method === FreightPricingMethod::ByWeightRange ? 'info' : 'gray')
                    ->wrap()
                    ->width('24rem'),
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
                SelectFilter::make('pricing_method')
                    ->label('Phương thức tính giá')
                    ->options(FreightPricingMethod::class),
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
            ], layout: FiltersLayout::AboveContent)
            // Bộ lọc hiện sẵn phía trên bảng như bảng tài xế, chọn là lọc ngay.
            ->filtersFormColumns(['sm' => 2, 'md' => 3, 'xl' => 6])
            ->deferFilters(false);
    }
}
