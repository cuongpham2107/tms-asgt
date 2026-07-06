<?php

namespace App\Filament\Resources\Vehicles\Tables;

use App\Filament\BaseTable;
use App\Filament\Tables\Columns\UniqueMapColumn;
use App\Models\Vehicle;
use EduardoRibeiroDev\FilamentLeaflet\Layers\Marker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;

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
            ]);
    }
}
