<?php

namespace App\Filament\Resources\WaitingFeeRates;

use App\Filament\BaseResource;
use App\Filament\Resources\WaitingFeeRates\Pages\ListWaitingFeeRates;
use App\Filament\Resources\WaitingFeeRates\Schemas\WaitingFeeRateForm;
use App\Filament\Resources\WaitingFeeRates\Tables\WaitingFeeRatesTable;
use App\Models\WaitingFeeRate;
use BackedEnum;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class WaitingFeeRateResource extends BaseResource
{
    protected static ?string $model = WaitingFeeRate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?string $navigationLabel = 'Phí giờ chờ';

    protected static ?string $pluralModelLabel = 'Phí giờ chờ';

    protected static ?string $modelLabel = 'Phí giờ chờ';

    protected static string|UnitEnum|null $navigationGroup = 'Cước vận chuyển';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return WaitingFeeRateForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WaitingFeeRatesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWaitingFeeRates::route('/'),
        ];
    }
}
