<?php

namespace App\Filament\Widgets;

use App\Models\Order;
use Filament\Widgets\ChartWidget;

class OrderAreaChartWidget extends ChartWidget
{
    protected ?string $heading = 'Thống kê đơn hàng đi khu vực';

    protected ?string $maxHeight = '300px';

    protected function getFilters(): ?array
    {
        return [
            'today' => 'Hôm nay',
            'week' => 'Tuần này',
            'month' => 'Tháng này',
            'year' => 'Năm nay',
        ];
    }

    protected function getData(): array
    {
        $activeFilter = $this->filter ?: 'today';

        $query = Order::query();

        $now = now();
        $query = match ($activeFilter) {
            'today' => $query->whereDate('planned_loading_at', $now->toDateString()),
            'week' => $query->whereBetween('planned_loading_at', [
                $now->startOfWeek()->toDateTimeString(),
                $now->endOfWeek()->toDateTimeString(),
            ]),
            'month' => $query->whereMonth('planned_loading_at', $now->month)
                ->whereYear('planned_loading_at', $now->year),
            'year' => $query->whereYear('planned_loading_at', $now->year),
            default => $query->whereDate('planned_loading_at', $now->toDateString()),
        };

        $data = $query->join('areas', 'orders.area_id', '=', 'areas.id')
            ->selectRaw('areas.code as area_code, count(orders.id) as count')
            ->groupBy('areas.code')
            ->pluck('count', 'area_code')
            ->toArray();

        $labels = array_keys($data);
        $values = array_values($data);

        return [
            'datasets' => [
                [
                    'label' => 'Số lượng đơn hàng',
                    'data' => $values,
                    'backgroundColor' => '#008fd5',
                    'borderColor' => '#008fd5',
                    'borderWidth' => 1,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
