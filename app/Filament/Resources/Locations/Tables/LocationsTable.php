<?php

namespace App\Filament\Resources\Locations\Tables;

use App\Filament\BaseTable;
use App\Models\Location;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;

class LocationsTable extends BaseTable
{
    public static function configure(Table $table): Table
    {
        return parent::applyDefaults($table)
            ->columns([
                TextColumn::make('areas.code')
                    ->label('Khu vực')
                    ->badge()
                    ->searchable(),
                TextColumn::make('code')
                    ->label('Mã')
                    ->searchable()
                    ->weight('bold'),
                TextColumn::make('billingZone.name')
                    ->label('Khu vực tính cước')
                    ->placeholder('—')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('name')
                    ->label('Tên')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('loc_type')
                    ->label('Loại')
                    ->badge()
                    ->alignCenter()
                    ->color(fn ($state): string => (is_object($state) && method_exists($state, 'getColor')) ? ($state->getColor() ?? 'gray') : 'gray')
                    ->formatStateUsing(fn ($state) => (is_object($state) && method_exists($state, 'getLabel')) ? $state->getLabel() : $state)
                    ->searchable(),
                TextColumn::make('geofence_radius_m')
                    ->label('Vùng kiểm tra GPS')
                    ->alignCenter()
                    ->state(function (Location $record): string {
                        if ($record->hasRectangleGeofence()) {
                            $dims = $record->getBoundsDimensionsMeters();
                            $shape = $record->geofence_type === 'square' ? 'Hình vuông' : 'Hình chữ nhật';

                            return $dims
                                ? "{$shape} ({$dims['width_m']}m × {$dims['height_m']}m)"
                                : $shape;
                        }

                        return $record->geofence_radius_m
                            ? "{$record->geofence_radius_m} m"
                            : config('checkpoint_geofence.radius_m', 3000).' m (mặc định)';
                    })
                    ->badge()
                    ->color(fn (Location $record): string => $record->hasRectangleGeofence() ? 'success' : ($record->geofence_radius_m ? 'primary' : 'gray'))
                    ->toggleable(),
                IconColumn::make('is_active')
                    ->label('Hoạt động')
                    ->alignCenter()
                    ->boolean(),
                TextColumn::make('address')
                    ->label('Địa chỉ')
                    ->limit(60)
                    ->wrap(),
                TextColumn::make('created_at')
                    ->label('Tạo lúc')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label('Cập nhật')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->groups([
                Group::make('area_id')
                    ->label('Khu vực')
                    ->getTitleFromRecordUsing(fn (Location $record): string => $record->area ? $record->area->code : 'Chưa xác định')
                    ->collapsible(),
            ])
            ->defaultPaginationPageOption(50)
            ->defaultGroup('area_id')
            ->groupingSettingsHidden()
            ->recordActions([
                EditAction::make()
                    ->iconButton(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
