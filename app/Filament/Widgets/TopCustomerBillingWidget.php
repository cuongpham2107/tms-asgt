<?php

namespace App\Filament\Widgets;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Filament\Pages\TripStatisticsReport;
use App\Models\Customer;
use App\Services\Shift\ShiftScheduleService;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class TopCustomerBillingWidget extends TableWidget
{
    use HasWidgetShield;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Sản lượng & Cước theo Khách hàng (Tháng hiện tại)';

    public function table(Table $table): Table
    {
        $shiftAnchor = ShiftScheduleService::getShiftWindow()[0];
        $monthStart = $shiftAnchor->copy()->startOfMonth()->setTime(8, 0, 0);
        $monthEnd = $shiftAnchor->copy()->endOfMonth()->addDay()->setTime(8, 0, 0);

        $orderWindowFilter = function (Builder $q) use ($monthStart, $monthEnd): void {
            $q->where('orders.status', '!=', OrderStatus::Cancelled->value)
                ->where(function (Builder $dateQ) use ($monthStart, $monthEnd) {
                    $dateQ->whereBetween('orders.planned_loading_at', [$monthStart, $monthEnd])
                        ->orWhere(fn (Builder $sq) => $sq->whereNull('orders.planned_loading_at')->whereBetween('orders.created_at', [$monthStart, $monthEnd]));
                });
        };

        $chargeWindowFilter = function (Builder $q) use ($monthStart, $monthEnd): void {
            $q->whereHas('order', function (Builder $oq) use ($monthStart, $monthEnd) {
                $oq->where('orders.status', '!=', OrderStatus::Cancelled->value)
                    ->where(function (Builder $dateQ) use ($monthStart, $monthEnd) {
                        $dateQ->whereBetween('orders.planned_loading_at', [$monthStart, $monthEnd])
                            ->orWhere(fn (Builder $sq) => $sq->whereNull('orders.planned_loading_at')->whereBetween('orders.created_at', [$monthStart, $monthEnd]));
                    });
            });
        };

        return $table
            ->query(
                Customer::query()
                    ->where('is_active', true)
                    ->whereHas('orders', $orderWindowFilter)
                    ->withCount([
                        'orders as hhhk_orders_count' => fn (Builder $q) => tap($q, $orderWindowFilter)->where('orders.type', OrderType::Hhhk->value),
                        'orders as external_orders_count' => fn (Builder $q) => tap($q, $orderWindowFilter)->where('orders.type', OrderType::External->value),
                        'orderCharges as unlocked_charges_count' => fn (Builder $q) => tap($q, $chargeWindowFilter)->whereNull('order_charges.exported_at'),
                    ])
                    ->withSum([
                        'orderCharges as monthly_billing_total' => $chargeWindowFilter,
                    ], 'total')
            )
            ->defaultSort('external_orders_count', 'desc')
            ->paginated([5, 10, 25])
            ->defaultPaginationPageOption(10)
            ->columns([
                TextColumn::make('code')
                    ->label('Mã KH')
                    ->badge()
                    ->color('primary')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->label('Khách hàng')
                    ->weight('medium')
                    ->searchable(),
                TextColumn::make('hhhk_orders_count')
                    ->label('Đơn HHHK')
                    ->numeric()
                    ->alignCenter()
                    ->sortable(),
                TextColumn::make('external_orders_count')
                    ->label('Đơn Hàng ngoài')
                    ->numeric()
                    ->alignCenter()
                    ->sortable(),
                TextColumn::make('unlocked_charges_count')
                    ->label('Cước chưa khoá')
                    ->numeric()
                    ->alignCenter()
                    ->badge()
                    ->color(fn ($state): string => (int) $state > 0 ? 'warning' : 'gray')
                    ->sortable(),
                TextColumn::make('monthly_billing_total')
                    ->label('Tổng tiền cước')
                    ->alignEnd()
                    ->fontFamily('mono')
                    ->weight('bold')
                    ->formatStateUsing(fn ($state): string => number_format((float) ($state ?? 0), 0, ',', '.').' đ')
                    ->sortable(),
            ])
            ->recordUrl(fn (Customer $record): ?string => TripStatisticsReport::canAccess()
                ? TripStatisticsReport::getUrl(['customerId' => $record->id, 'activeTab' => 'external'])
                : null)
            ->emptyStateHeading('Chưa có phát sinh đơn hàng trong tháng')
            ->emptyStateDescription('Dữ liệu đối soát khách hàng sẽ hiển thị khi có đơn hàng phát sinh trong tháng này.');
    }
}
