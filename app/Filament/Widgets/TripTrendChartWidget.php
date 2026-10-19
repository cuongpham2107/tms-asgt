<?php

namespace App\Filament\Widgets;

use App\Models\Order;
use App\Models\Trip;
use Filament\Widgets\ChartWidget;

class TripTrendChartWidget extends ChartWidget
{
    protected ?string $heading = 'Xu hướng Vận tải & Điều phối Chuyến xe';

    protected ?string $description = 'Theo dõi khối lượng chuyến xe và tiến độ hoàn thành đơn hàng';

    protected ?string $maxHeight = '320px';

    protected int|string|array $columnSpan = [
        'default' => 'full',
        'lg' => 2,
    ];

    public ?string $filter = '7days';

    protected function getFilters(): ?array
    {
        return [
            '7days' => '7 ngày gần nhất',
            '30days' => '30 ngày qua',
            'month' => 'Tháng này',
        ];
    }

    protected function getData(): array
    {
        $activeFilter = $this->filter ?: '7days';

        $days = match ($activeFilter) {
            '30days' => 30,
            'month' => (int) now()->day,
            default => 7,
        };

        $labels = [];
        $tripCounts = [];
        $orderCompletedCounts = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $date = now()->subDays($i);
            $dateStr = $date->toDateString();
            $label = $days > 14 ? $date->format('d/m') : $date->format('D d/m');

            $labels[] = $label;

            $trips = Trip::query()
                ->where(function ($q) use ($dateStr) {
                    $q->whereDate('started_at', $dateStr)
                        ->orWhereDate('created_at', $dateStr);
                })
                ->count();

            $completedOrders = Order::query()
                ->where('status', 'completed')
                ->whereDate('planned_loading_at', $dateStr)
                ->count();

            $tripCounts[] = $trips;
            $orderCompletedCounts[] = $completedOrders;
        }

        return [
            'datasets' => [
                [
                    'label' => 'Chuyến xe phát sinh',
                    'data' => $tripCounts,
                    'borderColor' => '#0ea5e9',
                    'backgroundColor' => 'rgba(14, 165, 233, 0.15)',
                    'fill' => true,
                    'tension' => 0.4,
                    'borderWidth' => 2.5,
                    'pointBackgroundColor' => '#0ea5e9',
                    'pointBorderColor' => '#ffffff',
                    'pointBorderWidth' => 2,
                    'pointRadius' => 4,
                    'pointHoverRadius' => 6,
                ],
                [
                    'label' => 'Đơn hoàn thành',
                    'data' => $orderCompletedCounts,
                    'borderColor' => '#10b981',
                    'backgroundColor' => 'rgba(16, 185, 129, 0.08)',
                    'fill' => true,
                    'tension' => 0.4,
                    'borderWidth' => 2.5,
                    'pointBackgroundColor' => '#10b981',
                    'pointBorderColor' => '#ffffff',
                    'pointBorderWidth' => 2,
                    'pointRadius' => 4,
                    'pointHoverRadius' => 6,
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
                    'position' => 'top',
                    'align' => 'end',
                    'labels' => [
                        'usePointStyle' => true,
                        'boxWidth' => 8,
                        'padding' => 16,
                        'font' => [
                            'size' => 12,
                            'weight' => '500',
                        ],
                    ],
                ],
                'tooltip' => [
                    'mode' => 'index',
                    'intersect' => false,
                    'padding' => 12,
                    'cornerRadius' => 8,
                ],
            ],
            'interaction' => [
                'mode' => 'nearest',
                'axis' => 'x',
                'intersect' => false,
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
                    ],
                ],
            ],
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
