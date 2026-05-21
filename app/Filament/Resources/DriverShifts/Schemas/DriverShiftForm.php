<?php

namespace App\Filament\Resources\DriverShifts\Schemas;

use App\Enums\ShiftType;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class DriverShiftForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('driver_id')
                    ->label('Lái xe')
                    ->prefixIcon(Heroicon::OutlinedUser)
                    ->relationship('driver', 'name')
                    ->required(),
                Select::make('vehicle_id')
                    ->label('Xe')
                    ->prefixIcon(Heroicon::OutlinedTruck)
                    ->relationship('vehicle', 'plate_number')
                    ->required(),
                Select::make('shift_type')
                    ->label('Loại ca')
                    ->prefixIcon(Heroicon::OutlinedClock)
                    ->options(ShiftType::class)
                    ->required(),
                DateTimePicker::make('start_time')
                    ->label('Bắt đầu ca')
                    ->prefixIcon(Heroicon::OutlinedCalendarDays)
                    ->required(),
                TextInput::make('start_km')
                    ->label('Km bắt đầu')
                    ->prefixIcon(Heroicon::OutlinedAdjustmentsVertical)
                    ->numeric(),
                TextInput::make('start_gps_lat')
                    ->label('GPS lat bắt đầu')
                    ->prefixIcon(Heroicon::OutlinedMapPin)
                    ->numeric(),
                TextInput::make('start_gps_lng')
                    ->label('GPS lng bắt đầu')
                    ->prefixIcon(Heroicon::OutlinedMapPin)
                    ->numeric(),
                DateTimePicker::make('end_time')
                    ->label('Kết thúc ca')
                    ->prefixIcon(Heroicon::OutlinedCalendarDays),
                TextInput::make('end_km')
                    ->label('Km kết thúc')
                    ->prefixIcon(Heroicon::OutlinedAdjustmentsVertical)
                    ->numeric(),
                TextInput::make('end_gps_lat')
                    ->label('GPS lat kết thúc')
                    ->numeric(),
                TextInput::make('end_gps_lng')
                    ->label('GPS lng kết thúc')
                    ->numeric(),
                TextInput::make('total_km')
                    ->label('Tổng km')
                    ->prefixIcon(Heroicon::OutlinedAdjustmentsVertical)
                    ->numeric(),
                TextInput::make('total_km_loaded')
                    ->label('Km có tải')
                    ->numeric(),
                TextInput::make('total_km_empty')
                    ->label('Km rỗng')
                    ->numeric(),
            ]);
    }
}
