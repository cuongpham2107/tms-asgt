<?php

namespace App\Filament\Resources\Areas;

use App\Filament\BaseResource;
use App\Filament\Resources\Areas\Pages\CreateArea;
use App\Filament\Resources\Areas\Pages\EditArea;
use App\Filament\Resources\Areas\Pages\ListAreas;
use App\Filament\Resources\Areas\Schemas\AreaForm;
use App\Filament\Resources\Areas\Tables\AreasTable;
use App\Models\Area;
use BackedEnum;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class AreaResource extends BaseResource
{
    protected static ?string $model = Area::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::Map;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $navigationLabel = 'Khu vực';

    protected static ?string $pluralModelLabel = 'Khu vực';

    protected static ?string $modelLabel = 'Khu vực';

    protected static string|UnitEnum|null $navigationGroup = 'Cấu hình';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return AreaForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AreasTable::configure($table);
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
            'index' => ListAreas::route('/'),
            // 'create' => CreateArea::route('/create'),
            // 'edit' => EditArea::route('/{record}/edit'),
        ];
    }
}
