<?php

namespace App\Filament\Resources\FeeTypes\Tables;

use App\Filament\BaseTable;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class FeeTypesTable extends BaseTable
{
    /**
     * Loại phí đã dùng trong cước đơn hàng không xoá được (khoá ngoại restrict) — tắt "Đang dùng" thay vì xoá.
     */
    protected static function getDefaultToolbarActions(): array
    {
        return [];
    }

    public static function configure(Table $table): Table
    {
        return parent::applyDefaults($table)
            ->defaultSort('sort')
            ->reorderable('sort')
            ->columns([
                TextColumn::make('sort')
                    ->label('#')
                    ->sortable(),
                TextColumn::make('name')
                    ->label('Tên phí')
                    ->searchable()
                    ->weight('bold'),
                TextColumn::make('code')
                    ->label('Mã')
                    ->searchable()
                    ->color('gray'),
                IconColumn::make('is_active')
                    ->label('Đang dùng')
                    ->boolean(),
            ]);
    }
}
