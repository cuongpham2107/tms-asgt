<?php

namespace App\Filament\Resources\DriverShifts\Schemas;

use App\Enums\ShiftType;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class DriverShiftForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Thông tin ca')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        Select::make('driver_id')
                            ->label('Lái xe')
                            ->prefixIcon(Heroicon::OutlinedUser)
                            ->relationship('driver', 'name')
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
                        DateTimePicker::make('end_time')
                            ->label('Kết thúc ca')
                            ->prefixIcon(Heroicon::OutlinedCalendarDays),
                    ]),
                Section::make('Thông tin xe và km')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        Select::make('vehicle_id')
                            ->label('Xe')
                            ->prefixIcon(Heroicon::OutlinedTruck)
                            ->relationship('vehicle', 'plate_number')
                            ->required()
                            ->columnSpanFull(),
                        TextInput::make('start_km')
                            ->label('Km bắt đầu')
                            ->prefixIcon(Heroicon::OutlinedAdjustmentsVertical)
                            ->numeric(),
                        TextInput::make('end_km')
                            ->label('Km kết thúc')
                            ->prefixIcon(Heroicon::OutlinedAdjustmentsVertical)
                            ->numeric(),
                        TextInput::make('start_gps_lat')
                            ->label('GPS bắt đầu')
                            ->prefixIcon(Heroicon::OutlinedMapPin)
                            ->numeric(),
                        TextInput::make('start_gps_lng')
                            ->label('')
                            ->numeric(),
                        TextInput::make('end_gps_lat')
                            ->label('GPS kết thúc')
                            ->numeric(),
                        TextInput::make('end_gps_lng')
                            ->label('')
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
                    ]),
                Section::make('Các xe đã sử dụng trong ca')
                    ->columnSpanFull()
                    ->schema([
                        RepeatableEntry::make('shiftVehicles')
                            ->label('')
                            ->schema([
                                TextEntry::make('vehicle.plate_number')
                                    ->label('Xe')
                                    ->icon(Heroicon::OutlinedTruck),
                                TextEntry::make('order_id')
                                    ->label('Đơn hàng'),
                                TextEntry::make('start_time')
                                    ->label('Bắt đầu')
                                    ->dateTime(),
                                TextEntry::make('end_time')
                                    ->label('Kết thúc')
                                    ->dateTime(),
                                TextEntry::make('start_km')
                                    ->label('Km đầu')
                                    ->numeric(),
                                TextEntry::make('end_km')
                                    ->label('Km cuối')
                                    ->numeric(),
                                TextEntry::make('calculated_km')
                                    ->label('Km')
                                    ->state(fn ($record) => $record->end_km && $record->start_km
                                        ? number_format($record->end_km - $record->start_km, 1)
                                        : '-'),
                            ])
                            ->columns(4),
                    ]),
            ]);
    }
}
