<?php

namespace App\Filament\Resources\DriverShifts;

use App\Filament\BaseResource;
use App\Filament\Resources\DriverShifts\Pages\CalendarDriverShifts;
use App\Filament\Resources\DriverShifts\Pages\CreateDriverShift;
use App\Filament\Resources\DriverShifts\Pages\EditDriverShift;
use App\Filament\Resources\DriverShifts\Pages\ListDriverShifts;
use App\Filament\Resources\DriverShifts\Schemas\DriverShiftForm;
use App\Filament\Resources\DriverShifts\Schemas\DriverShiftInfolist;
use App\Filament\Resources\DriverShifts\Tables\DriverShiftsTable;
use App\Models\DriverShift;
use BackedEnum;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class DriverShiftResource extends BaseResource
{
    protected static ?string $model = DriverShift::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?string $recordTitleAttribute = 'id';

    protected static ?string $navigationLabel = 'Ca trực';

    protected static ?string $pluralModelLabel = 'Ca trực';

    protected static string|UnitEnum|null $navigationGroup = 'Hoạt động';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return DriverShiftForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return DriverShiftInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DriverShiftsTable::configure($table);
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
            'index' => CalendarDriverShifts::route('/'),
            'table' => ListDriverShifts::route('/table'),
            // 'create' => CreateDriverShift::route('/create'),
            // 'edit' => EditDriverShift::route('/{record}/edit'),
        ];
    }
}
