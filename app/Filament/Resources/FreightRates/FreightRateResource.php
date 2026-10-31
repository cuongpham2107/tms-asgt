<?php

namespace App\Filament\Resources\FreightRates;

use App\Filament\BaseResource;
use App\Filament\Resources\FreightRates\Pages\ListFreightRates;
use App\Filament\Resources\FreightRates\Schemas\FreightRateForm;
use App\Filament\Resources\FreightRates\Tables\FreightRatesTable;
use App\Models\FreightRate;
use BackedEnum;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class FreightRateResource extends BaseResource
{
    protected static ?string $model = FreightRate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyDollar;

    protected static ?string $navigationLabel = 'Đơn giá vận chuyển';

    protected static ?string $pluralModelLabel = 'Đơn giá vận chuyển';

    protected static ?string $modelLabel = 'Đơn giá';

    protected static string|UnitEnum|null $navigationGroup = 'Cước vận chuyển';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return FreightRateForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FreightRatesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFreightRates::route('/'),
        ];
    }
}
