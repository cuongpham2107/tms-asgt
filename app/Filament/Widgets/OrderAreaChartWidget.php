<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\DriverMonthlyKmReport;
use App\Filament\Widgets\Concerns\StylesDashboardChart;
use App\Models\Order;
use App\Services\ShiftScheduleService;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Widgets\ChartWidget;

class OrderAreaChartWidget extends ChartWidget
{
    use HasWidgetShield;
    use StylesDashboardChart;

    protected ?string $heading = 'Sản lượng Vận tải theo Tuyến / Khu vực';

    protected ?string $description = 'Số lượng đơn hàng được phân bổ tới từng trung tâm logistics và khu vực';

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
            $query->whereBetween('orders.planned_loading_at', [$from, $to]);
        }

        $data = $query->join('areas', 'orders.area_id', '=', 'areas.id')
            ->selectRaw('areas.name as area_name, areas.code as area_code, count(orders.id) as count')
            ->groupBy('areas.name', 'areas.code')
            ->orderByDesc('count')
            ->get();

        $labels = [];
        $values = [];

        foreach ($data as $row) {
            $name = $row->area_name ?: $row->area_code;
            $labels[] = $name !== $row->area_code ? [$row->area_code, $name] : $row->area_code;
            $values[] = (int) $row->count;
        }

        if (empty($values)) {
            $labels = ['Chưa có dữ liệu'];
            $values = [0];
        }

        return [
            'datasets' => [
                [
                    'label' => 'Số lượng đơn hàng',
                    'data' => $values,
                    'backgroundColor' => '#0284c7',
                    'hoverBackgroundColor' => '#0369a1',
                    'borderRadius' => 4,
                    'maxBarThickness' => 22,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getOptions(): array
    {
        return $this->dashboardChartOptions([
            'indexAxis' => 'y',
            'plugins' => ['legend' => ['display' => false]],
            'scales' => [
                'x' => ['grid' => ['display' => true, 'color' => 'rgba(148, 163, 184, 0.15)'], 'beginAtZero' => true],
                'y' => ['grid' => ['display' => false]],
            ],
        ]);
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
