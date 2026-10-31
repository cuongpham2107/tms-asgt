<?php

namespace App\Filament\Resources\FeeTypes;

use App\Filament\BaseResource;
use App\Filament\Resources\FeeTypes\Pages\ListFeeTypes;
use App\Filament\Resources\FeeTypes\Schemas\FeeTypeForm;
use App\Filament\Resources\FeeTypes\Tables\FeeTypesTable;
use App\Models\FeeType;
use BackedEnum;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class FeeTypeResource extends BaseResource
{
    protected static ?string $model = FeeType::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedListBullet;

    protected static ?string $navigationLabel = 'Danh mục phí';

    protected static ?string $pluralModelLabel = 'Danh mục phí';

    protected static ?string $modelLabel = 'Loại phí';

    protected static string|UnitEnum|null $navigationGroup = 'Cước vận chuyển';

    protected static ?int $navigationSort = 4;

    public static function form(Schema $schema): Schema
    {
        return FeeTypeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FeeTypesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFeeTypes::route('/'),
        ];
    }
}
