<?php

namespace App\Filament\Widgets;

use App\Models\Order;
use Filament\Widgets\ChartWidget;

class OrderTypeChartWidget extends ChartWidget
{
    protected ?string $heading = 'Tỷ lệ đơn hàng theo loại';

    protected ?string $maxHeight = '280px';

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

        $counts = $query->selectRaw('type, count(*) as count')
            ->groupBy('type')
            ->pluck('count', 'type')
            ->toArray();

        $hhhkCount = $counts['HHHK'] ?? 0;
        $externalCount = $counts['external'] ?? 0;

        return [
            'datasets' => [
                [
                    'label' => 'Số lượng đơn hàng',
                    'data' => [$hhhkCount, $externalCount],
                    'backgroundColor' => ['#008fd5', '#4CAF50'],
                ],
            ],
            'labels' => ['Hàng không (HHHK)', 'Hàng ngoài'],
        ];
    }

    protected function getType(): string
    {
        return 'doughnut';
    }
}
