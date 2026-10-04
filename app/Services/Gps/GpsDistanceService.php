<?php

namespace App\Services\Gps;

use App\Models\VehicleGpsPoint;
use App\Services\OsrmService;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Quãng đường xe chạy trong một khoảng thời gian, tính từ điểm GPS đã lưu.
 *
 * Ưu tiên điểm điện thoại; khoảng điện thoại im lặng được lấp bằng điểm hộp đen EUP;
 * khoảng trống dài không có điểm nào được nối theo đường bộ (OSRM).
 */
class GpsDistanceService
{
    private const EARTH_RADIUS_M = 6371000;

    public function __construct(private readonly OsrmService $osrm) {}

    public function distance(?int $vehicleId, CarbonInterface $from, CarbonInterface $to, ?int $driverId = null): DistanceResult
    {
        if ($to->lte($from)) {
            return DistanceResult::empty();
        }

        $phonePoints = $this->phonePoints($vehicleId, $from, $to, $driverId);
        $eupPoints = $this->eupPoints($vehicleId, $from, $to);

        $track = $this->mergeTrack($phonePoints, $eupPoints, $from, $to);

        if ($track->isEmpty()) {
            return new DistanceResult(0.0, 0.0, null, $this->hasMocked($vehicleId, $from, $to, $driverId), 0);
        }

        $kept = $this->filterNoise($track);
        [$meters, $osrmSeconds] = $this->sumDistance($kept);

        $usesPhone = $kept->contains(fn (VehicleGpsPoint $p) => $p->source === VehicleGpsPoint::SOURCE_PHONE);
        $usesEup = $kept->contains(fn (VehicleGpsPoint $p) => $p->source === VehicleGpsPoint::SOURCE_EUP);

        return new DistanceResult(
            km: round($meters / 1000, 2),
            coverage: $this->coverage($track, $from, $to),
            source: match (true) {
                $usesPhone && $usesEup => DistanceResult::SOURCE_MIXED,
                $usesPhone => DistanceResult::SOURCE_PHONE,
                default => DistanceResult::SOURCE_EUP,
            },
            hasMocked: $this->hasMocked($vehicleId, $from, $to, $driverId),
            osrmFilledSeconds: $osrmSeconds,
        );
    }

    /**
     * @return Collection<int, VehicleGpsPoint>
     */
    private function phonePoints(?int $vehicleId, CarbonInterface $from, CarbonInterface $to, ?int $driverId): Collection
    {
        return VehicleGpsPoint::query()
            ->where('source', VehicleGpsPoint::SOURCE_PHONE)
            ->when($driverId !== null,
                fn ($q) => $q->where('driver_id', $driverId),
                fn ($q) => $q->where('vehicle_id', $vehicleId))
            ->whereBetween('recorded_at', [$from, $to])
            ->where('mocked', false)
            ->where(fn ($q) => $q->whereNull('accuracy')->orWhere('accuracy', '<=', config('gps.max_accuracy_m')))
            ->orderBy('recorded_at')
            ->get();
    }

    /**
     * @return Collection<int, VehicleGpsPoint>
     */
    private function eupPoints(?int $vehicleId, CarbonInterface $from, CarbonInterface $to): Collection
    {
        if ($vehicleId === null) {
            return collect();
        }

        return VehicleGpsPoint::query()
            ->where('source', VehicleGpsPoint::SOURCE_EUP)
            ->where('vehicle_id', $vehicleId)
            ->whereBetween('recorded_at', [$from, $to])
            ->orderBy('recorded_at')
            ->get();
    }

    private function hasMocked(?int $vehicleId, CarbonInterface $from, CarbonInterface $to, ?int $driverId): bool
    {
        return VehicleGpsPoint::query()
            ->where('source', VehicleGpsPoint::SOURCE_PHONE)
            ->when($driverId !== null,
                fn ($q) => $q->where('driver_id', $driverId),
                fn ($q) => $q->where('vehicle_id', $vehicleId))
            ->whereBetween('recorded_at', [$from, $to])
            ->where('mocked', true)
            ->exists();
    }

    /**
     * Điểm điện thoại làm trục; chèn điểm EUP vào những khoảng điện thoại im lặng quá lâu.
     *
     * @param  Collection<int, VehicleGpsPoint>  $phone
     * @param  Collection<int, VehicleGpsPoint>  $eup
     * @return Collection<int, VehicleGpsPoint>
     */
    private function mergeTrack(Collection $phone, Collection $eup, CarbonInterface $from, CarbonInterface $to): Collection
    {
        if ($phone->isEmpty()) {
            return $eup->values();
        }

        $gapSeconds = config('gps.gap_fill_seconds');
        $boundaries = collect([$from])->merge($phone->pluck('recorded_at'))->push($to)->values();

        $fillers = collect();
        for ($i = 0; $i < $boundaries->count() - 1; $i++) {
            $start = $boundaries[$i];
            $end = $boundaries[$i + 1];

            if ($start->diffInSeconds($end) > $gapSeconds) {
                $fillers = $fillers->merge($eup->filter(fn (VehicleGpsPoint $p) => $p->recorded_at->gt($start) && $p->recorded_at->lt($end)));
            }
        }

        return $phone->merge($fillers)->sortBy(fn (VehicleGpsPoint $p) => $p->recorded_at->getTimestamp())->values();
    }

    /**
     * Bỏ điểm nhảy vô lý và nhiễu khi xe đứng yên. Điểm bị bỏ không làm mốc cho điểm sau.
     *
     * @param  Collection<int, VehicleGpsPoint>  $track
     * @return Collection<int, VehicleGpsPoint>
     */
    private function filterNoise(Collection $track): Collection
    {
        $kept = collect();
        $previous = null;

        foreach ($track as $point) {
            if ($previous === null) {
                $kept->push($point);
                $previous = $point;

                continue;
            }

            $meters = $this->haversine($previous, $point);
            $seconds = max(1, $previous->recorded_at->diffInSeconds($point->recorded_at));
            $impliedKmh = $meters / $seconds * 3.6;

            if ($impliedKmh > config('gps.max_speed_kmh')) {
                continue;
            }

            $isStationaryJitter = $point->speed !== null
                ? $point->speed < config('gps.stationary_speed_kmh') && $meters < config('gps.stationary_radius_m')
                : $meters < config('gps.min_move_m');

            if ($isStationaryJitter) {
                continue;
            }

            $kept->push($point);
            $previous = $point;
        }

        return $kept;
    }

    /**
     * @param  Collection<int, VehicleGpsPoint>  $points
     * @return array{0: float, 1: int} [mét, số giây được nối bằng OSRM]
     */
    private function sumDistance(Collection $points): array
    {
        $meters = 0.0;
        $osrmSeconds = 0;

        for ($i = 1; $i < $points->count(); $i++) {
            $a = $points[$i - 1];
            $b = $points[$i];
            $straight = $this->haversine($a, $b);
            $gap = $a->recorded_at->diffInSeconds($b->recorded_at);

            if ($gap > config('gps.osrm_gap_seconds')) {
                $road = $this->roadDistance($a, $b);
                if ($road !== null) {
                    $meters += max($road, $straight);
                    $osrmSeconds += (int) $gap;

                    continue;
                }
            }

            $meters += $straight;
        }

        return [$meters, $osrmSeconds];
    }

    private function roadDistance(VehicleGpsPoint $a, VehicleGpsPoint $b): ?float
    {
        $result = $this->osrm->getRoute($a->lat, $a->lng, $b->lat, $b->lng);

        return ($result['success'] ?? false) ? (float) $result['data']['distance'] : null;
    }

    /**
     * Phần trăm thời gian có điểm GPS; khoảng giữa 2 điểm ≤ gap_fill_seconds được coi là có phủ.
     *
     * @param  Collection<int, VehicleGpsPoint>  $track
     */
    private function coverage(Collection $track, CarbonInterface $from, CarbonInterface $to): float
    {
        $window = max(1, $from->diffInSeconds($to));
        $gapLimit = config('gps.gap_fill_seconds');

        $boundaries = collect([$from])->merge($track->pluck('recorded_at'))->push($to)->values();
        $uncovered = 0;

        for ($i = 0; $i < $boundaries->count() - 1; $i++) {
            $gap = $boundaries[$i]->diffInSeconds($boundaries[$i + 1]);
            if ($gap > $gapLimit) {
                $uncovered += $gap;
            }
        }

        return round(max(0, 100 - $uncovered / $window * 100), 2);
    }

    private function haversine(VehicleGpsPoint $a, VehicleGpsPoint $b): float
    {
        $lat1 = deg2rad($a->lat);
        $lat2 = deg2rad($b->lat);
        $dLat = $lat2 - $lat1;
        $dLng = deg2rad($b->lng - $a->lng);

        $h = sin($dLat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($dLng / 2) ** 2;

        return 2 * self::EARTH_RADIUS_M * asin(min(1, sqrt($h)));
    }
}
