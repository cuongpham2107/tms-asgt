<?php

namespace App\Filament\Resources\Orders\Widgets\Concerns;

use App\Enums\OrderStatus;
use App\Models\Order;
use Carbon\CarbonImmutable;

use function collect;

trait InteractsWithOrderStats
{
    protected static function totalOrdersCount(): int
    {
        return Order::query()->count();
    }

    protected static function pendingOrdersCount(): int
    {
        return Order::query()
            ->whereIn('status', [
                OrderStatus::Draft->value,
                OrderStatus::Assigned->value,
            ])
            ->count();
    }

    protected static function transportingOrdersCount(): int
    {
        return Order::query()
            ->whereIn('status', [
                OrderStatus::Sent->value,
            ])
            ->count();
    }

    protected static function completedOrdersCount(): int
    {
        return Order::query()
            ->whereIn('status', [
                OrderStatus::Completed->value,
            ])
            ->count();
    }

    /**
     * @param  array<int, string>  $statuses
     * @return array<int, int>
     */
    protected static function weeklyTrend(array $statuses = []): array
    {
        $startDate = CarbonImmutable::now()->subDays(6)->startOfDay();
        $endDate = CarbonImmutable::now()->endOfDay();

        $countsByDate = Order::query()
            ->when($statuses !== [], fn ($query) => $query->whereIn('status', $statuses))
            ->whereBetween('created_at', [$startDate, $endDate])
            ->selectRaw('DATE(created_at) as date, COUNT(*) as aggregate')
            ->groupBy('date')
            ->pluck('aggregate', 'date');

        return collect(range(6, 0))
            ->map(function (int $daysAgo) use ($countsByDate): int {
                $dateKey = CarbonImmutable::now()->subDays($daysAgo)->toDateString();

                return (int) ($countsByDate[$dateKey] ?? 0);
            })
            ->all();
    }
}
