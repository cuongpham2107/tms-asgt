<?php

namespace App\Filament\Resources\WaitingFeeRates\Tables;

use App\Filament\BaseTable;
use App\Models\FreightRate;
use App\Models\WaitingFeeRate;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class WaitingFeeRatesTable extends BaseTable
{
    public static function configure(Table $table): Table
    {
        return parent::applyDefaults($table)
            ->defaultSort('customer_id')
            ->columns([
                TextColumn::make('customer.code')
                    ->label('Khách hàng')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                TextColumn::make('surcharge_type')
                    ->label('Loại hình phụ phí')
                    ->placeholder('Chuẩn')
                    ->searchable(),
                TextColumn::make('waiting_mode')
                    ->label('Kiểu chờ')
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        WaitingFeeRate::MODE_ENGINE_ON => 'Nổ máy',
                        WaitingFeeRate::MODE_ENGINE_OFF => 'Không nổ máy',
                        default => 'Thường',
                    }),
                TextColumn::make('tiers')
                    ->label('Phí theo trọng tải (phí / miễn phí)')
                    ->state(fn (WaitingFeeRate $record): array => collect($record->tiers)
                        ->map(fn (array $tier): string => str_replace('.', ',', FreightRate::tonnageKey($tier['tonnage'])).'T: '
                            .number_format($tier['fee'], 0, ',', '.')." đ/{$record->fee_block_minutes}' · miễn {$tier['free_minutes']}'")
                        ->all())
                    ->badge()
                    ->color('gray')
                    ->wrap()
                    ->width('22rem'),
                TextColumn::make('grace_minutes')
                    ->label('Miễn thêm (phút)')
                    ->numeric(),
                TextColumn::make('effective_from')
                    ->label('Từ ngày')
                    ->date('d/m/Y')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('effective_to')
                    ->label('Đến ngày')
                    ->date('d/m/Y')
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('customer_id')
                    ->label('Khách hàng')
                    ->relationship('customer', 'code')
                    ->searchable()
                    ->preload(),
            ]);
    }
}
