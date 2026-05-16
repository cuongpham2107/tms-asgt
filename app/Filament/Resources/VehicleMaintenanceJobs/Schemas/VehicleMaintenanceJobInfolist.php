<?php

namespace App\Filament\Resources\VehicleMaintenanceJobs\Schemas;

use App\Enums\MaintenanceJobStatus;
use App\Enums\MaintenanceJobType;
use App\Enums\Priority;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class VehicleMaintenanceJobInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Thông tin bảo dưỡng')
                    ->schema([
                        TextEntry::make('vehicle.plate_number')
                            ->label('Biển số xe'),
                        TextEntry::make('title')
                            ->label('Tên công việc'),
                        TextEntry::make('job_type')
                            ->label('Loại bảo dưỡng')
                            ->badge()
                            ->color(fn ($state) => $state instanceof MaintenanceJobType ? $state->getColor() : match ($state) {
                                'periodic_maintenance' => 'blue',
                                'repair' => 'orange',
                                'inspection' => 'amber',
                                'registration' => 'purple',
                                'insurance' => 'green',
                                default => 'gray',
                            })
                            ->formatStateUsing(fn ($state) => $state instanceof MaintenanceJobType ? $state->getLabel() : $state),
                        TextEntry::make('priority')
                            ->label('Mức ưu tiên')
                            ->badge()
                            ->color(fn ($state) => $state instanceof Priority ? $state->getColor() : match ($state) {
                                'urgent' => 'danger',
                                'high' => 'warning',
                                'medium' => 'info',
                                'low' => 'gray',
                                default => 'gray',
                            })
                            ->formatStateUsing(fn ($state) => $state instanceof Priority ? $state->getLabel() : $state),
                        TextEntry::make('status')
                            ->label('Trạng thái')
                            ->badge()
                            ->color(fn ($state) => $state instanceof MaintenanceJobStatus ? $state->getColor() : match ($state) {
                                'pending' => 'warning',
                                'in_progress' => 'info',
                                'completed' => 'success',
                                'cancelled' => 'danger',
                                'overdue' => 'danger',
                                default => 'gray',
                            })
                            ->formatStateUsing(fn ($state) => $state instanceof MaintenanceJobStatus ? $state->getLabel() : $state),
                        TextEntry::make('description')
                            ->label('Mô tả')
                            ->columnSpanFull(),
                        TextEntry::make('planned_date')
                            ->label('Ngày dự kiến')
                            ->date(),
                        TextEntry::make('completed_at')
                            ->label('Hoàn thành lúc')
                            ->dateTime(),
                        TextEntry::make('estimated_cost')
                            ->label('Chi phí dự kiến')
                            ->money('VND'),
                        TextEntry::make('actual_cost')
                            ->label('Chi phí thực tế')
                            ->money('VND'),
                        TextEntry::make('garage')
                            ->label('Xưởng sửa chữa'),
                        TextEntry::make('technician')
                            ->label('Thợ sửa chữa'),
                        TextEntry::make('km_at_service')
                            ->label('Km tại sửa chữa')
                            ->numeric(),
                        TextEntry::make('next_service_date')
                            ->label('Lần bảo dưỡng tiếp theo')
                            ->date(),
                        TextEntry::make('notes')
                            ->label('Ghi chú')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }
}
