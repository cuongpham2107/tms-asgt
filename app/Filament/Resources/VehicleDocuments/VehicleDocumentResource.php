<?php

namespace App\Filament\Resources\VehicleDocuments;

use App\Filament\BaseResource;
use App\Filament\Resources\VehicleDocuments\Pages\CreateVehicleDocument;
use App\Filament\Resources\VehicleDocuments\Pages\EditVehicleDocument;
use App\Filament\Resources\VehicleDocuments\Pages\ListVehicleDocuments;
use App\Filament\Resources\VehicleDocuments\Schemas\VehicleDocumentForm;
use App\Filament\Resources\VehicleDocuments\Schemas\VehicleDocumentInfolist;
use App\Filament\Resources\VehicleDocuments\Tables\VehicleDocumentsTable;
use App\Models\VehicleDocument;
use BackedEnum;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

class VehicleDocumentResource extends BaseResource
{
    protected static ?string $model = VehicleDocument::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $recordTitleAttribute = 'certificate_number';

    protected static ?string $navigationLabel = 'Giấy tờ xe';

    protected static ?string $pluralModelLabel = 'Giấy tờ xe';

    protected static string|UnitEnum|null $navigationGroup = 'Bảo dưỡng';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return VehicleDocumentForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return VehicleDocumentInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return VehicleDocumentsTable::configure($table);
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
            'index' => ListVehicleDocuments::route('/'),
            // 'create' => CreateVehicleDocument::route('/create'),
            // 'edit' => EditVehicleDocument::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
