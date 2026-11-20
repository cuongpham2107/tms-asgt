<?php

namespace App\Filament\Widgets\Concerns;

/**
 * Kiểu chung cho các biểu đồ trên dashboard: cùng chiều cao khung, chú thích, tooltip và lưới.
 */
trait StylesDashboardChart
{
    public function getMaxHeight(): ?string
    {
        return '260px';
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function dashboardChartOptions(array $overrides = []): array
    {
        $axis = [
            'border' => ['display' => false],
            'grid' => ['color' => 'rgba(148, 163, 184, 0.15)'],
            'ticks' => ['precision' => 0, 'padding' => 6, 'font' => ['size' => 11], 'color' => '#64748b'],
        ];

        return array_replace_recursive([
            'maintainAspectRatio' => false,
            'plugins' => [
                'legend' => [
                    'display' => true,
                    'position' => 'bottom',
                    'labels' => ['usePointStyle' => true, 'pointStyle' => 'circle', 'boxWidth' => 6, 'boxHeight' => 6, 'padding' => 12, 'font' => ['size' => 11], 'color' => '#475569'],
                ],
                'tooltip' => ['padding' => 10, 'cornerRadius' => 6, 'boxPadding' => 4],
            ],
            'scales' => [
                'x' => [...$axis, 'grid' => ['display' => false]],
                'y' => [...$axis, 'beginAtZero' => true],
            ],
        ], $overrides);
    }
}
