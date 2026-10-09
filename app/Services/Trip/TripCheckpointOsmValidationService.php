<?php

namespace App\Services\Trip;

use App\Models\Trip;
use App\Models\TripCheckpoint;
use App\Services\OsrmService;
use Illuminate\Support\Collection;

class TripCheckpointOsmValidationService
{
    public const STATUS_VALID = 'valid';

    public const STATUS_WARNING = 'warning';

    public const STATUS_DANGER = 'danger';

    public const STATUS_NO_GPS = 'no_gps';

    public const STATUS_UNKNOWN = 'unknown';

    public const STATUS_NO_DATA = 'no_data';

    public function __construct(
        protected OsrmService $osrmService,
    ) {}

    /**
     * Đối soát toàn bộ các mốc hành trình của một chuyến xe.
     *
     * @return array{
     *     overall_status: string,
     *     total_driver_km: float,
     *     total_osm_km: float,
     *     diff_km: float,
     *     diff_percent: float,
     *     warning_count: int,
     *     danger_count: int,
     *     valid_count: int,
     *     segments: array<int, array{
     *         checkpoint_id: int,
     *         prev_checkpoint_id: ?int,
     *         prev_type_label: ?string,
     *         curr_type_label: string,
     *         driver_km: ?float,
     *         prev_km: ?float,
     *         driver_delta_km: ?float,
     *         osm_km: ?float,
     *         diff_km: ?float,
     *         diff_percent: ?float,
     *         status: string,
     *         message: string,
     *         has_gps: bool
     *     }>
     * }
     */
    public function validateTrip(Trip $trip): array
    {
        $checkpoints = $trip->checkpoints()
            ->orderBy('occurred_at', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        return $this->validateCheckpoints($checkpoints);
    }

    /**
     * Đối soát danh sách mốc hành trình đã được sắp xếp theo thời gian tăng dần.
     *
     * @param  Collection<int, TripCheckpoint>  $checkpoints
     * @return array{
     *     overall_status: string,
     *     total_driver_km: float,
     *     total_osm_km: float,
     *     diff_km: float,
     *     diff_percent: float,
     *     warning_count: int,
     *     danger_count: int,
     *     valid_count: int,
     *     segments: array<int, array{
     *         checkpoint_id: int,
     *         prev_checkpoint_id: ?int,
     *         prev_type_label: ?string,
     *         curr_type_label: string,
     *         driver_km: ?float,
     *         prev_km: ?float,
     *         driver_delta_km: ?float,
     *         osm_km: ?float,
     *         diff_km: ?float,
     *         diff_percent: ?float,
     *         status: string,
     *         message: string,
     *         has_gps: bool
     *     }>
     * }
     */
    public function validateCheckpoints(Collection $checkpoints): array
    {
        $segments = [];
        $warningCount = 0;
        $dangerCount = 0;
        $validCount = 0;
        $totalDriverKm = 0.0;
        $totalOsmKm = 0.0;

        /** @var ?TripCheckpoint $prev */
        $prev = null;

        foreach ($checkpoints as $curr) {
            if ($prev !== null) {
                $segment = $this->validateSegment($prev, $curr);
                $segments[$curr->id] = $segment;

                if ($segment['status'] === self::STATUS_DANGER) {
                    $dangerCount++;
                } elseif ($segment['status'] === self::STATUS_WARNING) {
                    $warningCount++;
                } elseif ($segment['status'] === self::STATUS_VALID) {
                    $validCount++;
                }

                if ($segment['driver_delta_km'] !== null && $segment['driver_delta_km'] > 0) {
                    $totalDriverKm += $segment['driver_delta_km'];
                }

                if ($segment['osm_km'] !== null) {
                    $totalOsmKm += $segment['osm_km'];
                }
            }

            $prev = $curr;
        }

        $diffKm = round($totalDriverKm - $totalOsmKm, 1);
        $diffPercent = $totalOsmKm > 0.05 ? round(($diffKm / $totalOsmKm) * 100, 1) : 0.0;

        $overallStatus = self::STATUS_NO_DATA;
        if ($dangerCount > 0) {
            $overallStatus = self::STATUS_DANGER;
        } elseif ($warningCount > 0) {
            $overallStatus = self::STATUS_WARNING;
        } elseif ($validCount > 0) {
            $overallStatus = self::STATUS_VALID;
        }

        return [
            'overall_status' => $overallStatus,
            'total_driver_km' => round($totalDriverKm, 1),
            'total_osm_km' => round($totalOsmKm, 1),
            'diff_km' => $diffKm,
            'diff_percent' => $diffPercent,
            'warning_count' => $warningCount,
            'danger_count' => $dangerCount,
            'valid_count' => $validCount,
            'segments' => $segments,
        ];
    }

    /**
     * Đối soát 1 chặng giữa mốc trước và mốc hiện tại.
     *
     * @return array{
     *     checkpoint_id: int,
     *     prev_checkpoint_id: ?int,
     *     prev_type_label: ?string,
     *     curr_type_label: string,
     *     driver_km: ?float,
     *     prev_km: ?float,
     *     driver_delta_km: ?float,
     *     osm_km: ?float,
     *     diff_km: ?float,
     *     diff_percent: ?float,
     *     status: string,
     *     message: string,
     *     has_gps: bool
     * }
     */
    public function validateSegment(TripCheckpoint $prev, TripCheckpoint $curr): array
    {
        $prevKm = $prev->km_reading !== null ? (float) $prev->km_reading : null;
        $currKm = $curr->km_reading !== null ? (float) $curr->km_reading : null;

        $driverDeltaKm = ($prevKm !== null && $currKm !== null)
            ? round($currKm - $prevKm, 1)
            : null;

        $prevLat = $prev->gps_lat !== null ? (float) $prev->gps_lat : null;
        $prevLng = $prev->gps_lng !== null ? (float) $prev->gps_lng : null;
        $currLat = $curr->gps_lat !== null ? (float) $curr->gps_lat : null;
        $currLng = $curr->gps_lng !== null ? (float) $curr->gps_lng : null;

        $hasGps = $prevLat && $prevLng && $currLat && $currLng
            && abs($prevLat) > 0.0001 && abs($prevLng) > 0.0001
            && abs($currLat) > 0.0001 && abs($currLng) > 0.0001;

        $prevTypeLabel = $prev->checkpoint_type?->getLabel();
        $currTypeLabel = $curr->checkpoint_type?->getLabel() ?? 'Mốc hành trình';

        // 1. Trường hợp thiếu số km
        if ($driverDeltaKm === null) {
            return [
                'checkpoint_id' => $curr->id,
                'prev_checkpoint_id' => $prev->id,
                'prev_type_label' => $prevTypeLabel,
                'curr_type_label' => $currTypeLabel,
                'driver_km' => $currKm,
                'prev_km' => $prevKm,
                'driver_delta_km' => null,
                'osm_km' => null,
                'diff_km' => null,
                'diff_percent' => null,
                'status' => self::STATUS_UNKNOWN,
                'message' => 'Chưa có đủ số km để đối soát',
                'has_gps' => $hasGps,
            ];
        }

        // 2. Trường hợp km bị lùi (số sau < số trước)
        if ($driverDeltaKm < 0) {
            return [
                'checkpoint_id' => $curr->id,
                'prev_checkpoint_id' => $prev->id,
                'prev_type_label' => $prevTypeLabel,
                'curr_type_label' => $currTypeLabel,
                'driver_km' => $currKm,
                'prev_km' => $prevKm,
                'driver_delta_km' => $driverDeltaKm,
                'osm_km' => null,
                'diff_km' => null,
                'diff_percent' => null,
                'status' => self::STATUS_DANGER,
                'message' => sprintf('Lỗi: Km bị lùi %.1f km (từ %.1f xuống %.1f)', abs($driverDeltaKm), $prevKm, $currKm),
                'has_gps' => $hasGps,
            ];
        }

        // 3. Trường hợp không có toạ độ GPS
        if (! $hasGps) {
            return [
                'checkpoint_id' => $curr->id,
                'prev_checkpoint_id' => $prev->id,
                'prev_type_label' => $prevTypeLabel,
                'curr_type_label' => $currTypeLabel,
                'driver_km' => $currKm,
                'prev_km' => $prevKm,
                'driver_delta_km' => $driverDeltaKm,
                'osm_km' => null,
                'diff_km' => null,
                'diff_percent' => null,
                'status' => self::STATUS_NO_GPS,
                'message' => sprintf('+%.1f km (Không có GPS đối soát)', $driverDeltaKm),
                'has_gps' => false,
            ];
        }

        // 4. Có GPS: Tính khoảng cách đường bộ bằng OSRM
        $osmKm = $this->getRoadDistanceKm($prevLat, $prevLng, $currLat, $currLng);
        $diffKm = round($driverDeltaKm - $osmKm, 1);
        $diffPercent = $osmKm > 0.05 ? round(($diffKm / $osmKm) * 100, 1) : 0.0;

        // Vị trí không thay đổi đáng kể (đứng yên hoặc cùng kho bãi)
        if ($osmKm < 0.3) {
            if ($driverDeltaKm <= 1.0) {
                return [
                    'checkpoint_id' => $curr->id,
                    'prev_checkpoint_id' => $prev->id,
                    'prev_type_label' => $prevTypeLabel,
                    'curr_type_label' => $currTypeLabel,
                    'driver_km' => $currKm,
                    'prev_km' => $prevKm,
                    'driver_delta_km' => $driverDeltaKm,
                    'osm_km' => $osmKm,
                    'diff_km' => $diffKm,
                    'diff_percent' => 0.0,
                    'status' => self::STATUS_VALID,
                    'message' => sprintf('Tại chỗ (+%.1f km)', $driverDeltaKm),
                    'has_gps' => true,
                ];
            }

            if ($driverDeltaKm <= 5.0) {
                return [
                    'checkpoint_id' => $curr->id,
                    'prev_checkpoint_id' => $prev->id,
                    'prev_type_label' => $prevTypeLabel,
                    'curr_type_label' => $currTypeLabel,
                    'driver_km' => $currKm,
                    'prev_km' => $prevKm,
                    'driver_delta_km' => $driverDeltaKm,
                    'osm_km' => $osmKm,
                    'diff_km' => $diffKm,
                    'diff_percent' => 0.0,
                    'status' => self::STATUS_WARNING,
                    'message' => sprintf('Cảnh báo: Xe tại chỗ nhưng tăng +%.1f km', $driverDeltaKm),
                    'has_gps' => true,
                ];
            }

            return [
                'checkpoint_id' => $curr->id,
                'prev_checkpoint_id' => $prev->id,
                'prev_type_label' => $prevTypeLabel,
                'curr_type_label' => $currTypeLabel,
                'driver_km' => $currKm,
                'prev_km' => $prevKm,
                'driver_delta_km' => $driverDeltaKm,
                'osm_km' => $osmKm,
                'diff_km' => $diffKm,
                'diff_percent' => 0.0,
                'status' => self::STATUS_DANGER,
                'message' => sprintf('Bất thường: Không di chuyển nhưng tăng +%.1f km (OSM: ~0 km)', $driverDeltaKm),
                'has_gps' => true,
            ];
        }

        // Có di chuyển thực tế (osmKm >= 0.3)
        // Ngưỡng hợp lệ: chênh lệch <= 3km hoặc <= 15%
        if (abs($diffKm) <= 3.0 || abs($diffPercent) <= 15.0) {
            return [
                'checkpoint_id' => $curr->id,
                'prev_checkpoint_id' => $prev->id,
                'prev_type_label' => $prevTypeLabel,
                'curr_type_label' => $currTypeLabel,
                'driver_km' => $currKm,
                'prev_km' => $prevKm,
                'driver_delta_km' => $driverDeltaKm,
                'osm_km' => $osmKm,
                'diff_km' => $diffKm,
                'diff_percent' => $diffPercent,
                'status' => self::STATUS_VALID,
                'message' => sprintf('Khớp lộ trình (+%.1f km / OSM: %.1f km)', $driverDeltaKm, $osmKm),
                'has_gps' => true,
            ];
        }

        // Cảnh báo lệch vừa phải (lệch +15% đến +35%, hoặc lệch 3km đến 10km)
        if (($diffPercent > 15.0 && $diffPercent <= 35.0) || ($diffKm > 3.0 && $diffKm <= 10.0)) {
            return [
                'checkpoint_id' => $curr->id,
                'prev_checkpoint_id' => $prev->id,
                'prev_type_label' => $prevTypeLabel,
                'curr_type_label' => $currTypeLabel,
                'driver_km' => $currKm,
                'prev_km' => $prevKm,
                'driver_delta_km' => $driverDeltaKm,
                'osm_km' => $osmKm,
                'diff_km' => $diffKm,
                'diff_percent' => $diffPercent,
                'status' => self::STATUS_WARNING,
                'message' => sprintf('Cảnh báo: Lệch +%.1f km (+%.0f%% so với OSM: %.1f km)', $diffKm, $diffPercent, $osmKm),
                'has_gps' => true,
            ];
        }

        // Cảnh báo nhập thiếu km (> 15% và lệch > 3km)
        if ($diffPercent < -15.0 && abs($diffKm) > 3.0) {
            return [
                'checkpoint_id' => $curr->id,
                'prev_checkpoint_id' => $prev->id,
                'prev_type_label' => $prevTypeLabel,
                'curr_type_label' => $currTypeLabel,
                'driver_km' => $currKm,
                'prev_km' => $prevKm,
                'driver_delta_km' => $driverDeltaKm,
                'osm_km' => $osmKm,
                'diff_km' => $diffKm,
                'diff_percent' => $diffPercent,
                'status' => self::STATUS_WARNING,
                'message' => sprintf('Cảnh báo: Khai thiếu %.1f km (%.0f%% so với OSM: %.1f km)', abs($diffKm), $diffPercent, $osmKm),
                'has_gps' => true,
            ];
        }

        // Bất thường lớn (lệch > 35% hoặc lệch > 10km)
        return [
            'checkpoint_id' => $curr->id,
            'prev_checkpoint_id' => $prev->id,
            'prev_type_label' => $prevTypeLabel,
            'curr_type_label' => $currTypeLabel,
            'driver_km' => $currKm,
            'prev_km' => $prevKm,
            'driver_delta_km' => $driverDeltaKm,
            'osm_km' => $osmKm,
            'diff_km' => $diffKm,
            'diff_percent' => $diffPercent,
            'status' => self::STATUS_DANGER,
            'message' => sprintf('Bất thường: Lệch +%.1f km (+%.0f%% so với OSM: %.1f km)', $diffKm, $diffPercent, $osmKm),
            'has_gps' => true,
        ];
    }

    /**
     * Lấy khoảng cách đường bộ bằng OSRM (với Fallback đường chim bay * hệ số uốn lượn 1.30).
     */
    public function getRoadDistanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $straightLineKm = $this->calculateHaversineKm($lat1, $lng1, $lat2, $lng2);

        // Khoảng cách chim bay dưới 50m xem như cùng 1 vị trí
        if ($straightLineKm < 0.05) {
            return 0.0;
        }

        try {
            $route = $this->osrmService->getRoute($lat1, $lng1, $lat2, $lng2);
            if (! empty($route['success']) && isset($route['data']['distance'])) {
                return round($route['data']['distance'] / 1000, 1);
            }
        } catch (\Throwable) {
            // Sử dụng fallback nếu OSRM lỗi mạng hoặc timeout
        }

        // Fallback: Haversine * Hệ số uốn lượn đường bộ Việt Nam (1.30)
        return round($straightLineKm * 1.30, 1);
    }

    /**
     * Tính khoảng cách đường chim bay theo công thức Haversine (km).
     */
    public function calculateHaversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadiusKm = 6371.0;

        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) * sin($dLat / 2) +
            cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
            sin($dLng / 2) * sin($dLng / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadiusKm * $c;
    }
}
