<?php

namespace App\Filament\Resources\Customers\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CustomersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount('orders'))
            ->columns([
                Stack::make([
                    ViewColumn::make('customer_card')
                        ->view('filament.resources.customers.columns.customer-card'),

                    // Hidden searchable columns
                    TextColumn::make('name')
                        ->searchable()
                        ->hidden(),
                    TextColumn::make('phone')
                        ->searchable()
                        ->hidden(),
                    TextColumn::make('email')
                        ->searchable()
                        ->hidden(),
                    TextColumn::make('address')
                        ->searchable()
                        ->hidden(),
                ])->extraAttributes([
                    'class' => '[&_.fi-ta-col]:block',
                ]),
            ])
            ->contentGrid([
                'md' => 2,
                'xl' => 3,
            ])
            ->recordActions([
                ViewAction::make('view')
                    ->label('Chi tiết khách hàng')
                    ->button()
                    ->color('gray')
                    ->size('md')
                    ->modalDescription('Xem chi tiết thông tin khách hàng')
                    ->extraAttributes([
                        'class' => 'flex-1 ',
                    ]),
                EditAction::make()
                    ->button()
                    ->label('Tạo đơn')
                    ->size('md')
                    ->icon('heroicon-o-plus')
                    ->tooltip('Tạo đơn')

                    ->extraAttributes([
                        'class' => 'text-white font-bold [&_.fi-icon]:text-white! flex-1 bg-[#f97316] cursor-pointer hover:bg-[#ea6c0a] transition-colors',
                    ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    // DeleteBulkAction::make(),
                ]),
            ]);
    }
}
