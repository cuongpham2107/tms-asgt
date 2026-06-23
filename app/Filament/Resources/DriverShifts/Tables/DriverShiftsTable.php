<?php

namespace App\Filament\Resources\DriverShifts\Tables;

use App\Filament\BaseTable;
use App\Filament\Resources\DriverShifts\Actions\EndShiftAction;
use App\Models\DriverShift;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DriverShiftsTable extends BaseTable
{
    public static function configure(Table $table): Table
    {
        return parent::applyDefaults($table)
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['driver', 'trips.vehicle']))
            ->columns([
                TextColumn::make('driver.name')
                    ->label('Tài xế')
                    ->searchable(),
                TextColumn::make('vehicle_id')
                    ->label('Xe')
                    ->formatStateUsing(fn (DriverShift $record) => $record->trips()->first()?->vehicle?->plate_number ?? '-')
                    ->searchable(),
                TextColumn::make('shift_type')
                    ->label('Loại ca')
                    ->badge()
                    ->searchable(),
                TextColumn::make('start_time')
                    ->label('Giờ vào ca')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('end_time')
                    ->label('Giờ kết thúc ca')
                    ->dateTime()
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
            ->filters([
                Filter::make('start_date')
                    ->label('Ngày')
                    ->schema([
                        DatePicker::make('date')
                            ->default(now()),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['date'], fn (Builder $query, $date): Builder => $query->whereDate('start_time', $date))),
            ])
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make()
                        ->label('Xem chi tiết')
                        ->modalHeading('Chi tiết ca lái xe')
                        ->modalDescription('Xem thông tin chi tiết về ca lái xe, bao gồm lái xe, loại ca, thời gian bắt đầu và kết thúc, cũng như các chuyến đi liên quan.')
                        ->modalWidth(Width::SevenExtraLarge)
                        ->slideOver(),
                    // EditAction::make()
                    //     ->label('Sửa ca lái')
                    //     ->modalWidth(Width::SevenExtraLarge)
                    //     ->slideOver(),
                    EndShiftAction::make(),
                    DeleteAction::make(),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
