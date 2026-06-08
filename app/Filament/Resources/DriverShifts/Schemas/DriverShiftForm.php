<?php

namespace App\Filament\Resources\DriverShifts\Schemas;

use App\Enums\ShiftType;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
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
                Section::make('Thông tin km')
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
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
                        Repeater::make('shiftVehicles')
                            ->relationship('shiftVehicles')
                            ->label('Danh sách công việc')
                            ->table([
                                TableColumn::make('Xe'),
                                TableColumn::make('Đơn hàng'),
                                TableColumn::make('Bắt đầu'),
                                TableColumn::make('Kết thúc'),
                                TableColumn::make('Km đầu'),
                                TableColumn::make('Km cuối'),
                            ])
                            ->schema([
                                Select::make('vehicle_id')
                                    ->label('Xe')
                                    ->relationship('vehicle', 'plate_number')
                                    ->required(),
                                Select::make('order_id')
                                    ->label('Đơn hàng')
                                    ->relationship('order', 'order_code')
                                    ->searchable(),
                                DateTimePicker::make('start_time')
                                    ->label('Bắt đầu')
                                    ->native(false),
                                DateTimePicker::make('end_time')
                                    ->label('Kết thúc')
                                    ->native(false),
                                TextInput::make('start_km')
                                    ->label('Km đầu')
                                    ->numeric(),
                                TextInput::make('end_km')
                                    ->label('Km cuối')
                                    ->numeric(),
                            ])
                            ->columns(2)
                            ->addActionLabel('Thêm xe'),
                    ]),
            ]);
    }
}
