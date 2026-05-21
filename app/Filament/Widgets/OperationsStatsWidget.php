<?php

namespace App\Filament\Widgets;

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
                ->descriptionIcon('heroicon-o-truck')
                ->color('primary'),

            Stat::make('Đang chạy', Order::whereIn('status', ['started', 'arrived_pickup', 'delivering', 'arrived_delivery'])->count())
                ->description('Xe đang hoạt động')
                ->descriptionIcon('heroicon-o-play-circle')
                ->color('warning'),

            Stat::make('Hoàn thành', Order::where('status', 'completed')->whereDate('planned_loading_at', $today)->count())
                ->description('Đã giao xong hôm nay')
                ->descriptionIcon('heroicon-o-check-circle')
                ->color('success'),

            Stat::make('Xe sẵn sàng', Vehicle::where('is_active', true)->where('status', 'on')->count().' / '.Vehicle::where('is_active', true)->count())
                ->description('Xe ON / Tổng xe')
                ->descriptionIcon('heroicon-o-square-3-stack-3d')
                ->color('info'),
        ];
    }
}
