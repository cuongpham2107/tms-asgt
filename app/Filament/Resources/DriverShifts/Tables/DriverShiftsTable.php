<?php

namespace App\Filament\Resources\DriverShifts\Tables;

use App\Filament\BaseTable;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DriverShiftsTable extends BaseTable
{
    public static function configure(Table $table): Table
    {
        return parent::applyDefaults($table)
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['driver', 'vehicle']))
            ->columns([
                TextColumn::make('driver.name')
                    ->label('Tài xế')
                    ->searchable(),
                TextColumn::make('vehicle.id')
                    ->label('Xe')
                    ->searchable(),
                TextColumn::make('shift_type')
                    ->label('Loại ca')
                    ->badge()
                    ->searchable(),
                TextColumn::make('start_time')
                    ->label('Giờ vào ca')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('start_km')
                    ->label('Km vào ca')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('start_gps_lat')
                    ->label('GPS vào ca (lat)')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('start_gps_lng')
                    ->label('GPS vào ca (lng)')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('end_time')
                    ->label('Giờ kết thúc ca')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('end_km')
                    ->label('Km kết thúc ca')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('end_gps_lat')
                    ->label('GPS kết thúc (lat)')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('end_gps_lng')
                    ->label('GPS kết thúc (lng)')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('total_km')
                    ->label('Tổng km')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('total_km_loaded')
                    ->label('Km có hàng')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('total_km_empty')
                    ->label('Km rỗng')
                    ->numeric()
                    ->sortable(),
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
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
