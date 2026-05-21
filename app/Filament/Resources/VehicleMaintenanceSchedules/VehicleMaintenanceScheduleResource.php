<?php

namespace App\Filament\Resources\VehicleMaintenanceSchedules;

use App\Filament\BaseResource;
use App\Filament\Resources\VehicleMaintenanceSchedules\Pages\CreateVehicleMaintenanceSchedule;
use App\Filament\Resources\VehicleMaintenanceSchedules\Pages\EditVehicleMaintenanceSchedule;
use App\Filament\Resources\VehicleMaintenanceSchedules\Pages\ListVehicleMaintenanceSchedules;
use App\Filament\Resources\VehicleMaintenanceSchedules\Schemas\VehicleMaintenanceScheduleForm;
use App\Filament\Resources\VehicleMaintenanceSchedules\Schemas\VehicleMaintenanceScheduleInfolist;
use App\Filament\Resources\VehicleMaintenanceSchedules\Tables\VehicleMaintenanceSchedulesTable;
use App\Models\VehicleMaintenanceSchedule;
use BackedEnum;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class VehicleMaintenanceScheduleResource extends BaseResource
{
    protected static ?string $model = VehicleMaintenanceSchedule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendar;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $navigationLabel = 'Lịch bảo dưỡng';

    protected static ?string $pluralModelLabel = 'Lịch bảo dưỡng';

    protected static string|UnitEnum|null $navigationGroup = 'Bảo dưỡng';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return VehicleMaintenanceScheduleForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return VehicleMaintenanceScheduleInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return VehicleMaintenanceSchedulesTable::configure($table);
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
            'index' => ListVehicleMaintenanceSchedules::route('/'),
            // 'create' => CreateVehicleMaintenanceSchedule::route('/create'),
            // 'edit' => EditVehicleMaintenanceSchedule::route('/{record}/edit'),
        ];
    }
}
