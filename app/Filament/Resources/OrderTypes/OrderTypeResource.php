<?php

namespace App\Filament\Resources\OrderTypes;

use App\Filament\BaseResource;
use App\Filament\Resources\OrderTypes\Pages\CreateOrderType;
use App\Filament\Resources\OrderTypes\Pages\EditOrderType;
use App\Filament\Resources\OrderTypes\Pages\ListOrderTypes;
use App\Filament\Resources\OrderTypes\Schemas\OrderTypeForm;
use App\Filament\Resources\OrderTypes\Schemas\OrderTypeInfolist;
use App\Filament\Resources\OrderTypes\Tables\OrderTypesTable;
use App\Models\OrderType;
use BackedEnum;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class OrderTypeResource extends BaseResource
{
    protected static ?string $model = OrderType::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $navigationLabel = 'Loại đơn';

    protected static ?string $pluralModelLabel = 'Loại đơn';

    protected static string|UnitEnum|null $navigationGroup = 'Cấu hình';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return OrderTypeForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return OrderTypeInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return OrderTypesTable::configure($table);
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
            'index' => ListOrderTypes::route('/'),
            // 'create' => CreateOrderType::route('/create'),
            // 'edit' => EditOrderType::route('/{record}/edit'),
        ];
    }
}
