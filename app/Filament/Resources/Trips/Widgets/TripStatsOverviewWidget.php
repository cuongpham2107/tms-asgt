<?php

namespace App\Filament\Resources\Trips\Widgets;

use App\Enums\TripStatus;
use App\Filament\Resources\Trips\Pages\ListTrips;
use App\Filament\Traits\InteractsWithPageTable;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class TripStatsOverviewWidget extends StatsOverviewWidget
{
    use InteractsWithPageTable;

    protected int|string|array $columnSpan = 5;

    protected int|array|null $columns = 5;

    protected function getTablePage(): string
    {
        return ListTrips::class;
    }

    protected function getStats(): array
    {
        $baseQuery = $this->getPageTableQuery();

        $total = (clone $baseQuery)->count();

        $completed = (clone $baseQuery)->where('status', 'completed')->count();

        $running = (clone $baseQuery)
            ->where('status', 'in_progress')
            ->count();

        $pending = (clone $baseQuery)
            ->where('status', 'pending')
            ->count();

        $delayed = (clone $baseQuery)
            ->where('status', 'in_progress')
            ->whereHas('orders', fn ($q) => $q
                ->whereIn('status', [
                    TripStatus::Started->value,
                    TripStatus::ArrivedPickup->value,
                    TripStatus::Delivering->value,
                ])
                ->where('planned_loading_at', '<', now())
            )->count();

        return [
            Stat::make('Tổng chuyến', $total)
                ->description('Tất cả chuyến đi')
                ->descriptionIcon('heroicon-m-truck')
                ->color('primary')
                ->chart([7, 3, 4, 5, 6, 3, 5, 3]),

            Stat::make('Đang chạy', $running)
                ->description('Trên đường giao')
                ->descriptionIcon('heroicon-m-play-circle')
                ->color('info')
                ->chart([2, 4, 3, 6, 5, 7, 8, 6]),

            Stat::make('Đã gửi', $pending)
                ->description('Chưa khởi hành')
                ->descriptionIcon('heroicon-m-calendar-days')
                ->color('warning')
                ->chart([3, 2, 4, 2, 5, 2, 1, 2]),

            Stat::make('Hoàn thành', $completed)
                ->description('Đã kết thúc')
                ->descriptionIcon('heroicon-m-check-badge')
                ->color('success')
                ->chart([5, 6, 5, 8, 9, 12, 10, 15]),

            Stat::make('Trễ giờ', $delayed)
                ->description('Cần lưu ý')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color('danger')
                ->chart([0, 1, 0, 1, 2, 1, 3, 1]),
        ];
    }
}
