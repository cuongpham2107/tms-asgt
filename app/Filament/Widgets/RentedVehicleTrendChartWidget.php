<?php

namespace App\Filament\Widgets;

use App\Enums\TripStatus;
use App\Enums\VehicleOwnerType;
use App\Enums\VehicleType;
use App\Filament\Widgets\Concerns\BuildsShiftTrendBuckets;
use App\Filament\Widgets\Concerns\StylesDashboardChart;
use App\Models\Trip;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Widgets\ChartWidget;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

class RentedVehicleTrendChartWidget extends ChartWidget
{
    use BuildsShiftTrendBuckets;
    use HasWidgetShield;
    use StylesDashboardChart;

    protected ?string $heading = 'Xe thuê ngoài (ca 8h–8h+)';

    public ?string $filter = '7days';

    /** @var array{normal_trips: int, special_trips: int} */
    protected array $latestTripCounts = ['normal_trips' => 0, 'special_trips' => 0];

    protected function getFilters(): ?array
    {
        return $this->shiftTrendFilters();
    }

    /**
     * Số đầu xe thuê ngoài (tổng / xe thường / xe đặc biệt) kỳ hiện tại kèm so sánh kỳ trước và tổng lượt chuyến.
     */
    public function getDescription(): string|Htmlable|null
    {
        $bucketMeta = $this->resolveShiftTrendBuckets($this->filter);
        $periodLabel = $bucketMeta['periodLabel'];
        $comparisonTitle = $bucketMeta['comparisonTitle'];

        $parts = collect($this->getCachedData()['datasets'])->map(function (array $dataset) use ($periodLabel, $comparisonTitle): string {
            $values = array_values($dataset['data']);
            $count = count($values);
            $current = $count > 0 ? (int) $values[$count - 1] : 0;
            $previous = $count > 1 ? (int) $values[$count - 2] : 0;
            $delta = $current - $previous;

            $deltaClass = $delta > 0 ? 'text-amber-600' : ($delta < 0 ? 'text-emerald-600' : 'text-gray-400');
            $deltaText = $delta === 0 ? '±0' : sprintf('%+d', $delta);

            return '<span class="mr-3 inline-flex items-baseline gap-1" title="'.e($comparisonTitle).'"><span class="size-2 self-center rounded-full" style="background: '.e($dataset['borderColor']).'"></span>'
                .e($dataset['label']).' '.e($periodLabel).' <b class="text-sm font-bold tabular-nums text-gray-950 dark:text-white">'.$current.' xe</b>'
                .'<span class="text-xs tabular-nums '.$deltaClass.'">'.$deltaText.'</span></span>';
        });

        $totalTrips = $this->latestTripCounts['normal_trips'] + $this->latestTripCounts['special_trips'];
        if ($totalTrips > 0) {
            $parts->push(
                '<span class="inline-flex items-baseline gap-1 text-xs text-gray-500 dark:text-gray-400">· '
                .e($totalTrips.' lượt chuyến ('.$this->latestTripCounts['normal_trips'].' thường · '.$this->latestTripCounts['special_trips'].' đặc biệt)')
                .'</span>'
            );
        }

        return new HtmlString($parts->implode(''));
    }

    protected function getData(): array
    {
        ['windowStart' => $windowStart, 'windowEnd' => $windowEnd, 'buckets' => $buckets] = $this->resolveShiftTrendBuckets($this->filter);

        $trips = Trip::query()
            ->join('vehicles', 'vehicles.id', '=', 'trips.vehicle_id')
            ->where('vehicles.type', VehicleOwnerType::Rent->value)
            ->where('trips.status', '!=', TripStatus::Cancelled->value)
            ->where(function ($q) use ($windowStart, $windowEnd): void {
                $q->where(fn ($sq) => $sq->where('trips.started_at', '>=', $windowStart)->where('trips.started_at', '<', $windowEnd))
                    ->orWhere(fn ($sq) => $sq->whereNull('trips.started_at')->where('trips.completed_at', '>=', $windowStart)->where('trips.completed_at', '<', $windowEnd))
                    ->orWhere(fn ($sq) => $sq->whereNull('trips.started_at')->whereNull('trips.completed_at')->where('trips.created_at', '>=', $windowStart)->where('trips.created_at', '<', $windowEnd));
            })
            ->select([
                'trips.vehicle_id',
                'trips.started_at',
                'trips.completed_at',
                'trips.created_at',
                'vehicles.vehicle_type as rented_vehicle_type',
            ])
            ->get();

        $labels = array_column($buckets, 'label');
        $bucketCount = count($buckets);
        $lastIdx = $bucketCount - 1;

        /** @var array<int, array<int, true>> $normalVehicles */
        $normalVehicles = array_fill(0, $bucketCount, []);
        /** @var array<int, array<int, true>> $specialVehicles */
        $specialVehicles = array_fill(0, $bucketCount, []);

        $latestNormalTrips = 0;
        $latestSpecialTrips = 0;

        foreach ($trips as $trip) {
            $effectiveAt = $trip->started_at ?? $trip->completed_at ?? $trip->created_at;
            if ($effectiveAt === null || $trip->vehicle_id === null) {
                continue;
            }

            $isNormal = $trip->getAttribute('rented_vehicle_type') === VehicleType::Normal->value;

            foreach ($buckets as $idx => $bucket) {
                if ($effectiveAt->gte($bucket['start']) && $effectiveAt->lt($bucket['end'])) {
                    if ($isNormal) {
                        $normalVehicles[$idx][(int) $trip->vehicle_id] = true;
                        if ($idx === $lastIdx) {
                            $latestNormalTrips++;
                        }
                    } else {
                        $specialVehicles[$idx][(int) $trip->vehicle_id] = true;
                        if ($idx === $lastIdx) {
                            $latestSpecialTrips++;
                        }
                    }

                    break;
                }
            }
        }

        $this->latestTripCounts = [
            'normal_trips' => $latestNormalTrips,
            'special_trips' => $latestSpecialTrips,
        ];

        $normalCounts = [];
        $specialCounts = [];
        $totalCounts = [];

        for ($i = 0; $i < $bucketCount; $i++) {
            $n = count($normalVehicles[$i]);
            $s = count($specialVehicles[$i]);
            $normalCounts[] = $n;
            $specialCounts[] = $s;
            $totalCounts[] = $n + $s;
        }

        return [
            'datasets' => [
                $this->buildTrendLineDataset('Tổng xe thuê', $totalCounts, '#0ea5e9', 'rgba(14, 165, 233, 0.10)', true),
                $this->buildTrendLineDataset('Xe thường', $normalCounts, '#10b981', 'rgba(16, 185, 129, 0.06)', true),
                $this->buildTrendLineDataset('Xe đặc biệt', $specialCounts, '#f97316', 'rgba(249, 115, 22, 0.06)', true),
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
