<?php

namespace App\Filament\Resources\EmptyKilometers;

use App\Filament\BaseResource;
use App\Filament\Resources\EmptyKilometers\Pages\ListEmptyKilometers;
use App\Filament\Resources\EmptyKilometers\Schemas\EmptyKilometerInfolist;
use App\Filament\Resources\EmptyKilometers\Tables\EmptyKilometersTable;
use App\Models\EmptyKilometer;
use BackedEnum;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class EmptyKilometerResource extends BaseResource
{
    protected static ?string $model = EmptyKilometer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMap;

    protected static ?string $recordTitleAttribute = 'id';

    protected static ?string $navigationLabel = 'Km không hàng';

    protected static ?string $pluralModelLabel = 'Km không hàng';

    protected static string|UnitEnum|null $navigationGroup = 'Hoạt động';

    protected static ?int $navigationSort = 4;

    public static function infolist(Schema $schema): Schema
    {
        return EmptyKilometerInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return EmptyKilometersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEmptyKilometers::route('/'),
        ];
    }
}
