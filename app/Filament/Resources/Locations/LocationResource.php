<?php

namespace App\Filament\Resources\Locations;

use App\Filament\BaseResource;
use App\Filament\Resources\Locations\Pages\CreateLocation;
use App\Filament\Resources\Locations\Pages\EditLocation;
use App\Filament\Resources\Locations\Pages\ListLocations;
use App\Filament\Resources\Locations\Schemas\LocationForm;
use App\Filament\Resources\Locations\Tables\LocationsTable;
use App\Models\Location;
use BackedEnum;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class LocationResource extends BaseResource
{
    protected static bool $isGloballySearchable = true;

    /**
     * Tìm nhanh (⌘K / Ctrl+K).
     *
     * @return list<string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['code', 'name'];
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->with([]);
    }

    /**
     * @param  Location  $record
     * @return array<string, string>
     */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return [
            'Mã' => $record->code,
        ];
    }

    /**
     * Bảng sửa/xem bằng modal nên mở danh sách đã lọc sẵn theo bản ghi.
     *
     * @param  Location  $record
     */
    public static function getGlobalSearchResultUrl(Model $record): string
    {
        return static::getUrl('index', ['search' => $record->code]);
    }

    protected static ?string $model = Location::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $navigationLabel = 'Địa điểm';

    protected static ?string $pluralModelLabel = 'Địa điểm';

    protected static string|UnitEnum|null $navigationGroup = 'Quản lý';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return LocationForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return LocationsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLocations::route('/'),
            // 'create' => CreateLocation::route('/create'),
            // 'edit' => EditLocation::route('/{record}/edit'),
        ];
    }
}
