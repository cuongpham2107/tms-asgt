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
                        TextEntry::make('vehicle_plate')
                            ->label('Phương tiện')
                            ->icon(Heroicon::OutlinedTruck)
                            ->formatStateUsing(fn (DriverShift $record) => $record->orders
                                ->pluck('vehicle.plate_number')
                                ->unique()
                                ->filter()
                                ->implode(', ') ?: '-'),
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
                Section::make('Chi tiết km chạy theo từng đơn hàng')
                    ->columnSpanFull()
                    ->schema([
                        RepeatableEntry::make('orders_with_km_details')
                            ->label('Danh sách đơn hàng trong ca')
                            ->table([
                                RepeatableTableColumn::make('Mã đơn hàng'),
                                RepeatableTableColumn::make('Phương tiện'),
                                RepeatableTableColumn::make('Km nhận hàng (Pickup)'),
                                RepeatableTableColumn::make('Km giao xong (Completed)'),
                                RepeatableTableColumn::make('Km chạy có tải'),
                                RepeatableTableColumn::make('Giờ nhận hàng'),
                                RepeatableTableColumn::make('Giờ hoàn thành'),
                                RepeatableTableColumn::make('Trạng thái'),
                            ])
                            ->schema([
                                TextEntry::make('order_code')
                                    ->label('Mã đơn hàng'),
                                TextEntry::make('vehicle_plate')
                                    ->label('Phương tiện')
                                    ->icon(Heroicon::OutlinedTruck),
                                TextEntry::make('start_km')
                                    ->label('Km nhận hàng (Pickup)'),
                                TextEntry::make('end_km')
                                    ->label('Km giao xong (Completed)'),
                                TextEntry::make('loaded_km')
                                    ->label('Km chạy có tải')
                                    ->badge()
                                    ->color('success'),
                                TextEntry::make('pickup_time')
                                    ->label('Giờ nhận hàng'),
                                TextEntry::make('completed_time')
                                    ->label('Giờ hoàn thành'),
                                TextEntry::make('status')
                                    ->label('Trạng thái')
                                    ->badge()
                                    ->color(fn ($state) => match ($state) {
                                        'Completed', 'Hoàn thành' => 'success',
                                        'Cancelled', 'Đã hủy' => 'danger',
                                        default => 'warning',
                                    }),
                            ]),
                    ]),
                Section::make('Nhật ký dòng hoạt động của ca trực (Timeline)')
                    ->columnSpanFull()
                    ->schema([
                        RepeatableEntry::make('activity_timeline')
                            ->label('Dòng thời gian hoạt động')
                            ->table([
                                RepeatableTableColumn::make('Thời gian'),
                                RepeatableTableColumn::make('Hoạt động'),
                                RepeatableTableColumn::make('Phương tiện'),
                                RepeatableTableColumn::make('Tọa độ GPS'),
                            ])
                            ->schema([
                                TextEntry::make('time')
                                    ->label('Thời gian')
                                    ->dateTime('d/m/Y H:i'),
                                TextEntry::make('activity_display')
                                    ->label('Hoạt động')
                                    ->formatStateUsing(function ($record) {
                                        $type = $record['type'] ?? '';

                                        return match ($type) {
                                            'trip_start' => view('filament.resources.driver-shifts.components.timeline-trip-start', [
                                                'trip_code' => $record['trip_code'] ?? '-',
                                                'km' => $record['km'],
                                            ])->render(),
                                            'trip_end' => view('filament.resources.driver-shifts.components.timeline-trip-end', [
                                                'trip_code' => $record['trip_code'] ?? '-',
                                                'km' => $record['km'],
                                                'total_km' => $record['total_km'] ?? null,
                                            ])->render(),
                                            'order_checkpoint' => view('filament.resources.driver-shifts.components.timeline-checkpoint', [
                                                'checkpoint' => $record,
                                            ])->render(),
                                            default => '-',
                                        };
                                    })
                                    ->html(),
                                TextEntry::make('vehicle')
                                    ->label('Phương tiện')
                                    ->formatStateUsing(fn ($state) => $state ?? '-'),
                                TextEntry::make('gps')
                                    ->label('Tọa độ GPS')
                                    ->icon(Heroicon::OutlinedMapPin)
                                    ->formatStateUsing(fn ($state) => $state ?? '-'),
                            ]),
                    ]),
            ]);
    }
}
