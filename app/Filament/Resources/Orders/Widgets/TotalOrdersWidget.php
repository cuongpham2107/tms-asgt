<?php

namespace App\Filament\Resources\Orders\Widgets;

use App\Filament\Resources\Orders\Widgets\Concerns\InteractsWithOrderStats;
use Filament\Support\Enums\IconPosition;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class TotalOrdersWidget extends StatsOverviewWidget
{
    use InteractsWithOrderStats;

    protected int|string|array $columnSpan = 1;

    protected function getStats(): array
    {
        return [
            Stat::make('Tổng đơn', self::totalOrdersCount())
                ->icon('heroicon-o-rectangle-stack')
                ->color('primary')
                ->description('7 ngày gần nhất')
                ->descriptionIcon('heroicon-m-arrow-trending-up', IconPosition::After)
                ->descriptionColor('primary')
                ->chart(self::weeklyTrend())
                ->chartColor('primary'),
        ];
    }
}
