<?php

namespace App\Filament\Resources\DriverShifts\Schemas;

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
                Section::make('Thông tin ca trực')
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('driver.name')
                            ->label('Lái xe')
                            ->icon(Heroicon::OutlinedUser),
                        TextEntry::make('vehicle.plate_number')
                            ->label('Biển số xe')
                            ->icon(Heroicon::OutlinedTruck),
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
                        TextEntry::make('start_km')
                            ->label('Km vào ca')
                            ->icon(Heroicon::OutlinedSparkles)
                            ->numeric(),
                        TextEntry::make('end_km')
                            ->label('Km kế thúc ca')
                            ->icon(Heroicon::OutlinedSparkles)
                            ->numeric(),
                        TextEntry::make('start_gps_lat')
                            ->label('GPS vào ca')
                            ->icon(Heroicon::OutlinedMapPin)
                            ->formatStateUsing(fn ($record) => $record->start_gps_lat ? "{$record->start_gps_lat}, {$record->start_gps_lng}" : '-'),
                        TextEntry::make('end_gps_lat')
                            ->label('GPS kế thúc ca')
                            ->icon(Heroicon::OutlinedMapPin)
                            ->formatStateUsing(fn ($record) => $record->end_gps_lat ? "{$record->end_gps_lat}, {$record->end_gps_lng}" : '-'),
                    ])
                    ->columns(2),
            ]);
    }
}
