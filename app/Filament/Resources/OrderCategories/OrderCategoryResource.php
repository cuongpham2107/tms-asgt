<?php

namespace App\Filament\Resources\OrderCategories;

use App\Filament\BaseResource;
use App\Filament\Resources\OrderCategories\Pages\CreateOrderCategory;
use App\Filament\Resources\OrderCategories\Pages\EditOrderCategory;
use App\Filament\Resources\OrderCategories\Pages\ListOrderCategories;
use App\Filament\Resources\OrderCategories\Schemas\OrderCategoryForm;
use App\Filament\Resources\OrderCategories\Schemas\OrderCategoryInfolist;
use App\Filament\Resources\OrderCategories\Tables\OrderCategoriesTable;
use App\Models\OrderCategory;
use BackedEnum;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class OrderCategoryResource extends BaseResource
{
    protected static ?string $model = OrderCategory::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFolder;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $navigationLabel = 'Phân loại đơn';

    protected static ?string $pluralModelLabel = 'Phân loại đơn';

    protected static string|UnitEnum|null $navigationGroup = 'Cấu hình';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return OrderCategoryForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return OrderCategoryInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return OrderCategoriesTable::configure($table);
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
            'index' => ListOrderCategories::route('/'),
            'create' => CreateOrderCategory::route('/create'),
            'edit' => EditOrderCategory::route('/{record}/edit'),
        ];
    }
}
