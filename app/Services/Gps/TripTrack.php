<?php

namespace App\Services\Gps;

use App\Models\Trip;
use App\Models\VehicleGpsPoint;
use App\Services\TripKmCalculatorService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Hành trình của chuyến để vẽ bản đồ: các đoạn liên tiếp, đánh dấu có hàng / không hàng.
 */
class TripTrack
{
    private const MAX_POINTS = 1500;

    public function __construct(private readonly TripKmCalculatorService $calculator) {}

    /**
     * @return array<int, array{loaded: bool, points: array<int, array{0: float, 1: float}>}>
     */
    public function segments(Trip $trip): array
    {
        if ($trip->started_at === null || $trip->vehicle_id === null) {
            return [];
        }

        $points = $this->points($trip);
        if ($points->count() < 2) {
            return [];
        }

        $loaded = $this->calculator->loadedIntervals($trip);
        $isLoaded = fn (VehicleGpsPoint $p): bool => $loaded->contains(
            fn (array $i) => $p->recorded_at->gte($i[0]) && $p->recorded_at->lte($i[1])
        );

        $segments = [];
        $current = null;

        foreach ($points as $point) {
            $flag = $isLoaded($point);
            $coordinate = [$point->lat, $point->lng];

            if ($current === null || $current['loaded'] !== $flag) {
                if ($current !== null) {
                    $current['points'][] = $coordinate;
                    $segments[] = $current;
                }
                $current = ['loaded' => $flag, 'points' => []];
            }

            $current['points'][] = $coordinate;
        }

        $segments[] = $current;

        return array_values(array_filter($segments, fn (array $s) => count($s['points']) >= 2));
    }

    /**
     * @return Collection<int, VehicleGpsPoint>
     */
    private function points(Trip $trip): Collection
    {
        $from = $trip->started_at->toImmutable();
        $to = ($trip->completed_at ?? $trip->cancelled_at ?? CarbonImmutable::now())->toImmutable();

        $base = VehicleGpsPoint::query()
            ->where('vehicle_id', $trip->vehicle_id)
            ->whereBetween('recorded_at', [$from, $to])
            ->where('mocked', false)
            ->orderBy('recorded_at');

        $points = (clone $base)->where('source', VehicleGpsPoint::SOURCE_PHONE)->get(['recorded_at', 'lat', 'lng']);
        if ($points->count() < 2) {
            $points = (clone $base)->where('source', VehicleGpsPoint::SOURCE_EUP)->get(['recorded_at', 'lat', 'lng']);
        }

        // ponytail: lấy mẫu đều để bản đồ nhẹ; chuyến cực dài sẽ mất chi tiết khúc cua.
        $step = (int) max(1, ceil($points->count() / self::MAX_POINTS));

        return $points->filter(fn ($p, int $i) => $i % $step === 0 || $i === $points->count() - 1)->values();
    }
}
