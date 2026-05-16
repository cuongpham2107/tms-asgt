<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\OperationsStatsWidget;
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
            VehicleMapWidget::class,
        ];
    }
}
