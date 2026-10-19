<?php

namespace App\Filament\Resources\Staff;

use App\Filament\Resources\Staff\Pages\ListStaff;
use App\Filament\Resources\Staff\Schemas\StaffForm;
use App\Filament\Resources\Staff\Tables\StaffTable;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class StaffResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $slug = 'staff';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $navigationLabel = 'Nhân viên';

    protected static ?string $modelLabel = 'Nhân viên';

    protected static ?string $pluralModelLabel = 'Nhân viên';

    protected static string|UnitEnum|null $navigationGroup = 'Quản lý';

    protected static ?int $navigationSort = 3;

    public static function canViewAny(): bool
    {
        return auth()->user()?->hasRole('super_admin') || auth()->user()?->can('ViewAny:User');
    }

    public static function form(Schema $schema): Schema
    {
        return StaffForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return StaffTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereDoesntHave('roles', fn ($q) => $q->where('name', 'driver'));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStaff::route('/'),
        ];
    }
}
