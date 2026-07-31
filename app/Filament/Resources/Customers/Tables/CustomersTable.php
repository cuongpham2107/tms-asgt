<?php

namespace App\Filament\Resources\Customers\Tables;

use App\Filament\BaseTable;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CustomersTable extends BaseTable
{
    public static function configure(Table $table): Table
    {
        return parent::applyDefaults($table)
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount('orders'))
            ->columns([
                TextColumn::make('code')
                    ->label('Mã KH')
                    ->searchable()
                    ->weight('bold'),
                TextColumn::make('name')
                    ->label('Tên khách hàng')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('phone')
                    ->label('Điện thoại')
                    ->searchable(),
                TextColumn::make('email')
                    ->label('Email')
                    ->searchable(),
                TextColumn::make('orders_count')
                    ->label('Số đơn')
                    ->alignCenter()
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label('Hoạt động')
                    ->alignCenter()
                    ->boolean(),
            ])
            ->recordActions([
                ViewAction::make('view')
                    ->label('')
                    ->color('gray')
                    ->modalDescription('Xem chi tiết thông tin khách hàng'),
                EditAction::make()
                    ->label('')
                    ->tooltip('Sửa'),
            ], position: RecordActionsPosition::BeforeColumns);
    }
}
