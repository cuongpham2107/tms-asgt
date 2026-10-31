<?php

namespace App\Filament\Resources\BillingZones\Tables;

use App\Filament\BaseTable;
use App\Models\BillingZone;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class BillingZonesTable extends BaseTable
{
    public static function configure(Table $table): Table
    {
        return parent::applyDefaults($table)
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with('locations:id,billing_zone_id,code')
                ->withCount(['locations', 'originRates', 'destinationRates']))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Tên khu vực')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                TextColumn::make('code')
                    ->label('Mã viết tắt')
                    ->searchable()
                    ->color('gray'),
                TextColumn::make('locations.code')
                    ->label('Địa điểm')
                    ->badge()
                    ->color('gray')
                    ->wrap()
                    ->searchable()
                    ->placeholder('Chưa có địa điểm')
                    ->width('28rem'),
                TextColumn::make('locations_count')
                    ->label('Số địa điểm')
                    ->sortable()
                    ->alignCenter(),
                TextColumn::make('rates_count')
                    ->label('Số dòng giá')
                    ->state(fn (BillingZone $record): int => $record->origin_rates_count + $record->destination_rates_count)
                    ->alignCenter(),
            ])
            ->filters([
                TernaryFilter::make('has_locations')
                    ->label('Có địa điểm')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->has('locations'),
                        false: fn (Builder $query): Builder => $query->doesntHave('locations'),
                    ),
            ]);
    }
}
