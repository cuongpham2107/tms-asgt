<?php

namespace App\Filament\Resources\VehicleMaintenanceJobs\Tables;

use App\Filament\BaseTable;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class VehicleMaintenanceJobsTable extends BaseTable
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
                TextColumn::make('title')
                    ->label('Tiêu đề')
                    ->searchable(),
                TextColumn::make('planned_date')
                    ->label('Ngày dự kiến')
                    ->date()
                    ->sortable(),
                TextColumn::make('remind_before_days')
                    ->label('Nhắc trước (ngày)')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('estimated_cost')
                    ->label('Chi phí dự kiến')
                    ->money()
                    ->sortable(),
                TextColumn::make('actual_cost')
                    ->label('Chi phí thực tế')
                    ->money()
                    ->sortable(),
                TextColumn::make('garage')
                    ->label('Garage')
                    ->searchable(),
                TextColumn::make('technician')
                    ->label('Kỹ thuật viên')
                    ->searchable(),
                TextColumn::make('km_at_service')
                    ->label('Km bảo dưỡng')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('next_service_date')
                    ->label('Ngày bảo dưỡng tiếp')
                    ->date()
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Trạng thái')
                    ->badge()
                    ->searchable(),
                TextColumn::make('completed_at')
                    ->label('Hoàn tất lúc')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('schedule.name')
                    ->label('Lịch bảo dưỡng')
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
