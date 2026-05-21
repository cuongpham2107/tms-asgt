<?php

namespace App\Filament\Resources\VehicleMaintenanceSchedules\Tables;

use App\Filament\BaseTable;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class VehicleMaintenanceSchedulesTable extends BaseTable
{
    public static function configure(Table $table): Table
    {
        return parent::applyDefaults($table)
            ->modifyQueryUsing(fn (Builder $query) => $query->with('vehicle'))
            ->columns([
                TextColumn::make('vehicle.id')
                    ->label('Xe')
                    ->searchable(),
                TextColumn::make('job_type')
                    ->label('Loại bảo dưỡng')
                    ->badge()
                    ->searchable(),
                TextColumn::make('priority')
                    ->label('Ưu tiên')
                    ->badge()
                    ->searchable(),
                TextColumn::make('name')
                    ->label('Tên lịch')
                    ->searchable(),
                TextColumn::make('trigger_type')
                    ->label('Kiểu kích hoạt')
                    ->badge()
                    ->searchable(),
                TextColumn::make('km_interval')
                    ->label('Khoảng km')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('km_current')
                    ->label('Km hiện tại')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('km_next_trigger')
                    ->label('Km kích hoạt tiếp')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('km_remind_before')
                    ->label('Nhắc trước (km)')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('date_interval_days')
                    ->label('Khoảng ngày')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('last_service_date')
                    ->label('Bảo dưỡng gần nhất')
                    ->date()
                    ->sortable(),
                TextColumn::make('date_next_trigger')
                    ->label('Ngày kích hoạt tiếp')
                    ->date()
                    ->sortable(),
                TextColumn::make('date_remind_before_days')
                    ->label('Nhắc trước (ngày)')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('estimated_cost')
                    ->label('Chi phí dự kiến')
                    ->money()
                    ->sortable(),
                TextColumn::make('garage')
                    ->label('Garage')
                    ->searchable(),
                IconColumn::make('is_mandatory')
                    ->label('Bắt buộc')
                    ->boolean(),
                IconColumn::make('auto_create_job')
                    ->label('Tự tạo job')
                    ->boolean(),
                IconColumn::make('is_active')
                    ->label('Hoạt động')
                    ->boolean(),
                TextColumn::make('alert_status')
                    ->label('Trạng thái cảnh báo')
                    ->searchable(),
                TextColumn::make('last_triggered_at')
                    ->label('Kích hoạt gần nhất')
                    ->dateTime()
                    ->sortable(),
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
