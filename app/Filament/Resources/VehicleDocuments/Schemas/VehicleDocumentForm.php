<?php

namespace App\Filament\Resources\VehicleDocuments\Schemas;

use App\Enums\VehicleDocumentStatus;
use App\Enums\VehicleDocumentType;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Support\RawJs;

class VehicleDocumentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('vehicle_id')
                    ->label('Xe')
                    ->prefixIcon(Heroicon::OutlinedTruck)
                    ->relationship('vehicle', 'plate_number')
                    ->required(),
                Select::make('doc_type')
                    ->label('Loại giấy tờ')
                    ->prefixIcon(Heroicon::OutlinedDocumentText)
                    ->options(VehicleDocumentType::class)
                    ->required(),
                TextInput::make('certificate_number')
                    ->label('Số chứng nhận')
                    ->prefixIcon(Heroicon::OutlinedHashtag)
                    ->required(),
                TextInput::make('issued_by')
                    ->label('Nơi cấp')
                    ->prefixIcon(Heroicon::OutlinedBuildingOffice)
                    ->required(),
                DatePicker::make('issued_date')
                    ->label('Ngày cấp')
                    ->prefixIcon(Heroicon::OutlinedCalendarDays)
                    ->required(),
                DatePicker::make('expiry_date')
                    ->label('Ngày hết hạn')
                    ->prefixIcon(Heroicon::OutlinedExclamationCircle)
                    ->required(),
                TextInput::make('renewal_cost')
                    ->label('Phí gia hạn')
                    ->prefixIcon(Heroicon::OutlinedCurrencyDollar)
                    ->mask(RawJs::make('$money($input)'))
                    ->stripCharacters(',')
                    ->numeric()
                    ->prefix('$'),
                DatePicker::make('last_renewed_date')
                    ->label('Gia hạn lần cuối'),
                Textarea::make('notes')
                    ->label('Ghi chú')
                    ->columnSpanFull(),
                Select::make('status')
                    ->label('Trạng thái')
                    ->prefixIcon(Heroicon::OutlinedCheckCircle)
                    ->options(VehicleDocumentStatus::class)
                    ->default('active')
                    ->required(),
            ]);
    }
}
