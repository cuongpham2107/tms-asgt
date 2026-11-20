<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\DriverMonthlyKmReport;
use App\Filament\Widgets\Concerns\StylesDashboardChart;
use App\Models\Order;
use App\Services\ShiftScheduleService;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Widgets\ChartWidget;

class OrderTypeChartWidget extends ChartWidget
{
    use HasWidgetShield;
    use StylesDashboardChart;

    protected ?string $heading = 'Cơ cấu Loại Hàng hoá';

    protected ?string $description = 'Tỷ lệ giữa Hàng không (HHHK) và Hàng ngoài';

    public ?string $filter = 'month';

    protected function getFilters(): ?array
    {
        return [
            'month' => 'Tháng này',
            'week' => 'Tuần này',
            'today' => 'Hôm nay',
            'all' => 'Toàn bộ thời gian',
        ];
    }

    protected function getData(): array
    {
        $activeFilter = $this->filter ?: 'month';

        $query = Order::query();
        $shiftDate = ShiftScheduleService::getShiftWindow()[0]->toDateString();

        if ($activeFilter !== 'all') {
            [$from, $to] = DriverMonthlyKmReport::rangeFor($activeFilter, $shiftDate);
            $query->whereBetween('planned_loading_at', [$from, $to]);
        }

        $counts = $query->selectRaw('type, count(*) as count')
            ->groupBy('type')
            ->pluck('count', 'type')
            ->toArray();

        $hhhkCount = (int) ($counts['HHHK'] ?? 0);
        $externalCount = (int) ($counts['external'] ?? 0);
        $total = $hhhkCount + $externalCount;

        $hhhkPercent = $total > 0 ? round(($hhhkCount / $total) * 100) : 0;
        $externalPercent = $total > 0 ? round(($externalCount / $total) * 100) : 0;

        return [
            'datasets' => [
                [
                    'label' => 'Số lượng đơn hàng',
                    'data' => [$hhhkCount, $externalCount],
                    'backgroundColor' => ['#0284c7', '#10b981'],
                    'borderWidth' => 2,
                    'borderColor' => '#ffffff',
                    'hoverOffset' => 4,
                ],
            ],
            'labels' => [
                "Hàng không ({$hhhkCount} - {$hhhkPercent}%)",
                "Hàng ngoài ({$externalCount} - {$externalPercent}%)",
            ],
        ];
    }

    protected function getOptions(): array
    {
        return $this->dashboardChartOptions([
            'cutout' => '68%',
            'scales' => ['x' => ['display' => false], 'y' => ['display' => false]],
        ]);
    }

    protected function getType(): string
    {
        return 'doughnut';
    }
}
