<?php

namespace App\Filament\Resources\VehicleMaintenanceSchedules\Schemas;

use App\Enums\MaintenanceJobType;
use App\Enums\MaintenanceTriggerType;
use App\Enums\Priority;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Support\RawJs;

class VehicleMaintenanceScheduleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Tên lịch')
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
                Select::make('trigger_type')
                    ->label('Kích hoạt theo')
                    ->prefixIcon(Heroicon::OutlinedBolt)
                    ->options(MaintenanceTriggerType::class)
                    ->required(),
                TextInput::make('km_interval')
                    ->label('Chu kỳ km')
                    ->prefixIcon(Heroicon::OutlinedAdjustmentsVertical)
                    ->mask(RawJs::make('$money($input)'))
                    ->stripCharacters(',')
                    ->numeric(),
                TextInput::make('km_current')
                    ->label('Km hiện tại')
                    ->mask(RawJs::make('$money($input)'))
                    ->stripCharacters(',')
                    ->numeric(),
                TextInput::make('km_next_trigger')
                    ->label('Km kích hoạt tiếp')
                    ->mask(RawJs::make('$money($input)'))
                    ->stripCharacters(',')
                    ->numeric(),
                TextInput::make('km_remind_before')
                    ->label('Nhắc trước (km)')
                    ->mask(RawJs::make('$money($input)'))
                    ->stripCharacters(',')
                    ->numeric()
                    ->default(500),
                TextInput::make('date_interval_days')
                    ->label('Chu kỳ ngày')
                    ->prefixIcon(Heroicon::OutlinedCalendarDays)
                    ->mask(RawJs::make('$money($input)'))
                    ->stripCharacters(',')
                    ->numeric(),
                DatePicker::make('last_service_date')
                    ->label('Bảo dưỡng lần cuối'),
                DatePicker::make('date_next_trigger')
                    ->label('Kích hoạt tiếp theo'),
                TextInput::make('date_remind_before_days')
                    ->label('Nhắc trước (ngày)')
                    ->prefixIcon(Heroicon::OutlinedBellAlert)
                    ->mask(RawJs::make('$money($input)'))
                    ->stripCharacters(',')
                    ->numeric()
                    ->default(14),
                TextInput::make('estimated_cost')
                    ->label('Dự toán')
                    ->prefixIcon(Heroicon::OutlinedCurrencyDollar)
                    ->mask(RawJs::make('$money($input)'))
                    ->stripCharacters(',')
                    ->numeric(),
                TextInput::make('garage')
                    ->label('Garage')
                    ->prefixIcon(Heroicon::OutlinedHomeModern),
                Toggle::make('is_mandatory')
                    ->label('Bắt buộc')
                    ->required(),
                Toggle::make('auto_create_job')
                    ->label('Tự động tạo job')
                    ->required(),
                Toggle::make('is_active')
                    ->label('Kích hoạt')
                    ->required(),
                TextInput::make('alert_status')
                    ->label('Cảnh báo')
                    ->required()
                    ->default('ok'),
                DateTimePicker::make('last_triggered_at')
                    ->label('Kích hoạt lần cuối'),
            ]);
    }
}
