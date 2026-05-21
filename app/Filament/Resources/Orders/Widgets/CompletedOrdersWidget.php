<?php

namespace App\Filament\Resources\Orders\Widgets;

use App\Filament\Resources\Orders\Widgets\Concerns\InteractsWithOrderStats;
use Filament\Support\Enums\IconPosition;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class CompletedOrdersWidget extends StatsOverviewWidget
{
    use InteractsWithOrderStats;

    protected int|string|array $columnSpan = 1;

    protected function getStats(): array
    {
        return [
            Stat::make('Hoàn thành', self::completedOrdersCount())
                ->icon('heroicon-o-check-badge')
                ->color('success')
                ->description('Đơn đã chốt xong')
                ->descriptionIcon('heroicon-m-arrow-trending-up', IconPosition::After)
                ->descriptionColor('success')
                ->chart(self::weeklyTrend([
                    'completed',
                    'delivered',
                ]))
                ->chartColor('success'),
        ];
    }
}
