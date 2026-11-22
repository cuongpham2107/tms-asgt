<?php

namespace App\Filament\Widgets;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Filament\Pages\TripStatisticsReport;
use App\Models\Order;
use App\Models\OrderCharge;
use App\Services\Shift\ShiftScheduleService;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class BillingOverviewStatsWidget extends BaseWidget
{
    use HasWidgetShield;

    protected ?string $heading = 'Tổng quan Cước vận chuyển (Tháng hiện tại)';

    protected int|string|array $columnSpan = 'full';

    protected int|array|null $columns = [
        'default' => 1,
        'sm' => 2,
        'xl' => 4,
    ];

    protected function getStats(): array
    {
        $shiftAnchor = ShiftScheduleService::getShiftWindow()[0];
        $monthStart = $shiftAnchor->copy()->startOfMonth()->setTime(8, 0, 0);
        $monthEnd = $shiftAnchor->copy()->endOfMonth()->addDay()->setTime(8, 0, 0);

        $externalOrders = Order::query()
            ->where('type', OrderType::External->value)
            ->where('status', '!=', OrderStatus::Cancelled->value)
            ->where(function ($q) use ($monthStart, $monthEnd) {
                $q->whereBetween('planned_loading_at', [$monthStart, $monthEnd])
                    ->orWhere(fn ($sq) => $sq->whereNull('planned_loading_at')->whereBetween('created_at', [$monthStart, $monthEnd]));
            })
            ->get(['id', 'status', 'chargeable_weight']);

        $externalCount = $externalOrders->count();
        $externalOrderIds = $externalOrders->modelKeys();

        $charges = $externalOrderIds === []
            ? collect()
            : OrderCharge::query()->whereIn('order_id', $externalOrderIds)->get(['order_id', 'base_price', 'total', 'exported_at']);

        $totalAmount = (float) $charges->sum('total');
        $pricedCount = $charges->whereNotNull('base_price')->count();
        $lockedCharges = $charges->whereNotNull('exported_at');
        $unlockedPricedCharges = $charges->whereNull('exported_at')->whereNotNull('base_price');

        $chargedOrderIds = $charges->pluck('order_id')->flip();
        $completedWithoutChargeCount = $externalOrders->filter(
            fn (Order $order): bool => $order->status === OrderStatus::Completed
                && ! $chargedOrderIds->has($order->id)
        )->count();

        $missingPriceCount = $charges->whereNull('base_price')->count() + $completedWithoutChargeCount;
        $missingWeightCount = $externalOrders->whereNull('chargeable_weight')->count();

        $canAccessReport = TripStatisticsReport::canAccess();
        $reportUrl = $canAccessReport ? TripStatisticsReport::getUrl(['activeTab' => 'external']) : null;
        $missingUrl = $canAccessReport ? TripStatisticsReport::getUrl(['activeTab' => 'external', 'chargeStatus' => 'missing']) : null;
        $lockedUrl = $canAccessReport ? TripStatisticsReport::getUrl(['activeTab' => 'external', 'chargeStatus' => 'locked']) : null;

        return [
            Stat::make('Tổng cước Hàng ngoài', number_format($totalAmount, 0, ',', '.').' đ')
                ->description($pricedCount > 0 ? "{$pricedCount} / {$externalCount} đơn đã có giá" : "Tháng {$shiftAnchor->format('m/Y')} · {$externalCount} đơn Hàng ngoài")
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('primary')
                ->url($reportUrl),

            Stat::make('Đơn thiếu giá / tải trọng', $missingPriceCount)
                ->description($missingWeightCount > 0 ? "{$missingWeightCount} đơn chưa nhập trọng tải cước" : 'Theo dõi các đơn cần bổ sung đơn giá')
                ->descriptionIcon($missingPriceCount > 0 ? 'heroicon-m-exclamation-circle' : 'heroicon-m-check-circle')
                ->color($missingPriceCount > 0 ? 'warning' : 'success')
                ->url($missingUrl),

            Stat::make('Chưa khoá bảng kê', $unlockedPricedCharges->count())
                ->description(number_format((float) $unlockedPricedCharges->sum('total'), 0, ',', '.').' đ chờ đối soát')
                ->descriptionIcon('heroicon-m-lock-open')
                ->color($unlockedPricedCharges->count() > 0 ? 'info' : 'gray')
                ->url($reportUrl),

            Stat::make('Đã khoá bảng kê', $lockedCharges->count())
                ->description(number_format((float) $lockedCharges->sum('total'), 0, ',', '.').' đ đã chốt')
                ->descriptionIcon('heroicon-m-lock-closed')
                ->color('success')
                ->url($lockedUrl),
        ];
    }
}
