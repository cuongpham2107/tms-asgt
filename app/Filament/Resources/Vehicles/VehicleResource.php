<?php

namespace App\Filament\Resources\Vehicles;

use App\Filament\BaseResource;
use App\Filament\Resources\Vehicles\Pages\ListVehicles;
use App\Filament\Resources\Vehicles\Schemas\VehicleForm;
use App\Filament\Resources\Vehicles\Tables\VehiclesTable;
use App\Models\Vehicle;
use BackedEnum;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class VehicleResource extends BaseResource
{
    protected static bool $isGloballySearchable = true;

    /**
     * Tìm nhanh (⌘K / Ctrl+K).
     *
     * @return list<string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['plate_number'];
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->with([]);
    }

    /**
     * @param  Vehicle  $record
     * @return array<string, string>
     */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return [
            'Loại xe' => $record->vehicle_type?->getLabel() ?? '—',
        ];
    }

    /**
     * Bảng sửa/xem bằng modal nên mở danh sách đã lọc sẵn theo bản ghi.
     *
     * @param  Vehicle  $record
     */
    public static function getGlobalSearchResultUrl(Model $record): string
    {
        return static::getUrl('index', ['search' => $record->plate_number]);
    }

    protected static ?string $model = Vehicle::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static ?string $recordTitleAttribute = 'plate_number';

    protected static ?string $navigationLabel = 'Phương tiện';

    protected static ?string $pluralModelLabel = 'Phương tiện';

    protected static string|UnitEnum|null $navigationGroup = 'Quản lý';

    protected static ?int $navigationSort = 0;

    public static function form(Schema $schema): Schema
    {
        return VehicleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return VehiclesTable::configure($table);
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
            'index' => ListVehicles::route('/'),
            // 'create' => CreateVehicle::route('/create'),
            // 'view' => ViewVehicle::route('/{record}'),
            // 'edit' => EditVehicle::route('/{record}/edit'),
        ];
    }
}
