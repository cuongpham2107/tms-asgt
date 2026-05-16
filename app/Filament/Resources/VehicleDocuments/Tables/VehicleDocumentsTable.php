<?php

namespace App\Filament\Resources\VehicleDocuments\Tables;

use App\Filament\BaseTable;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class VehicleDocumentsTable extends BaseTable
{
    public static function configure(Table $table): Table
    {
        return parent::applyDefaults($table)
            ->modifyQueryUsing(fn (Builder $query) => $query->with('vehicle'))
            ->columns([
                TextColumn::make('vehicle.id')
                    ->label('Xe')
                    ->searchable(),
                TextColumn::make('doc_type')
                    ->label('Loại giấy tờ')
                    ->badge()
                    ->searchable(),
                TextColumn::make('certificate_number')
                    ->label('Số chứng từ')
                    ->searchable(),
                TextColumn::make('issued_by')
                    ->label('Nơi cấp')
                    ->searchable(),
                TextColumn::make('issued_date')
                    ->label('Ngày cấp')
                    ->date()
                    ->sortable(),
                TextColumn::make('expiry_date')
                    ->label('Ngày hết hạn')
                    ->date()
                    ->sortable(),
                TextColumn::make('renewal_cost')
                    ->label('Chi phí gia hạn')
                    ->money()
                    ->sortable(),
                TextColumn::make('last_renewed_date')
                    ->label('Gia hạn gần nhất')
                    ->date()
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Trạng thái')
                    ->badge()
                    ->searchable(),
                TextColumn::make('created_by')
                    ->label('Người tạo')
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
                TextColumn::make('deleted_at')
                    ->label('Đã xóa lúc')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
