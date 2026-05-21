<?php

namespace App\Filament\Resources\Orders\Widgets;

use App\Filament\Resources\Orders\Widgets\Concerns\InteractsWithOrderStats;
use Filament\Support\Enums\IconPosition;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class PendingOrdersWidget extends StatsOverviewWidget
{
    use InteractsWithOrderStats;

    protected int|string|array $columnSpan = 1;

    protected function getStats(): array
    {
        return [
            Stat::make('Chờ xử lý', self::pendingOrdersCount())
                ->icon('heroicon-o-clock')
                ->color('warning')
                ->description('Đơn chưa hoàn tất phân xe')
                ->descriptionIcon('heroicon-m-arrow-trending-up', IconPosition::After)
                ->descriptionColor('warning')
                ->chart(self::weeklyTrend([
                    'draft',
                    'assigned',
                ]))
                ->chartColor('warning'),
        ];
    }
}
