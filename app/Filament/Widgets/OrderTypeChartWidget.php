<?php

namespace App\Filament\Widgets;

use App\Models\Order;
use Filament\Widgets\ChartWidget;

class OrderTypeChartWidget extends ChartWidget
{
    protected ?string $heading = 'Cơ cấu Loại Hàng hoá';

    protected ?string $description = 'Tỷ lệ giữa Hàng không (HHHK) và Hàng ngoài';

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
                    'hoverOffset' => 6,
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
