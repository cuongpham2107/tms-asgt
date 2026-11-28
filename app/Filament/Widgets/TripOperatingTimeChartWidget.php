<?php

namespace App\Filament\Widgets;

use App\Enums\TripStatus;
use App\Enums\VehicleOwnerType;
use App\Filament\Widgets\Concerns\BuildsShiftTrendBuckets;
use App\Filament\Widgets\Concerns\StylesDashboardChart;
use App\Models\Trip;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Widgets\ChartWidget;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

class TripOperatingTimeChartWidget extends ChartWidget
{
    use BuildsShiftTrendBuckets;
    use HasWidgetShield;
    use StylesDashboardChart;

    protected ?string $heading = 'Thời gian khai thác (ca 8h–8h+)';

    public ?string $filter = '7days';

    /** @var array{active_vehicles: int, trip_count: int} */
    protected array $latestPeriodMeta = ['active_vehicles' => 0, 'trip_count' => 0];

    protected function getFilters(): ?array
    {
        return $this->shiftTrendFilters();
    }

    /**
     * Tổng giờ khai thác kỳ hiện tại, so sánh kỳ trước và trung bình giờ/xe, giờ/chuyến.
     */
    public function getDescription(): string|Htmlable|null
    {
        $bucketMeta = $this->resolveShiftTrendBuckets($this->filter);
        $periodLabel = $bucketMeta['periodLabel'];
        $comparisonTitle = $bucketMeta['comparisonTitle'];

        $datasets = $this->getCachedData()['datasets'];
        $parts = collect($datasets)->map(function (array $dataset) use ($periodLabel, $comparisonTitle): string {
            $values = array_values($dataset['data']);
            $count = count($values);
            $current = $count > 0 ? (float) $values[$count - 1] : 0.0;
            $previous = $count > 1 ? (float) $values[$count - 2] : 0.0;
            $delta = round($current - $previous, 1);

            $deltaClass = $delta > 0 ? 'text-emerald-600' : ($delta < 0 ? 'text-rose-600' : 'text-gray-400');
            $deltaText = $delta == 0.0
                ? '±0'
                : ($delta > 0 ? '+' : '-').number_format(abs($delta), 1, ',', '.');

            return '<span class="mr-3 inline-flex items-baseline gap-1" title="'.e($comparisonTitle).'"><span class="size-2 self-center rounded-full" style="background: '.e($dataset['borderColor']).'"></span>'
                .e($dataset['label']).' '.e($periodLabel).' <b class="text-sm font-bold tabular-nums text-gray-950 dark:text-white">'.number_format($current, 1, ',', '.').' giờ</b>'
                .'<span class="text-xs tabular-nums '.$deltaClass.'">'.$deltaText.'</span></span>';
        });

        $totalCurrent = 0.0;
        if (isset($datasets[0]['data']) && is_array($datasets[0]['data']) && $datasets[0]['data'] !== []) {
            $totalCurrent = (float) end($datasets[0]['data']);
        }

        $activeVehicles = $this->latestPeriodMeta['active_vehicles'];
        $tripCount = $this->latestPeriodMeta['trip_count'];

        if ($activeVehicles > 0 || $tripCount > 0) {
            $efficiencyBits = [];
            if ($activeVehicles > 0) {
                $efficiencyBits[] = 'TB '.number_format($totalCurrent / $activeVehicles, 1, ',', '.').' giờ/xe ('.$activeVehicles.' xe)';
            }
            if ($tripCount > 0) {
                $efficiencyBits[] = number_format($totalCurrent / $tripCount, 1, ',', '.').' giờ/chuyến';
            }

            $parts->push(
                '<span class="inline-flex items-baseline gap-1 text-xs text-gray-500 dark:text-gray-400">· '
                .e(implode(' · ', $efficiencyBits))
                .'</span>'
            );
        }

        return new HtmlString($parts->implode(''));
    }

    protected function getData(): array
    {
        ['windowStart' => $windowStart, 'windowEnd' => $windowEnd, 'buckets' => $buckets] = $this->resolveShiftTrendBuckets($this->filter);

        $trips = Trip::query()
            ->with('vehicle:id,type')
            ->where('status', '!=', TripStatus::Cancelled->value)
            ->whereNotNull('started_at')
            ->whereNotNull('completed_at')
            ->where('completed_at', '>=', $windowStart)
            ->where('completed_at', '<', $windowEnd)
            ->get(['vehicle_id', 'started_at', 'completed_at']);

        $labels = array_column($buckets, 'label');
        $bucketCount = count($buckets);
        $lastIdx = $bucketCount - 1;

        $totalMinutes = array_fill(0, $bucketCount, 0.0);
        $companyMinutes = array_fill(0, $bucketCount, 0.0);
        $rentMinutes = array_fill(0, $bucketCount, 0.0);
        $latestVehicles = [];
        $latestTrips = 0;

        foreach ($trips as $trip) {
            if ($trip->started_at === null || $trip->completed_at === null || $trip->completed_at->lt($trip->started_at)) {
                continue;
            }

            $minutes = (float) $trip->started_at->diffInMinutes($trip->completed_at);
            if ($minutes <= 0.0) {
                continue;
            }

            $isRent = $trip->vehicle?->type === VehicleOwnerType::Rent;

            foreach ($buckets as $idx => $bucket) {
                if ($trip->completed_at->gte($bucket['start']) && $trip->completed_at->lt($bucket['end'])) {
                    $totalMinutes[$idx] += $minutes;
                    if ($isRent) {
                        $rentMinutes[$idx] += $minutes;
                    } else {
                        $companyMinutes[$idx] += $minutes;
                    }

                    if ($idx === $lastIdx) {
                        $latestTrips++;
                        if ($trip->vehicle_id !== null) {
                            $latestVehicles[$trip->vehicle_id] = true;
                        }
                    }

                    break;
                }
            }
        }

        $this->latestPeriodMeta = [
            'active_vehicles' => count($latestVehicles),
            'trip_count' => $latestTrips,
        ];

        $toHours = static fn (array $items): array => array_map(static fn (float $m): float => round($m / 60, 1), $items);

        return [
            'datasets' => [
                $this->buildTrendLineDataset('Tổng khai thác', $toHours($totalMinutes), '#6366f1', 'rgba(99, 102, 241, 0.10)', true),
                $this->buildTrendLineDataset('Xe công ty', $toHours($companyMinutes), '#0ea5e9', 'rgba(14, 165, 233, 0.05)', false),
                $this->buildTrendLineDataset('Xe thuê ngoài', $toHours($rentMinutes), '#f97316', 'rgba(249, 115, 22, 0.05)', false),
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
