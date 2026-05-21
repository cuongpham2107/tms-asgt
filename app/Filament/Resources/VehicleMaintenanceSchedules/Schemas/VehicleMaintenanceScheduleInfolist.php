<?php

namespace App\Filament\Resources\VehicleMaintenanceSchedules\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class VehicleMaintenanceScheduleInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Thông tin lịch bảo dưỡng')
                    ->schema([
                        TextEntry::make('vehicle.plate_number')
                            ->label('Biển số xe'),
                        TextEntry::make('name')
                            ->label('Tên lịch'),
                        TextEntry::make('job_type')
                            ->label('Loại bảo dưỡng')
                            ->badge()
                            ->formatStateUsing(fn ($state) => $state->getLabel() ?? $state),
                        TextEntry::make('priority')
                            ->label('Mức ưu tiên')
                            ->badge()
                            ->formatStateUsing(fn ($state) => $state->getLabel() ?? $state),
                        TextEntry::make('trigger_type')
                            ->label('Cách kịch hoạt')
                            ->badge()
                            ->formatStateUsing(fn ($state) => $state->getLabel() ?? $state),
                        TextEntry::make('km_interval')
                            ->label('Khoảng Km')
                            ->numeric(),
                        TextEntry::make('km_current')
                            ->label('Km hiện tại')
                            ->numeric(),
                        TextEntry::make('km_next_trigger')
                            ->label('Km kế tiếp kịch hoạt')
                            ->numeric(),
                        TextEntry::make('km_remind_before')
                            ->label('Cảnh báo trước (Km)')
                            ->numeric(),
                        TextEntry::make('date_interval_days')
                            ->label('Khoảng ngày')
                            ->numeric(),
                        TextEntry::make('last_service_date')
                            ->label('Lần bảo dưỡng cuối')
                            ->date(),
                        TextEntry::make('date_next_trigger')
                            ->label('Ngày kế tiếp kịch hoạt')
                            ->date(),
                        TextEntry::make('description')
                            ->label('Mô tả')
                            ->columnSpanFull(),
                        TextEntry::make('notes')
                            ->label('Ghi chú')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }
}
