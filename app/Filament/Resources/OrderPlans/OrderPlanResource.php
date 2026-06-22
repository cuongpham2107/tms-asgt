<?php

namespace App\Filament\Resources\OrderPlans;

use App\Filament\BaseResource;
use App\Filament\Resources\Orders\Schemas\OrderForm;
use App\Filament\Resources\Orders\Tables\OrdersTable;
use App\Models\Order;
use BackedEnum;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class OrderPlanResource extends BaseResource
{
    protected static ?string $model = Order::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?string $navigationLabel = 'Kế hoạch';

    protected static ?string $pluralModelLabel = 'Kế hoạch';

    protected static string|UnitEnum|null $navigationGroup = 'Vận hành';

    protected static ?int $navigationSort = 1;

    public static function table(Table $table): Table
    {
        return OrdersTable::configure($table, 'plan');
    }

    public static function form(Schema $schema): Schema
    {
        return OrderForm::configure($schema);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrderPlans::route('/'),
        ];
    }
}
