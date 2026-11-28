<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\BillingOverviewStatsWidget;
use App\Filament\Widgets\DispatchActionRequiredWidget;
use App\Filament\Widgets\FleetComplianceAlertWidget;
use App\Filament\Widgets\OperationsStatsWidget;
use App\Filament\Widgets\OrderAreaChartWidget;
use App\Filament\Widgets\OrderTypeChartWidget;
use App\Filament\Widgets\RentedVehicleTrendChartWidget;
use App\Filament\Widgets\TopCustomerBillingWidget;
use App\Filament\Widgets\TripKmTrendChartWidget;
use App\Filament\Widgets\TripOperatingTimeChartWidget;
use App\Filament\Widgets\TripTrendChartWidget;
use App\Filament\Widgets\VehicleKmOverChartWidget;
use App\Filament\Widgets\VehicleStatusChartWidget;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use UnitEnum;

class Dashboard extends BaseDashboard
{
    protected static string|UnitEnum|null $navigationGroup = 'Tổng quan';

    protected static ?string $title = 'Bảng điều hành Vận tải';

    private const SUMMARY_WIDGETS = [
        OperationsStatsWidget::class,
        BillingOverviewStatsWidget::class,
        DispatchActionRequiredWidget::class,
        FleetComplianceAlertWidget::class,
    ];

    private const CHART_WIDGETS = [
        TripTrendChartWidget::class,
        VehicleStatusChartWidget::class,
        OrderAreaChartWidget::class,
        OrderTypeChartWidget::class,
    ];

    private const PERFORMANCE_TREND_WIDGETS = [
        TripKmTrendChartWidget::class,
        TripOperatingTimeChartWidget::class,
        RentedVehicleTrendChartWidget::class,
    ];

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
            ...self::SUMMARY_WIDGETS,
            ...self::CHART_WIDGETS,
            VehicleKmOverChartWidget::class,
            ...self::PERFORMANCE_TREND_WIDGETS,
            TopCustomerBillingWidget::class,
        ];
    }

    /**
     * 4 biểu đồ ghép lưới 2×2 chiếm 2/3 bên trái, bảng xếp hạng KM xe ở 1/3 bên phải cao bằng cả khối;
     * bên dưới là 2 biểu đồ xu hướng hiệu suất (KM vận hành + Thời gian khai thác) chia đôi toàn chiều rộng.
     */
    public function getWidgetsContentComponent(): Component
    {
        return Grid::make($this->getColumns())
            ->schema(fn (): array => [
                ...$this->getWidgetsSchemaComponents(self::SUMMARY_WIDGETS),
                Grid::make(['default' => 1, 'md' => 2])
                    ->columnSpan(['default' => 'full', 'lg' => 2])
                    ->schema($this->getWidgetsSchemaComponents(self::CHART_WIDGETS)),
                ...$this->getWidgetsSchemaComponents([VehicleKmOverChartWidget::class]),
                Grid::make(['default' => 1, 'md' => 2, 'xl' => 3])
                    ->columnSpanFull()
                    ->schema($this->getWidgetsSchemaComponents(self::PERFORMANCE_TREND_WIDGETS)),
                ...$this->getWidgetsSchemaComponents([TopCustomerBillingWidget::class]),
            ]);
    }
}
