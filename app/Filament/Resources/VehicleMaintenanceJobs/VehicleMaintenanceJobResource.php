<?php

namespace App\Filament\Resources\VehicleMaintenanceJobs;

use App\Filament\BaseResource;
use App\Filament\Resources\VehicleMaintenanceJobs\Pages\CreateVehicleMaintenanceJob;
use App\Filament\Resources\VehicleMaintenanceJobs\Pages\EditVehicleMaintenanceJob;
use App\Filament\Resources\VehicleMaintenanceJobs\Pages\ListVehicleMaintenanceJobs;
use App\Filament\Resources\VehicleMaintenanceJobs\Schemas\VehicleMaintenanceJobForm;
use App\Filament\Resources\VehicleMaintenanceJobs\Schemas\VehicleMaintenanceJobInfolist;
use App\Filament\Resources\VehicleMaintenanceJobs\Tables\VehicleMaintenanceJobsTable;
use App\Models\VehicleMaintenanceJob;
use BackedEnum;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class VehicleMaintenanceJobResource extends BaseResource
{
    protected static ?string $model = VehicleMaintenanceJob::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?string $navigationLabel = 'Công việc bảo dưỡng';

    protected static ?string $pluralModelLabel = 'Công việc bảo dưỡng';

    protected static string|UnitEnum|null $navigationGroup = 'Bảo dưỡng';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return VehicleMaintenanceJobForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return VehicleMaintenanceJobInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return VehicleMaintenanceJobsTable::configure($table);
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
            'index' => ListVehicleMaintenanceJobs::route('/'),
            // 'create' => CreateVehicleMaintenanceJob::route('/create'),
            // 'edit' => EditVehicleMaintenanceJob::route('/{record}/edit'),
        ];
    }
}
