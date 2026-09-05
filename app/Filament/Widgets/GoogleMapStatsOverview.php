<?php

namespace App\Filament\Widgets;

use App\Enums\VehicleOwnerType;
use App\Enums\VehicleStatus;
use App\Models\Vehicle;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class GoogleMapStatsOverview extends BaseWidget
{
    protected int|array|null $columns = 5;

    protected function getStats(): array
    {
        $vehicles = Vehicle::query()
            ->where('is_active', true)
            ->where('type', VehicleOwnerType::Company)
            ->get();

        $total = $vehicles->count();
        $running = $vehicles->where('status', VehicleStatus::Running)->count();
        $on = $vehicles->where('status', VehicleStatus::On)->count();
        $bdsc = $vehicles->where('status', VehicleStatus::Bdsc)->count();
        $off = $vehicles->where('status', VehicleStatus::Off)->count();

        $pct = function (int $count) use ($total): string {
            if ($total === 0 || $count === 0) {
                return '0%';
            }

            $raw = ($count / $total) * 100;
            if ($raw >= 100.0) {
                return '100%';
            }

            if ($raw < 1.0 || $raw > 99.0) {
                return number_format($raw, 1, '.', '').'%';
            }

            return round($raw, 1).'%';
        };

        return [
            Stat::make('Tổng phương tiện', number_format($total))
                ->description('Tất cả xe hoạt động')
                ->descriptionIcon('heroicon-m-truck')
                ->color('primary'),

            Stat::make('Đang chạy', number_format($running))
                ->description($pct($running).' tổng đội xe')
                ->descriptionIcon('heroicon-m-play-circle')
                ->color('warning'),

            Stat::make('Sẵn sàng', number_format($on))
                ->description($pct($on).' tổng đội xe')
                ->descriptionIcon('heroicon-m-check-circle')
                ->color('success'),

            Stat::make('Bảo dưỡng / SC', number_format($bdsc))
                ->description($pct($bdsc).' tổng đội xe')
                ->descriptionIcon('heroicon-m-wrench-screwdriver')
                ->color('danger'),

            Stat::make('Tắt máy / Tạm dừng', number_format($off))
                ->description($pct($off).' tổng đội xe')
                ->descriptionIcon('heroicon-m-stop-circle')
                ->color('gray'),
        ];
    }
}
