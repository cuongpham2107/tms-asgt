<?php

namespace App\Filament\Widgets;

use App\Enums\VehicleStatus;
use App\Models\Vehicle;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class GoogleMapStatsOverview extends BaseWidget
{
    protected static ?string $maxHeight = '140px';

    protected int | array | null $columns = 5;
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
                ->descriptionIcon('heroicon-o-truck')
                ->color('primary'),

            Stat::make('Đang chạy', number_format($running))
                ->description($pct($running).'% tổng xe')
                ->descriptionIcon('heroicon-o-play')
                ->color('warning')
                ->chart([7, 2, 10, 3, 15, 4, 17]),

            Stat::make('Sẵn sàng', number_format($on))
                ->description($pct($on).'% tổng xe')
                ->descriptionIcon('heroicon-o-check-circle')
                ->color('success'),

            Stat::make('Bảo dưỡng', number_format($bdsc))
                ->description($pct($bdsc).'% tổng xe')
                ->descriptionIcon('heroicon-o-wrench-screwdriver')
                ->color('danger'),

            Stat::make('Tắt máy', number_format($off))
                ->description($pct($off).'% tổng xe')
                ->descriptionIcon('heroicon-o-stop')
                ->color('gray'),
        ];
    }
}
