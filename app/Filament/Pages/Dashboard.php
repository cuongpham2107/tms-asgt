<?php

namespace App\Filament\Pages;

use App\Filament\Resources\DriverShifts\Widgets\DriverShiftCalendarWidget;
use App\Filament\Widgets\OperationsStatsWidget;
use App\Filament\Widgets\OrderAreaChartWidget;
use App\Filament\Widgets\OrderStatusChartWidget;
use App\Filament\Widgets\OrderTypeChartWidget;
use App\Filament\Widgets\VehicleDestinationChartWidget;
use App\Filament\Widgets\VehicleMapWidget;
use Filament\Pages\Dashboard as BaseDashboard;
use UnitEnum;

class Dashboard extends BaseDashboard
{
    protected static string|UnitEnum|null $navigationGroup = 'Tổng quan';

    public function getWidgets(): array
    {
        return [
            OperationsStatsWidget::class,
            OrderTypeChartWidget::class,
            OrderStatusChartWidget::class,
            OrderAreaChartWidget::class,
            VehicleDestinationChartWidget::class,
            VehicleMapWidget::class,
            DriverShiftCalendarWidget::class,
        ];
    }
}
