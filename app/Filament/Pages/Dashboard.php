<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\OperationsStatsWidget;
use App\Filament\Widgets\OrderAreaChartWidget;
use App\Filament\Widgets\OrderStatusChartWidget;
use App\Filament\Widgets\OrderTypeChartWidget;
use App\Filament\Widgets\TripTrendChartWidget;
use Filament\Pages\Dashboard as BaseDashboard;
use UnitEnum;

class Dashboard extends BaseDashboard
{
    protected static string|UnitEnum|null $navigationGroup = 'Tổng quan';

    protected static ?string $title = 'Bảng điều hành Vận tải';

    public function getColumns(): int|array
    {
        return [
            'default' => 1,
            'sm' => 2,
            'lg' => 3,
            'xl' => 3,
        ];
    }

    public function getWidgets(): array
    {
        return [
            OperationsStatsWidget::class,
            TripTrendChartWidget::class,
            OrderStatusChartWidget::class,
            OrderAreaChartWidget::class,
            OrderTypeChartWidget::class,
        ];
    }
}
