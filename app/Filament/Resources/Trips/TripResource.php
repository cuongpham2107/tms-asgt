<?php

namespace App\Filament\Resources\Trips;

use App\Filament\BaseResource;
use App\Filament\Resources\Trips\Schemas\TripForm;
use App\Filament\Resources\Trips\Schemas\TripInfolist;
use App\Filament\Resources\Trips\Tables\TripsTable;
use App\Models\Trip;
use BackedEnum;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class TripResource extends BaseResource
{
    protected static ?string $recordTitleAttribute = 'trip_code';

    protected static bool $isGloballySearchable = true;

    /**
     * Tìm nhanh (⌘K / Ctrl+K).
     *
     * @return list<string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['trip_code', 'vehicle.plate_number', 'driver.name'];
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->with(['vehicle', 'driver']);
    }

    /**
     * @param  Trip  $record
     * @return array<string, string>
     */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return [
            'Xe' => $record->vehicle?->plate_number ?? '—',
            'Lái xe' => $record->driver?->name ?? '—',
        ];
    }

    /**
     * Mở dòng thời gian của chuyến.
     *
     * @param  Trip  $record
     */
    public static function getGlobalSearchResultUrl(Model $record): string
    {
        return static::getUrl('timeline', ['record' => $record]);
    }

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

    public static function form(Schema $schema): Schema
    {
        return TripForm::configure($schema);
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
