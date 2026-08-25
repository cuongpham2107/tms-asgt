<?php

namespace App\Filament\Widgets;

use App\Enums\VehicleOwnerType;
use App\Enums\VehicleStatus;
use App\Models\DriverShift;
use App\Models\Order;
use App\Models\Vehicle;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class OperationsStatsWidget extends BaseWidget
{
    protected int|string|array $columnSpan = 'full';

    protected int|array|null $columns = [
        'default' => 1,
        'sm' => 2,
        'md' => 2,
        'lg' => 4,
        'xl' => 4,
        '2xl' => 8,
    ];

    protected function getStats(): array
    {
        $today = now()->toDateString();

        $companyOn = Vehicle::where('is_active', true)
            ->where('type', VehicleOwnerType::Company)
            ->where('status', VehicleStatus::On)
            ->count();
        $companyTotal = Vehicle::where('is_active', true)
            ->where('type', VehicleOwnerType::Company)
            ->count();

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

        return [
            Stat::make('Xe công ty sẵn sàng', "{$companyOn} / {$companyTotal}")
                ->description('Xe công ty ON / Tổng xe')
                ->descriptionIcon('heroicon-m-truck')
                ->color('info')
                ->chart([10, 10, 9, 10, 11, 10, 12]),

            Stat::make('Xe thuê làm việc', $rentWorkingToday)
                ->description('Xe thuê hoạt động hôm nay')
                ->descriptionIcon('heroicon-m-currency-dollar')
                ->color('warning')
                ->chart([10, 10, 9, 10, 11, 10, 12]),
            Stat::make('Tổng chuyến hôm nay', Order::whereDate('planned_loading_at', $today)->count())
                ->description('Chuyến đi trong ngày')
                ->descriptionIcon('heroicon-m-truck')
                ->color('primary')
                ->chart([3, 5, 4, 8, 5, 9, 7]),

            Stat::make('Đang chạy', Order::whereIn('status', ['started', 'arrived_pickup', 'delivering', 'arrived_delivery'])->count())
                ->description('Xe đang hoạt động')
                ->descriptionIcon('heroicon-m-play-circle')
                ->color('warning')
                ->chart([2, 4, 3, 5, 4, 6, 8]),

            Stat::make('Hoàn thành', Order::where('status', 'completed')->whereDate('planned_loading_at', $today)->count())
                ->description('Đã giao xong hôm nay')
                ->descriptionIcon('heroicon-m-check-circle')
                ->color('success')
                ->chart([0, 2, 4, 3, 5, 6, 7]),

            Stat::make('Đơn hàng nháp', Order::where('status', 'draft')->count())
                ->description('Chờ phân xe & lái xe')
                ->descriptionIcon('heroicon-m-document-text')
                ->color('gray')
                ->chart([5, 4, 6, 3, 2, 5, 4]),

            Stat::make('Chuyến quay đầu', Order::where('is_return_trip', true)->whereDate('planned_loading_at', $today)->count())
                ->description('Chuyến khứ hồi hôm nay')
                ->descriptionIcon('heroicon-m-arrow-path')
                ->color('info')
                ->chart([0, 1, 0, 2, 1, 3, 2]),

            Stat::make('Tài xế trong ca', DriverShift::whereNull('end_time')->count())
                ->description('Ca trực đang hoạt động')
                ->descriptionIcon('heroicon-m-users')
                ->color('success')
                ->chart([5, 6, 8, 9, 10, 12, 11]),
        ];
    }
}
