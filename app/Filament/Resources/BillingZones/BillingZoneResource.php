<?php

namespace App\Filament\Resources\BillingZones;

use App\Filament\BaseResource;
use App\Filament\Resources\BillingZones\Pages\ListBillingZones;
use App\Filament\Resources\BillingZones\Schemas\BillingZoneForm;
use App\Filament\Resources\BillingZones\Tables\BillingZonesTable;
use App\Models\BillingZone;
use BackedEnum;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class BillingZoneResource extends BaseResource
{
    protected static ?string $model = BillingZone::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static ?string $navigationLabel = 'Khu vực tính cước';

    protected static ?string $pluralModelLabel = 'Khu vực tính cước';

    protected static ?string $modelLabel = 'Khu vực tính cước';

    protected static string|UnitEnum|null $navigationGroup = 'Cước vận chuyển';

    protected static ?int $navigationSort = 0;

    public static function form(Schema $schema): Schema
    {
        return BillingZoneForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BillingZonesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBillingZones::route('/'),
        ];
    }
}
