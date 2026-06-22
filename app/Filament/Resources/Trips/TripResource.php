<?php

namespace App\Filament\Resources\Trips;

use App\Filament\BaseResource;
use App\Filament\Resources\Trips\Schemas\TripInfolist;
use App\Filament\Resources\Trips\Tables\TripsTable;
use App\Models\Trip;
use BackedEnum;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class TripResource extends BaseResource
{
    protected static ?string $model = Trip::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMap;

    protected static ?string $navigationLabel = 'Chuyến đi';

    protected static ?string $pluralModelLabel = 'Chuyến đi';

    protected static ?string $modelLabel = 'Chuyến đi';

    protected static string|UnitEnum|null $navigationGroup = 'Vận hành';

    protected static ?int $navigationSort = 2;

    public static function table(Table $table): Table
    {
        return TripsTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return TripInfolist::configure($schema);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTrips::route('/'),
            'timeline' => Pages\ViewTripTimeline::route('/{record}/timeline'),
        ];
    }
}
