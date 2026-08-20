<?php

namespace App\Filament\Resources\Vehicles\Tables;

use App\Filament\BaseTable;
use App\Filament\Tables\Columns\UniqueMapColumn;
use App\Models\Vehicle;
use EduardoRibeiroDev\FilamentLeaflet\Layers\Marker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class VehiclesTable extends BaseTable
{
    public static function configure(Table $table): Table
    {
        return parent::applyDefaults($table)
            ->modifyQueryUsing(fn ($query) => $query->with(['driver', 'documents', 'maintenanceJobs']))
            ->groups([
                Group::make('type')
                    ->label('Loại')
                    ->collapsible(),
            ])
            ->defaultGroup('type')
            ->groupingSettingsHidden()
            ->columns([
                TextColumn::make('type')
                    ->label('Quản lý xe')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                TextColumn::make('plate_number')
                    ->label('Biển số')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                UniqueMapColumn::make('location')
                    ->label('Vị trí')
                    ->height(80)
                    ->width(100)
                    ->zoom(15)
                    ->pickMarker(fn (Marker $marker) => $marker->icon(asset('images/truck.png'), [16, 16]))
                    ->static()
                    ->placeholder('—')
                    ->state(fn (Vehicle $record): ?array => $record->gps_lat && $record->gps_lng
                        ? ['lat' => (float) $record->gps_lat, 'lng' => (float) $record->gps_lng]
                        : null),
                TextColumn::make('vehicle_type')
                    ->label('Kiểu xe')
                    ->badge()
                    ->sortable()
                    ->searchable(),
                // TextColumn::make('type')
                //     ->label('Loại xe')
                //     ->badge()
                //     ->sortable(),
                TextColumn::make('owner')
                    ->label('Chủ xe')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('driver.name')
                    ->label('Tài xế')
                    ->searchable(),
                TextColumn::make('status')
                    ->label('Trạng thái')
                    ->badge()
                    ->sortable(),
                TextColumn::make('dangerous_goods_permit_expiry_date')
                    ->label('Hạn GP Hàng nguy hiểm')
                    ->badge()
                    ->color(fn (?Vehicle $record): string => $record?->getDangerousGoodsPermitStatus()['color'] ?? 'gray')
                    ->state(fn (?Vehicle $record): string => $record?->getDangerousGoodsPermitStatus()['formatted_date'] ?? '—')
                    ->description(fn (?Vehicle $record): ?string => $record?->dangerous_goods_permit_expiry_date ? $record->getDangerousGoodsPermitStatus()['label'] : null)
                    ->sortable(),
                TextColumn::make('make')
                    ->label('Hiệu xe')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('model_year')
                    ->label('Năm SX')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('current_mileage')
                    ->label('Số km')
                    ->formatStateUsing(fn ($state) => $state ? number_format($state, 0, ',', '.').' km' : '—')
                    ->sortable(),
                TextColumn::make('load_capacity')
                    ->label('Tải trọng')
                    ->formatStateUsing(fn ($state) => $state ? number_format($state, 1).' tấn' : '—')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Ngày tạo')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('expired_dg_permit')
                    ->label('Có GP Hàng nguy hiểm đã hết hạn')
                    ->query(fn (Builder $query): Builder => $query->whereNotNull('dangerous_goods_permit_expiry_date')->where('dangerous_goods_permit_expiry_date', '<', now()->toDateString())),

                Filter::make('expiring_soon_dg_permit')
                    ->label('GP Hàng nguy hiểm sắp hết hạn (30 ngày)')
                    ->query(fn (Builder $query): Builder => $query->whereBetween('dangerous_goods_permit_expiry_date', [now()->toDateString(), now()->addDays(30)->toDateString()])),
            ]);
    }
}
