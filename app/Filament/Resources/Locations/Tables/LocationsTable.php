<?php

namespace App\Filament\Resources\Locations\Tables;

use App\Filament\BaseTable;
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
                TextColumn::make('area.code')
                    ->label('Khu vực')
                    ->searchable(),
                TextColumn::make('code')
                    ->label('Mã')
                    ->searchable()
                    ->weight('bold'),
                TextColumn::make('name')
                    ->label('Tên')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('loc_type')
                    ->label('Loại')
                    ->badge()
                    ->color(fn ($state): string => (is_object($state) && method_exists($state, 'getColor')) ? ($state->getColor() ?? 'gray') : 'gray')
                    ->formatStateUsing(fn ($state) => (is_object($state) && method_exists($state, 'getLabel')) ? $state->getLabel() : $state)
                    ->searchable(),
                IconColumn::make('is_active')
                    ->label('Hoạt động')
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
                Group::make('area.code')
                    ->label('Khu vực')
                    ->collapsible(),
            ])
            ->defaultGroup('area.code')
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
