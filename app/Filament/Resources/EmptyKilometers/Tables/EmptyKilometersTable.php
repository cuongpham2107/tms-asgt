<?php

namespace App\Filament\Resources\EmptyKilometers\Tables;

use App\Filament\BaseTable;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class EmptyKilometersTable extends BaseTable
{
    public static function configure(Table $table): Table
    {
        return parent::applyDefaults($table)
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['driver', 'vehicle', 'shift']))
            ->columns([
                TextColumn::make('driver.name')
                    ->label('Lái xe')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('vehicle.plate_number')
                    ->label('Biển số xe')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('start_km')
                    ->label('Km bắt đầu')
                    ->numeric(decimalPlaces: 1)
                    ->sortable(),

                TextColumn::make('end_km')
                    ->label('Km kết thúc')
                    ->numeric(decimalPlaces: 1)
                    ->sortable(),

                TextColumn::make('distance')
                    ->label('Km không hàng')
                    ->numeric(decimalPlaces: 1)
                    ->sortable()
                    ->weight('bold')
                    ->color('warning'),

                TextColumn::make('started_at')
                    ->label('Bắt đầu lúc')
                    ->dateTime('H:i d/m/Y')
                    ->sortable(),

                TextColumn::make('ended_at')
                    ->label('Kết thúc lúc')
                    ->dateTime('H:i d/m/Y')
                    ->sortable(),

                TextColumn::make('note')
                    ->label('Ghi chú')
                    ->limit(40)
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->label('Tạo lúc')
                    ->dateTime('H:i d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('started_at', 'desc')
            ->recordActions([
                ViewAction::make(),
                DeleteAction::make(),
            ]);
    }
}
