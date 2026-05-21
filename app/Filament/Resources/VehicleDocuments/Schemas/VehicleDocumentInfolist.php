<?php

namespace App\Filament\Resources\VehicleDocuments\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class VehicleDocumentInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Thông tin giấy tờ')
                    ->schema([
                        TextEntry::make('vehicle.plate_number')
                            ->label('Biển số xe'),
                        TextEntry::make('doc_type')
                            ->label('Loại giấy tờ')
                            ->badge()

                            ->formatStateUsing(fn ($state) => $state->getLabel() ?? $state),
                        TextEntry::make('certificate_number')
                            ->label('Số chứng chỉ'),
                        TextEntry::make('issued_by')
                            ->label('Cơ quan cấp'),
                        TextEntry::make('issued_date')
                            ->label('Ngày cấp')
                            ->date(),
                        TextEntry::make('expiry_date')
                            ->label('Ngày hết hạn')
                            ->date()
                            ->color(fn ($record) => $record->expiry_date?->isPast() ? 'danger' : 'success'),
                        TextEntry::make('renewal_cost')
                            ->label('Chi phí gia hạn')
                            ->money('VND'),
                        TextEntry::make('last_renewed_date')
                            ->label('Lần gia hạn cuối')
                            ->date(),
                        TextEntry::make('status')
                            ->label('Trạng thái')
                            ->badge()
                            ->formatStateUsing(fn ($state) => $state->getLabel() ?? $state),
                        TextEntry::make('notes')
                            ->label('Ghi chú')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }
}
