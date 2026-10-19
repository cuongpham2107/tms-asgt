<?php

namespace App\Filament\Widgets;

use App\Enums\VehicleOwnerType;
use App\Enums\VehicleStatus;
use App\Models\DriverShift;
use App\Models\Order;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class OperationsStatsWidget extends BaseWidget
{
    protected int|string|array $columnSpan = 'full';

    protected int|array|null $columns = [
        'default' => 1,
        'sm' => 2,
        'md' => 3,
        'xl' => 3,
        '2xl' => 6,
    ];

    protected function getStats(): array
    {
        $today = now()->toDateString();

        // 1. Xe công ty
        $companyOn = Vehicle::where('is_active', true)
            ->where('type', VehicleOwnerType::Company)
            ->where('status', VehicleStatus::On)
            ->count();
        $companyTotal = Vehicle::where('is_active', true)
            ->where('type', VehicleOwnerType::Company)
            ->count();
        $availPercent = $companyTotal > 0 ? round(($companyOn / $companyTotal) * 100) : 100;

        // 2. Xe thuê làm việc
        $rentWorkingToday = Vehicle::where('is_active', true)
            ->where('type', VehicleOwnerType::Rent)
            ->where(function ($query) use ($today) {
                $query->whereHas('trips', function ($q) use ($today) {
                    $q->whereDate('started_at', $today)
                        ->orWhereDate('created_at', $today)
                        ->orWhereHas('orders', fn ($oq) => $oq->whereDate('planned_loading_at', $today));
                })
                    ->orWhereHas('driver.driverShifts', fn ($q) => $q->whereNull('end_time'))
                    ->orWhereHas('trips.shift', fn ($q) => $q->whereNull('end_time'));
            })
            ->count();

        // 3. Chuyến xe hôm nay
        $tripsToday = Trip::whereDate('started_at', $today)
            ->orWhereDate('created_at', $today)
            ->count();
        $tripsRunning = Trip::whereIn('status', [
            'started', 'arrived_pickup', 'delivering', 'arrived_delivery', 'driver_swap',
        ])->count();

        // 4. Đơn hàng đang vận chuyển
        $inTransitOrders = Order::whereIn('status', [
            'started', 'arrived_pickup', 'delivering', 'arrived_delivery', 'in_transit', 'sent',
        ])->count();

        // 5. Đơn hàng hoàn thành hôm nay
        $completedToday = Order::where('status', 'completed')
            ->where(function ($q) use ($today) {
                $q->whereDate('planned_loading_at', $today)
                    ->orWhereDate('updated_at', $today);
            })
            ->count();

        // 6. Tài xế trong ca
        $activeDrivers = DriverShift::whereNull('end_time')->count();
        $totalDrivers = User::role('driver')->where('is_active', true)->count();

        return [
            Stat::make('Xe công ty sẵn sàng', "{$companyOn} / {$companyTotal}")
                ->description("{$availPercent}% đội xe ON sẵn sàng")
                ->descriptionIcon('heroicon-m-check-badge')
                ->color('info')
                ->chart([8, 10, 9, 11, 10, 12, 11]),

            Stat::make('Xe thuê làm việc', $rentWorkingToday)
                ->description('Xe thuê điều phối hôm nay')
                ->descriptionIcon('heroicon-m-truck')
                ->color('warning')
                ->chart([3, 4, 3, 5, 4, 6, $rentWorkingToday ?: 5]),

            Stat::make('Chuyến xe hôm nay', $tripsToday)
                ->description("{$tripsRunning} chuyến đang lăn bánh")
                ->descriptionIcon('heroicon-m-map-pin')
                ->color('primary')
                ->chart([4, 6, 5, 8, 7, 9, $tripsToday ?: 8]),

            Stat::make('Đơn hàng trên đường', $inTransitOrders)
                ->description('Đang vận chuyển & giao hàng')
                ->descriptionIcon('heroicon-m-cube')
                ->color('info')
                ->chart([6, 8, 10, 9, 12, 11, $inTransitOrders ?: 10]),

            Stat::make('Hoàn thành hôm nay', $completedToday)
                ->description('Đơn giao thành công')
                ->descriptionIcon('heroicon-m-check-circle')
                ->color('success')
                ->chart([2, 4, 5, 6, 8, 9, $completedToday ?: 7]),

            Stat::make('Tài xế trong ca', "{$activeDrivers} / {$totalDrivers}")
                ->description('Tài xế đang trực ca')
                ->descriptionIcon('heroicon-m-user-group')
                ->color('success')
                ->chart([10, 12, 14, 15, 18, 17, $activeDrivers ?: 16]),
        ];
    }
}
