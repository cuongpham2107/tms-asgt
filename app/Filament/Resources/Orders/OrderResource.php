<?php

namespace App\Filament\Resources\Orders;

use App\Filament\BaseResource;
use App\Filament\Resources\Orders\Pages\CreateOrder;
use App\Filament\Resources\Orders\Pages\EditOrder;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Schemas\OrderForm;
use App\Filament\Resources\Orders\Schemas\OrderInfolist;
use App\Filament\Resources\Orders\Tables\OrdersTable;
use App\Models\Order;
use BackedEnum;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class OrderResource extends BaseResource
{
    protected static bool $isGloballySearchable = true;

    /**
     * Tìm nhanh (⌘K / Ctrl+K).
     *
     * @return list<string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['order_code', 'customer.code'];
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->with(['customer']);
    }

    /**
     * @param  Order  $record
     * @return array<string, string>
     */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return [
            'Khách hàng' => $record->customer?->code ?? '—',
            'Trạng thái' => $record->status?->getLabel() ?? '—',
        ];
    }

    /**
     * Bảng sửa/xem bằng modal nên mở danh sách đã lọc sẵn theo bản ghi.
     *
     * @param  Order  $record
     */
    public static function getGlobalSearchResultUrl(Model $record): string
    {
        return static::getUrl('index', ['search' => $record->order_code]);
    }

    protected static ?string $model = Order::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocument;

    protected static ?string $recordTitleAttribute = 'order_code';

    protected static ?string $navigationLabel = 'Đơn hàng';

    protected static ?string $pluralModelLabel = 'Đơn hàng';

    protected static string|UnitEnum|null $navigationGroup = 'Vận hành';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return OrderForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return OrderInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return OrdersTable::configure($table, 'order');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrders::route('/'),
            // 'create' => CreateOrder::route('/create'),
            // 'edit' => EditOrder::route('/{record}/edit'),
        ];
    }
}
