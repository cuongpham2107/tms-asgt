<?php

namespace App\Filament\Resources\Orders\Widgets;

use App\Filament\Resources\Orders\Widgets\Concerns\InteractsWithOrderStats;
use Filament\Support\Enums\IconPosition;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class TransportingOrdersWidget extends StatsOverviewWidget
{
    use InteractsWithOrderStats;

    protected int|string|array $columnSpan = 1;

    protected function getStats(): array
    {
        return [
            Stat::make('Đang vận chuyển', self::transportingOrdersCount())
                ->icon('heroicon-o-truck')
                ->color('info')
                ->description('Đơn đang trên đường')
                ->descriptionIcon('heroicon-m-arrow-trending-up', IconPosition::After)
                ->descriptionColor('info')
                ->chart(self::weeklyTrend([
                    'sent',
                    'started',
                    'arrived_pickup',
                    'delivering',
                    'arrived_delivery',
                ]))
                ->chartColor('info'),
        ];
    }
}
