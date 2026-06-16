<?php

namespace App\Filament\Resources\Trips\Widgets;

use App\Enums\OrderStatus;
use App\Filament\Resources\Trips\TripResource;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class TripStatsOverviewWidget extends StatsOverviewWidget
{
    protected int|string|array $columnSpan = 5;

    protected int|array|null $columns = 5;

    protected function getStats(): array
    {
        $baseQuery = TripResource::getEloquentQuery();

        $total = (clone $baseQuery)->count();
        $running = (clone $baseQuery)->whereIn('status', [
            OrderStatus::Started->value,
            OrderStatus::ArrivedPickup->value,
            OrderStatus::Delivering->value,
            OrderStatus::ArrivedDelivery->value,
        ])->count();
        $planned = (clone $baseQuery)->where('status', OrderStatus::Draft->value)->count();
        $completed = (clone $baseQuery)->where('status', OrderStatus::Completed->value)->count();
        $delayed = (clone $baseQuery)
            ->whereIn('status', [
                OrderStatus::Started->value,
                OrderStatus::ArrivedPickup->value,
                OrderStatus::Delivering->value,
            ])
            ->where('planned_loading_at', '<', now())
            ->count();

        return [
            Stat::make('Tổng chuyến', $total)
                ->color('gray'),
            Stat::make('Đang chạy', $running)
                ->color('info'),
            Stat::make('Kế hoạch', $planned)
                ->color('gray'),
            Stat::make('Hoàn thành', $completed)
                ->color('success'),
            Stat::make('Trễ giờ', $delayed)
                ->color('danger'),
        ];
    }
}
