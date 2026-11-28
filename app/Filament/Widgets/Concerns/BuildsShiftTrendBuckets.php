<?php

namespace App\Filament\Widgets\Concerns;

use App\Services\Shift\ShiftScheduleService;
use Carbon\Carbon;

/**
 * Chia khung thời gian biểu đồ xu hướng theo ca 8h–8h+ (ngày / tuần / tháng)
 * dùng chung cho các widget xu hướng chuyến, KM và thời gian khai thác.
 */
trait BuildsShiftTrendBuckets
{
    /**
     * @return array<string, string>
     */
    protected function shiftTrendFilters(): array
    {
        return [
            '7days' => '7 ngày gần nhất',
            '30days' => '30 ngày qua',
            'month' => 'Tháng này',
            'weekly' => 'Theo tuần (12 tuần)',
            'monthly' => 'Theo tháng (6 tháng)',
        ];
    }

    /**
     * @return array{
     *     windowStart: Carbon,
     *     windowEnd: Carbon,
     *     buckets: list<array{label: string, start: Carbon, end: Carbon}>,
     *     periodLabel: string,
     *     comparisonTitle: string
     * }
     */
    protected function resolveShiftTrendBuckets(?string $filter): array
    {
        $activeFilter = $filter ?: '7days';
        $shiftNow = ShiftScheduleService::getShiftWindow()[0];

        if ($activeFilter === 'weekly') {
            $weeks = 12;
            $currentWeekStart = $shiftNow->copy()->startOfWeek()->setTime(8, 0, 0);
            $buckets = [];

            for ($i = $weeks - 1; $i >= 0; $i--) {
                $start = $currentWeekStart->copy()->subWeeks($i);
                $end = $start->copy()->addWeek();
                $displayEnd = $end->copy()->subDay();
                $buckets[] = [
                    'label' => sprintf('T%02d (%s-%s)', $start->isoWeek(), $start->format('d/m'), $displayEnd->format('d/m')),
                    'start' => $start,
                    'end' => $end,
                ];
            }

            return [
                'windowStart' => $buckets[0]['start'],
                'windowEnd' => $buckets[count($buckets) - 1]['end'],
                'buckets' => $buckets,
                'periodLabel' => 'tuần này',
                'comparisonTitle' => 'Tuần này so với tuần trước',
            ];
        }

        if ($activeFilter === 'monthly') {
            $months = 6;
            $currentMonthStart = $shiftNow->copy()->startOfMonth()->setTime(8, 0, 0);
            $buckets = [];

            for ($i = $months - 1; $i >= 0; $i--) {
                $start = $currentMonthStart->copy()->subMonthsNoOverflow($i);
                $end = $start->copy()->addMonthNoOverflow();
                $buckets[] = [
                    'label' => 'T'.$start->format('m/Y'),
                    'start' => $start,
                    'end' => $end,
                ];
            }

            return [
                'windowStart' => $buckets[0]['start'],
                'windowEnd' => $buckets[count($buckets) - 1]['end'],
                'buckets' => $buckets,
                'periodLabel' => 'tháng này',
                'comparisonTitle' => 'Tháng này so với tháng trước',
            ];
        }

        $days = match ($activeFilter) {
            '30days' => 30,
            'month' => max(1, (int) $shiftNow->day),
            default => 7,
        };

        $buckets = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = $shiftNow->copy()->subDays($i);
            $start = $date->copy()->setTime(8, 0, 0);
            $end = $date->copy()->addDay()->setTime(8, 0, 0);
            $buckets[] = [
                'label' => $days > 14 ? $date->format('d/m') : $date->format('D d/m'),
                'start' => $start,
                'end' => $end,
            ];
        }

        return [
            'windowStart' => $buckets[0]['start'],
            'windowEnd' => $buckets[count($buckets) - 1]['end'],
            'buckets' => $buckets,
            'periodLabel' => 'hôm nay',
            'comparisonTitle' => 'Hôm nay so với hôm qua',
        ];
    }

    /**
     * @param  list<int|float>  $data
     * @return array<string, mixed>
     */
    protected function buildTrendLineDataset(string $label, array $data, string $hexColor, string $bgRgba, bool $fill = true): array
    {
        return [
            'label' => $label,
            'data' => $data,
            'borderColor' => $hexColor,
            'backgroundColor' => $bgRgba,
            'fill' => $fill,
            'tension' => 0.35,
            'borderWidth' => 2,
            'pointBackgroundColor' => $hexColor,
            'pointBorderColor' => '#ffffff',
            'pointBorderWidth' => 1.5,
            'pointRadius' => 2.5,
            'pointHoverRadius' => 5,
        ];
    }
}
