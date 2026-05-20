<?php

namespace App\Filament\Resources\OrderCategories\Tables;

use App\Filament\BaseTable;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class OrderCategoriesTable extends BaseTable
{
    public static function configure(Table $table): Table
    {
        return parent::applyDefaults($table)
            ->columns([
                TextColumn::make('type')
                    ->label('Loại đơn')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state?->getLabel() ?? '-')
                    ->color(fn ($state) => $state?->getColor() ?? 'gray')
                    ->searchable(),
                TextColumn::make('code')
                    ->label('Mã')
                    ->searchable(),
                TextColumn::make('name')
                    ->label('Tên phân loại')
                    ->searchable(),
                TextColumn::make('color')
                    ->label('Màu')
                    ->searchable(),
                TextColumn::make('sort_order')
                    ->label('Thứ tự')
                    ->numeric()
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label('Hoạt động')
                    ->boolean(),
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
