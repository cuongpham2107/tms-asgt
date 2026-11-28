<?php

namespace App\Filament\Widgets;

use App\Enums\TripStatus;
use App\Filament\Widgets\Concerns\BuildsShiftTrendBuckets;
use App\Filament\Widgets\Concerns\StylesDashboardChart;
use App\Models\Trip;
use App\Services\Km\DriverMonthlyKmReportService;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Widgets\ChartWidget;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

class TripKmTrendChartWidget extends ChartWidget
{
    use BuildsShiftTrendBuckets;
    use HasWidgetShield;
    use StylesDashboardChart;

    protected ?string $heading = 'Xu hướng Km vận hành (ca 8h–8h+)';

    public ?string $filter = '7days';

    protected function getFilters(): ?array
    {
        return $this->shiftTrendFilters();
    }

    /**
     * Tổng Km (và tách có hàng / không hàng) của kỳ hiện tại kèm chênh lệch so với kỳ liền trước.
     */
    public function getDescription(): string|Htmlable|null
    {
        $bucketMeta = $this->resolveShiftTrendBuckets($this->filter);
        $periodLabel = $bucketMeta['periodLabel'];
        $comparisonTitle = $bucketMeta['comparisonTitle'];

        $parts = collect($this->getCachedData()['datasets'])->map(function (array $dataset) use ($periodLabel, $comparisonTitle): string {
            $values = array_values($dataset['data']);
            $count = count($values);
            $current = $count > 0 ? (float) $values[$count - 1] : 0.0;
            $previous = $count > 1 ? (float) $values[$count - 2] : 0.0;
            $delta = round($current - $previous, 1);

            $deltaClass = $delta > 0 ? 'text-emerald-600' : ($delta < 0 ? 'text-rose-600' : 'text-gray-400');
            $deltaText = $delta == 0.0
                ? '±0'
                : ($delta > 0 ? '+' : '-').number_format(abs($delta), 0, ',', '.');

            return '<span class="mr-3 inline-flex items-baseline gap-1" title="'.e($comparisonTitle).'"><span class="size-2 self-center rounded-full" style="background: '.e($dataset['borderColor']).'"></span>'
                .e($dataset['label']).' '.e($periodLabel).' <b class="text-sm font-bold tabular-nums text-gray-950 dark:text-white">'.number_format($current, 0, ',', '.').' km</b>'
                .'<span class="text-xs tabular-nums '.$deltaClass.'">'.$deltaText.'</span></span>';
        });

        return new HtmlString($parts->implode(''));
    }

    protected function getData(): array
    {
        ['windowStart' => $windowStart, 'windowEnd' => $windowEnd, 'buckets' => $buckets] = $this->resolveShiftTrendBuckets($this->filter);

        $trips = Trip::query()
            ->whereIn('status', [
                TripStatus::Completed->value,
                TripStatus::Delivered->value,
                TripStatus::DriverSwap->value,
                TripStatus::Cancelled->value,
            ])
            ->tap(fn (Builder $q): Builder => DriverMonthlyKmReportService::applyCompletionWindow($q, $windowStart, $windowEnd))
            ->get(['completed_at', 'cancelled_at', 'started_at', 'created_at', 'start_km', 'end_km', 'total_km', 'total_km_loaded', 'total_km_empty']);

        $labels = array_column($buckets, 'label');
        $bucketCount = count($buckets);
        $totalKmData = array_fill(0, $bucketCount, 0.0);
        $loadedKmData = array_fill(0, $bucketCount, 0.0);
        $emptyKmData = array_fill(0, $bucketCount, 0.0);

        foreach ($trips as $trip) {
            $effectiveAt = $trip->completed_at ?? $trip->cancelled_at ?? $trip->started_at ?? $trip->created_at;
            if ($effectiveAt === null) {
                continue;
            }

            $kmTotal = $trip->total_km !== null
                ? max(0.0, (float) $trip->total_km)
                : (($trip->end_km !== null && $trip->start_km !== null) ? max(0.0, (float) $trip->end_km - (float) $trip->start_km) : 0.0);

            if ($kmTotal <= 0.0) {
                continue;
            }

            $kmLoaded = max(0.0, (float) ($trip->total_km_loaded ?? 0.0));
            $kmEmpty = $trip->total_km_empty !== null
                ? max(0.0, (float) $trip->total_km_empty)
                : max(0.0, $kmTotal - $kmLoaded);

            foreach ($buckets as $idx => $bucket) {
                if ($effectiveAt->gte($bucket['start']) && $effectiveAt->lt($bucket['end'])) {
                    $totalKmData[$idx] += $kmTotal;
                    $loadedKmData[$idx] += $kmLoaded;
                    $emptyKmData[$idx] += $kmEmpty;
                    break;
                }
            }
        }

        $roundList = static fn (array $items): array => array_map(static fn (float $v): float => round($v, 1), $items);

        return [
            'datasets' => [
                $this->buildTrendLineDataset('Tổng Km', $roundList($totalKmData), '#0ea5e9', 'rgba(14, 165, 233, 0.10)', true),
                $this->buildTrendLineDataset('Km có hàng', $roundList($loadedKmData), '#10b981', 'rgba(16, 185, 129, 0.06)', true),
                $this->buildTrendLineDataset('Km không hàng', $roundList($emptyKmData), '#f59e0b', 'rgba(245, 158, 11, 0.05)', false),
            ],
            'labels' => $labels,
        ];
    }

    protected function getOptions(): array
    {
        return $this->dashboardChartOptions([
            'plugins' => ['tooltip' => ['mode' => 'index', 'intersect' => false]],
            'interaction' => ['mode' => 'index', 'intersect' => false],
        ]);
    }

    protected function getType(): string
    {
        return 'line';
    }
}
