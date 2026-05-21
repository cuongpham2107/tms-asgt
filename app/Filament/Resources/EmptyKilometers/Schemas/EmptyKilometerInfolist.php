<?php

namespace App\Filament\Resources\EmptyKilometers\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class EmptyKilometerInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Thông tin km không hàng')
                    ->icon(Heroicon::OutlinedMap)
                    ->columnSpanFull()
                    ->columns(3)
                    ->schema([
                        TextEntry::make('driver.name')
                            ->label('Lái xe')
                            ->icon(Heroicon::OutlinedUser),
                        TextEntry::make('vehicle.plate_number')
                            ->label('Biển số xe')
                            ->icon(Heroicon::OutlinedTruck),
                        TextEntry::make('distance')
                            ->label('Km không hàng')
                            ->icon(Heroicon::OutlinedSparkles)
                            ->numeric(decimalPlaces: 1)
                            ->weight('bold')
                            ->color('warning'),
                    ]),

                Section::make('Điểm bắt đầu')
                    ->icon(Heroicon::OutlinedPlay)
                    ->columnSpanFull()
                    ->columns(3)
                    ->schema([
                        TextEntry::make('start_km')
                            ->label('Km đồng hồ')
                            ->icon(Heroicon::OutlinedSparkles)
                            ->numeric(decimalPlaces: 1),
                        TextEntry::make('started_at')
                            ->label('Thời điểm')
                            ->icon(Heroicon::OutlinedClock)
                            ->dateTime('H:i:s d/m/Y'),
                        TextEntry::make('start_gps_lat')
                            ->label('GPS')
                            ->icon(Heroicon::OutlinedMapPin)
                            ->formatStateUsing(fn ($record) => $record->start_gps_lat
                                ? "{$record->start_gps_lat}, {$record->start_gps_lng}"
                                : '-'),
                    ]),

                Section::make('Điểm kết thúc')
                    ->icon(Heroicon::OutlinedStop)
                    ->columnSpanFull()
                    ->columns(3)
                    ->schema([
                        TextEntry::make('end_km')
                            ->label('Km đồng hồ')
                            ->icon(Heroicon::OutlinedSparkles)
                            ->numeric(decimalPlaces: 1),
                        TextEntry::make('ended_at')
                            ->label('Thời điểm')
                            ->icon(Heroicon::OutlinedClock)
                            ->dateTime('H:i:s d/m/Y'),
                        TextEntry::make('end_gps_lat')
                            ->label('GPS')
                            ->icon(Heroicon::OutlinedMapPin)
                            ->formatStateUsing(fn ($record) => $record->end_gps_lat
                                ? "{$record->end_gps_lat}, {$record->end_gps_lng}"
                                : '-'),
                    ]),

                Section::make('Thông tin thêm')
                    ->icon(Heroicon::OutlinedInformationCircle)
                    ->columnSpanFull()
                    ->columns(1)
                    ->schema([
                        TextEntry::make('note')
                            ->label('Ghi chú')
                            ->placeholder('Không có ghi chú'),
                    ]),
            ]);
    }
}
