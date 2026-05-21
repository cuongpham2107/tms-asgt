<?php

namespace App\Filament\Resources\Vehicles\Tables;

use App\Filament\BaseTable;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class VehiclesTable extends BaseTable
{
    public static function configure(Table $table): Table
    {
        return parent::applyDefaults($table)
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['driver', 'documents', 'maintenanceJobs']))
            ->columns([
                Stack::make([
                    ViewColumn::make('vehicle_card')
                        ->view('filament.resources.vehicles.columns.vehicle-card'),

                    // Hidden searchable columns
                    TextColumn::make('plate_number')
                        ->searchable()
                        ->hidden(),
                    TextColumn::make('vehicle_type')
                        ->searchable()
                        ->hidden(),
                    TextColumn::make('owner')
                        ->searchable()
                        ->hidden(),
                ])->extraAttributes([
                    'class' => '[&_.fi-ta-col]:block',
                ]),
            ])
            ->contentGrid([
                'md' => 3,
                'xl' => 4,
            ])

            ->recordActions([
                ViewAction::make('view')
                    ->label('Chi tiết xe')
                    ->button()
                    ->color('gray')
                    ->size('md')
                    ->modalWidth('2xl')
                    ->modalHeading(fn ($record) => "Thông tin xe: {$record->plate_number}")
                    ->modalDescription('Xem chi tiết thông tin xe')
                    ->extraModalFooterActions([
                        EditAction::make('edit')
                            ->button()
                            ->label('Chỉnh sửa')
                            ->icon('heroicon-o-pencil'),
                    ])
                    ->extraAttributes([
                        'class' => 'hidden',
                    ]),
                // EditAction::make()
                //     ->button()
                //     ->label('Chỉnh sửa')
                //     ->size('md')
                //     ->icon('heroicon-o-pencil')
                //     ->tooltip('Chỉnh sửa')
                //     ->extraAttributes([
                //         'class' => 'text-white font-bold [&_.fi-icon]:text-white! flex-1 bg-blue-600 cursor-pointer hover:bg-blue-700 transition-colors',
                //     ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    // DeleteBulkAction::make(),
                ]),
            ]);
    }
}
