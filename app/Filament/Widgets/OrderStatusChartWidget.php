<?php

namespace App\Filament\Widgets;

use App\Enums\OrderStatus;
use App\Models\Order;
use Filament\Widgets\ChartWidget;

class OrderStatusChartWidget extends ChartWidget
{
    protected ?string $heading = 'Cơ cấu Trạng thái Đơn hàng';

    protected ?string $description = 'Tỷ lệ phân bổ trạng thái các đơn hàng trong hệ thống';

    protected ?string $maxHeight = '320px';

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

        $data = $query->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $labels = [];
        $values = [];
        $backgroundColors = [];

        $colorMap = [
            'completed' => '#10b981',    // Emerald
            'in_transit' => '#0ea5e9',   // Sky
            'sent' => '#6366f1',         // Indigo
            'draft' => '#94a3b8',        // Slate
            'cancelled' => '#ef4444',    // Rose
            'started' => '#3b82f6',      // Blue
            'arrived_pickup' => '#8b5cf6', // Violet
            'delivering' => '#06b6d4',   // Cyan
            'arrived_delivery' => '#14b8a6', // Teal
        ];

        foreach ($data as $statusVal => $count) {
            $statusEnum = OrderStatus::tryFrom($statusVal);
            $label = $statusEnum ? $statusEnum->getLabel() : $statusVal;

            $labels[] = "{$label} ({$count})";
            $values[] = $count;
            $backgroundColors[] = $colorMap[$statusVal] ?? '#a855f7';
        }

        if (empty($values)) {
            $labels = ['Không có dữ liệu'];
            $values = [1];
            $backgroundColors = ['rgba(156, 163, 175, 0.2)'];
        }

        return [
            'datasets' => [
                [
                    'label' => 'Đơn hàng',
                    'data' => $values,
                    'backgroundColor' => $backgroundColors,
                    'borderWidth' => 2,
                    'borderColor' => '#ffffff',
                    'hoverOffset' => 6,
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
                    'display' => true,
                    'position' => 'bottom',
                    'labels' => [
                        'usePointStyle' => true,
                        'boxWidth' => 8,
                        'padding' => 14,
                        'font' => [
                            'size' => 11,
                            'weight' => '500',
                        ],
                    ],
                ],
                'tooltip' => [
                    'padding' => 12,
                    'cornerRadius' => 8,
                ],
            ],
            'cutout' => '72%',
        ];
    }

    protected function getType(): string
    {
        return 'doughnut';
    }
}
