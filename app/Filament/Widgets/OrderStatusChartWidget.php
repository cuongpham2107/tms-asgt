<?php

namespace App\Filament\Widgets;

use App\Enums\OrderStatus;
use App\Models\Order;
use Filament\Widgets\ChartWidget;

class OrderStatusChartWidget extends ChartWidget
{
    protected ?string $heading = 'Thống kê đơn hàng theo trạng thái';

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

        $data = $query->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $labels = [];
        $values = [];
        $backgroundColors = [];

        foreach ($data as $statusVal => $count) {
            $statusEnum = OrderStatus::tryFrom($statusVal);
            $labels[] = $statusEnum ? $statusEnum->getLabel() : $statusVal;
            $values[] = $count;

            $colorName = $statusEnum ? $statusEnum->getColor() : 'gray';
            $backgroundColors[] = match ($colorName) {
                'success' => '#4CAF50',
                'warning' => '#FF9800',
                'danger' => '#F44336',
                'info' => '#008fd5',
                'primary' => '#8b5cf6',
                default => '#9E9E9E',
            };
        }

        return [
            'datasets' => [
                [
                    'label' => 'Số lượng đơn hàng',
                    'data' => $values,
                    'backgroundColor' => $backgroundColors,
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
