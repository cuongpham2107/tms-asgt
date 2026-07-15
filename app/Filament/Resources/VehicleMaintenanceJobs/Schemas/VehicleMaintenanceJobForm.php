<?php

namespace App\Filament\Resources\VehicleMaintenanceJobs\Schemas;

use App\Enums\MaintenanceJobStatus;
use App\Enums\MaintenanceJobType;
use App\Enums\Priority;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Support\RawJs;

class VehicleMaintenanceJobForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('title')
                    ->label('Tiêu đề')
                    ->prefixIcon(Heroicon::OutlinedPencilSquare)
                    ->required(),
                Select::make('vehicle_id')
                    ->label('Xe')
                    ->prefixIcon(Heroicon::OutlinedTruck)
                    ->relationship('vehicle', 'plate_number')
                    ->required(),
                Select::make('job_type')
                    ->label('Loại công việc')
                    ->prefixIcon(Heroicon::OutlinedWrench)
                    ->options(MaintenanceJobType::class)
                    ->required(),
                Select::make('priority')
                    ->label('Ưu tiên')
                    ->prefixIcon(Heroicon::OutlinedFlag)
                    ->options(Priority::class)
                    ->default('medium')
                    ->required(),
                Textarea::make('description')
                    ->label('Mô tả')
                    ->columnSpanFull(),
                DatePicker::make('planned_date')
                    ->label('Ngày dự kiến')
                    ->prefixIcon(Heroicon::OutlinedCalendarDays)
                    ->required(),
                TextInput::make('remind_before_days')
                    ->label('Nhắc trước (ngày)')
                    ->prefixIcon(Heroicon::OutlinedBellAlert)
                    ->required()
                    ->mask(RawJs::make('$money($input)'))
                    ->stripCharacters(',')
                    ->numeric()
                    ->default(3),
                TextInput::make('estimated_cost')
                    ->label('Dự toán')
                    ->prefixIcon(Heroicon::OutlinedCurrencyDollar)
                    ->mask(RawJs::make('$money($input)'))
                    ->stripCharacters(',')
                    ->numeric(),
                TextInput::make('actual_cost')
                    ->label('Thực tế')
                    ->prefixIcon(Heroicon::OutlinedCurrencyDollar)
                    ->mask(RawJs::make('$money($input)'))
                    ->stripCharacters(',')
                    ->numeric(),
                TextInput::make('garage')
                    ->label('Garage')
                    ->prefixIcon(Heroicon::OutlinedHomeModern),
                TextInput::make('technician')
                    ->label('Kỹ thuật viên')
                    ->prefixIcon(Heroicon::OutlinedUser),
                TextInput::make('km_at_service')
                    ->label('Km lúc bảo dưỡng')
                    ->prefixIcon(Heroicon::OutlinedAdjustmentsVertical)
                    ->mask(RawJs::make('$money($input)'))
                    ->stripCharacters(',')
                    ->numeric(),
                DatePicker::make('next_service_date')
                    ->label('Bảo dưỡng tiếp theo')
                    ->prefixIcon(Heroicon::OutlinedCalendarDays),
                Textarea::make('notes')
                    ->label('Ghi chú')
                    ->columnSpanFull(),
                Select::make('status')
                    ->label('Trạng thái')
                    ->prefixIcon(Heroicon::OutlinedCheckCircle)
                    ->options(MaintenanceJobStatus::class)
                    ->default('pending')
                    ->required(),
                DateTimePicker::make('completed_at')
                    ->label('Hoàn thành lúc'),
                Select::make('schedule_id')
                    ->label('Lịch bảo dưỡng')
                    ->relationship('schedule', 'name'),
            ]);
    }
}
