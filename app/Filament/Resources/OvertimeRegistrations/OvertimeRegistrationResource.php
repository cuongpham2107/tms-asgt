<?php

namespace App\Filament\Resources\OvertimeRegistrations;

use App\Filament\BaseResource;
use App\Filament\Resources\OvertimeRegistrations\Pages\ListOvertimeRegistrations;
use App\Filament\Resources\OvertimeRegistrations\Tables\OvertimeRegistrationsTable;
use App\Models\OvertimeRegistration;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class OvertimeRegistrationResource extends BaseResource
{
    protected static ?string $model = OvertimeRegistration::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?string $recordTitleAttribute = 'id';

    protected static ?string $navigationLabel = 'Đăng ký tăng cường';

    protected static ?string $pluralModelLabel = 'Đăng ký tăng cường';

    protected static ?string $modelLabel = 'Đăng ký tăng cường';

    protected static string|UnitEnum|null $navigationGroup = 'Hoạt động';

    protected static ?int $navigationSort = 2;

    public static function table(Table $table): Table
    {
        return OvertimeRegistrationsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOvertimeRegistrations::route('/'),
        ];
    }
}
