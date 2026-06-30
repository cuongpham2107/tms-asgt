<?php

namespace App\Filament\Resources\Trips\Widgets;

use App\Enums\TripStatus;
use App\Filament\Resources\Trips\Pages\ListTrips;
use App\Filament\Traits\InteractsWithPageTable;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;

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

        $activeStatuses = TripStatus::activeStatuses();

        $running = (clone $baseQuery)
            ->whereIn('status', array_map(fn ($s) => $s->value, $activeStatuses))
            ->count();

        $pending = (clone $baseQuery)
            ->where('status', TripStatus::Pending->value)
            ->count();

        $completed = (clone $baseQuery)
            ->where('status', TripStatus::Completed->value)
            ->count();

        $delayed = (clone $baseQuery)
            ->whereIn('status', [
                TripStatus::Started->value,
                TripStatus::ArrivedPickup->value,
                TripStatus::Delivering->value,
                TripStatus::ArrivedDelivery->value,
            ])
            ->whereHas('orders', fn (Builder $q) => $q
                ->where('planned_loading_at', '<', now())
            )->count();

        return [
            Stat::make('Tổng chuyến', $total)
                ->description('Tất cả chuyến đi')
                ->descriptionIcon('heroicon-m-truck')
                ->color('primary'),

            Stat::make('Đang chạy', $running)
                ->description('Đang vận chuyển')
                ->descriptionIcon('heroicon-m-play-circle')
                ->color('info'),

            Stat::make('Chờ chạy', $pending)
                ->description('Chưa khởi hành')
                ->descriptionIcon('heroicon-m-calendar-days')
                ->color('warning'),

            Stat::make('Hoàn thành', $completed)
                ->description('Đã kết thúc')
                ->descriptionIcon('heroicon-m-check-badge')
                ->color('success'),

            Stat::make('Trễ giờ', $delayed)
                ->description('Quá thời gian dự kiến')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color('danger'),
        ];
    }
}
