<?php

namespace App\Filament\Resources\DriverShifts\Schemas;

use App\Models\DriverShift;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn as RepeatableTableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class DriverShiftInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Thông tin ca')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('driver.name')
                            ->label('Lái xe')
                            ->icon(Heroicon::OutlinedUser),
                        TextEntry::make('shift_type')
                            ->label('Loại ca')
                            ->icon(Heroicon::OutlinedClock)
                            ->badge()
                            ->color(fn ($record) => $record->shift_type?->getColor() ?? 'gray')
                            ->formatStateUsing(fn ($state) => $state?->getLabel() ?? $state),
                        TextEntry::make('start_time')
                            ->label('Giờ vào ca')
                            ->icon(Heroicon::OutlinedArrowRightStartOnRectangle)
                            ->dateTime(),
                        TextEntry::make('end_time')
                            ->label('Giờ kế thúc ca')
                            ->icon(Heroicon::OutlinedArrowRightEndOnRectangle)
                            ->dateTime(),
                        TextEntry::make('start_gps')
                            ->label('GPS vào ca')
                            ->icon(Heroicon::OutlinedMapPin)
                            ->formatStateUsing(fn (DriverShift $record) => $record->start_gps_lat && $record->start_gps_lng
                                ? "{$record->start_gps_lat}, {$record->start_gps_lng}"
                                : '-'),
                        TextEntry::make('end_gps')
                            ->label('GPS kế thúc ca')
                            ->icon(Heroicon::OutlinedMapPin)
                            ->formatStateUsing(fn (DriverShift $record) => $record->end_gps_lat && $record->end_gps_lng
                                ? "{$record->end_gps_lat}, {$record->end_gps_lng}"
                                : '-'),
                    ]),
                Section::make('Thông tin km')
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('total_km')
                            ->label('Tổng km')
                            ->icon(Heroicon::OutlinedAdjustmentsVertical)
                            ->numeric(),
                        TextEntry::make('total_km_loaded')
                            ->label('Km có tải')
                            ->numeric(),
                        TextEntry::make('total_km_empty')
                            ->label('Km rỗng')
                            ->numeric(),
                    ]),
                Section::make('Các xe đã sử dụng trong ca')
                    ->columnSpanFull()
                    ->schema([
                        RepeatableEntry::make('shiftVehicles')
                            ->label('Danh sách công việc')
                            ->table([
                                RepeatableTableColumn::make('Xe'),
                                RepeatableTableColumn::make('Đơn hàng'),
                                RepeatableTableColumn::make('Bắt đầu'),
                                RepeatableTableColumn::make('Kết thúc'),
                                RepeatableTableColumn::make('Km đầu'),
                                RepeatableTableColumn::make('Km cuối'),
                            ])
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
                            ]),
                    ]),
            ]);
    }
}
