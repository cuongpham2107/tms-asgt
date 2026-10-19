<?php

namespace App\Filament\Widgets;

use App\Models\Order;
use Filament\Widgets\ChartWidget;

class OrderAreaChartWidget extends ChartWidget
{
    protected ?string $heading = 'Sản lượng Vận tải theo Tuyến / Khu vực';

    protected ?string $description = 'Số lượng đơn hàng được phân bổ tới từng trung tâm logistics và khu vực';

    protected ?string $maxHeight = '320px';

    protected int|string|array $columnSpan = [
        'default' => 'full',
        'lg' => 2,
    ];

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
        $now = now();

        $query = match ($activeFilter) {
            'today' => $query->whereDate('planned_loading_at', $now->toDateString()),
            'week' => $query->whereBetween('planned_loading_at', [
                $now->startOfWeek()->toDateTimeString(),
                $now->endOfWeek()->toDateTimeString(),
            ]),
            'month' => $query->whereMonth('planned_loading_at', $now->month)
                ->whereYear('planned_loading_at', $now->year),
            'all' => $query,
            default => $query->whereMonth('planned_loading_at', $now->month)
                ->whereYear('planned_loading_at', $now->year),
        };

        $data = $query->join('areas', 'orders.area_id', '=', 'areas.id')
            ->selectRaw('areas.name as area_name, areas.code as area_code, count(orders.id) as count')
            ->groupBy('areas.name', 'areas.code')
            ->orderByDesc('count')
            ->get();

        $labels = [];
        $values = [];
        $colors = [
            '#3b82f6', // NBA / Blue
            '#10b981', // BN / Emerald
            '#f59e0b', // TN / Amber
            '#8b5cf6', // NBO / Violet
            '#06b6d4', // ASG / Cyan
            '#ec4899', // PROVINCE / Pink
            '#64748b', // Other
        ];
        $backgroundColors = [];

        foreach ($data as $index => $row) {
            $name = $row->area_name ?: $row->area_code;
            $labels[] = "{$row->area_code} ({$name})";
            $values[] = (int) $row->count;
            $backgroundColors[] = $colors[$index % count($colors)];
        }

        if (empty($values)) {
            $labels = ['Chưa có dữ liệu'];
            $values = [0];
            $backgroundColors = ['#94a3b8'];
        }

        return [
            'datasets' => [
                [
                    'label' => 'Số lượng đơn hàng',
                    'data' => $values,
                    'backgroundColor' => $backgroundColors,
                    'borderRadius' => 8,
                    'borderSkipped' => false,
                    'maxBarThickness' => 36,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => [
                'legend' => [
                    'display' => false,
                ],
                'tooltip' => [
                    'padding' => 12,
                    'cornerRadius' => 8,
                ],
            ],
            'scales' => [
                'y' => [
                    'beginAtZero' => true,
                    'grid' => [
                        'color' => 'rgba(156, 163, 175, 0.12)',
                        'borderDash' => [4, 4],
                    ],
                    'ticks' => [
                        'precision' => 0,
                        'padding' => 8,
                    ],
                ],
                'x' => [
                    'grid' => [
                        'display' => false,
                    ],
                    'ticks' => [
                        'padding' => 8,
                        'font' => [
                            'size' => 11,
                        ],
                    ],
                ],
            ],
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
