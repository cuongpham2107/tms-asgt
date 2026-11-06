<?php

namespace App\Filament\Resources\FeeTypes\Tables;

use App\Enums\FeeCalcMethod;
use App\Filament\BaseTable;
use App\Models\FeeType;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class FeeTypesTable extends BaseTable
{
    // /**
    //  * Loại phí đã dùng trong cước đơn hàng không xoá được (khoá ngoại restrict) — tắt "Đang dùng" thay vì xoá.
    //  */
    // protected static function getDefaultToolbarActions(): array
    // {
    //     return [];
    // }

    public static function configure(Table $table): Table
    {
        return parent::applyDefaults($table)
            ->defaultSort('sort')
            ->reorderable('sort')
            ->columns([
                TextColumn::make('sort')
                    ->label('#')
                    ->sortable(),
                TextColumn::make('customer.code')
                    ->label('Khách hàng')
                    ->placeholder('Dùng chung')
                    ->weight('bold')
                    ->sortable(),
                TextColumn::make('name')
                    ->label('Tên phí')
                    ->searchable()
                    ->weight('bold'),
                TextColumn::make('code')
                    ->label('Mã')
                    ->searchable()
                    ->color('gray'),
                TextColumn::make('calc_method')
                    ->label('Cách tính')
                    ->badge(),
                TextColumn::make('amount')
                    ->label('Số tiền / tỷ lệ')
                    ->state(fn (FeeType $record): ?string => $record->amount === null || ! $record->method()->isAuto()
                        ? null
                        : number_format((float) $record->amount, 0, ',', '.').' '.$record->method()->unit())
                    ->placeholder('—'),
                IconColumn::make('auto_apply')
                    ->label('Tự cộng mọi đơn')
                    ->boolean()
                    ->toggleable(),
                TextColumn::make('description')
                    ->label('Diễn giải')
                    ->placeholder('—')
                    ->wrap()
                    ->toggleable(),
                IconColumn::make('is_active')
                    ->label('Đang dùng')
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('customer_id')
                    ->label('Khách hàng')
                    ->relationship('customer', 'code')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('scope')
                    ->label('Phạm vi')
                    ->options(['shared' => 'Dùng chung', 'customer' => 'Riêng của khách'])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'shared' => $query->whereNull('customer_id'),
                        'customer' => $query->whereNotNull('customer_id'),
                        default => $query,
                    }),
                SelectFilter::make('calc_method')
                    ->label('Cách tính')
                    ->options(FeeCalcMethod::class),
            ], layout: FiltersLayout::AboveContent)
            ->filtersFormColumns(['sm' => 2, 'md' => 3])
            ->deferFilters(false);
    }
}
