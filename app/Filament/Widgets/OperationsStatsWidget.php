<?php

namespace App\Filament\Widgets;

use App\Models\DriverShift;
use App\Models\Order;
use App\Models\Vehicle;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class OperationsStatsWidget extends BaseWidget
{
    protected function getStats(): array
    {
        $today = now()->toDateString();

        return [
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

            Stat::make('Xe sẵn sàng', Vehicle::where('is_active', true)->where('status', 'on')->count().' / '.Vehicle::where('is_active', true)->count())
                ->description('Xe ON / Tổng xe')
                ->descriptionIcon('heroicon-m-squares-2x2')
                ->color('info')
                ->chart([10, 10, 9, 10, 11, 10, 12]),

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
