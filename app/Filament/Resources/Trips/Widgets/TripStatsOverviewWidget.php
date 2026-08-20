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
                ->icon('heroicon-o-truck')
                ->color('primary'),

            Stat::make('Đang chạy', $running)
                ->icon('heroicon-o-play-circle')
                ->color('info'),

            Stat::make('Chờ chạy', $pending)
                ->icon('heroicon-o-clock')
                ->color('warning'),

            Stat::make('Hoàn thành', $completed)
                ->icon('heroicon-o-check-circle')
                ->color('success'),

            Stat::make('Trễ giờ', $delayed)
                ->icon('heroicon-o-exclamation-triangle')
                ->color('danger'),
        ];
    }
}
