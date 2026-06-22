<?php

namespace App\Filament\Widgets;

use App\Enums\VehicleStatus;
use App\Models\Vehicle;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class GoogleMapStatsOverview extends BaseWidget
{
    protected static ?string $maxHeight = '140px';

    protected int|array|null $columns = 5;

    protected function getStats(): array
    {
        $vehicles = Vehicle::where('is_active', true)->get();

        $total = $vehicles->count();
        $running = $vehicles->filter(fn (Vehicle $v) => $v->status === VehicleStatus::Running)->count();
        $on = $vehicles->filter(fn (Vehicle $v) => $v->status === VehicleStatus::On)->count();
        $bdsc = $vehicles->filter(fn (Vehicle $v) => $v->status === VehicleStatus::Bdsc)->count();
        $off = $vehicles->filter(fn (Vehicle $v) => $v->status === VehicleStatus::Off)->count();

        $pct = fn (int $count) => $total > 0 ? round($count / $total * 100) : 0;

        return [
            Stat::make('Tổng xe', number_format($total))
                ->description('Tất cả xe đang hoạt động')
                ->descriptionIcon('heroicon-m-truck')
                ->color('primary')
                ->chart([5, 8, 4, 10, 11, 10, 12]),

            Stat::make('Đang chạy', number_format($running))
                ->description($pct($running).'% tổng xe')
                ->descriptionIcon('heroicon-m-play-circle')
                ->color('warning')
                ->chart([7, 2, 10, 3, 15, 4, 17]),

            Stat::make('Sẵn sàng', number_format($on))
                ->description($pct($on).'% tổng xe')
                ->descriptionIcon('heroicon-m-check-circle')
                ->color('success')
                ->chart([5, 4, 5, 8, 9, 8, 10]),

            Stat::make('Bảo dưỡng', number_format($bdsc))
                ->description($pct($bdsc).'% tổng xe')
                ->descriptionIcon('heroicon-m-wrench-screwdriver')
                ->color('danger')
                ->chart([1, 0, 2, 1, 0, 1, 2]),

            Stat::make('Tắt máy', number_format($off))
                ->description($pct($off).'% tổng xe')
                ->descriptionIcon('heroicon-m-stop-circle')
                ->color('gray')
                ->chart([2, 3, 2, 4, 3, 2, 3]),
        ];
    }
}
