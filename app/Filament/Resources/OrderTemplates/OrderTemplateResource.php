<?php

namespace App\Filament\Resources\OrderTemplates;

use App\Filament\BaseResource;
use App\Filament\Resources\OrderTemplates\Pages\CreateOrderTemplate;
use App\Filament\Resources\OrderTemplates\Pages\EditOrderTemplate;
use App\Filament\Resources\OrderTemplates\Pages\ListOrderTemplates;
use App\Filament\Resources\OrderTemplates\Schemas\OrderTemplateForm;
use App\Filament\Resources\OrderTemplates\Schemas\OrderTemplateInfolist;
use App\Filament\Resources\OrderTemplates\Tables\OrderTemplatesTable;
use App\Models\OrderTemplate;
use BackedEnum;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class OrderTemplateResource extends BaseResource
{
    protected static ?string $model = OrderTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentDuplicate;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $navigationLabel = 'Mẫu đơn';

    protected static ?string $pluralModelLabel = 'Mẫu đơn';

    protected static string|UnitEnum|null $navigationGroup = 'Cấu hình';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return OrderTemplateForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return OrderTemplateInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return OrderTemplatesTable::configure($table);
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
            'index' => ListOrderTemplates::route('/'),
            // 'create' => CreateOrderTemplate::route('/create'),
            // 'edit' => EditOrderTemplate::route('/{record}/edit'),
        ];
    }
}
