<?php

namespace App\Services\Trip;

use App\Enums\CheckpointType;
use App\Enums\EupCarStatus;
use App\Enums\GeoCheckStatus;
use App\Exceptions\CheckpointGeofenceException;
use App\Models\Location;
use App\Models\Order;
use App\Models\OrderDeliveryPoint;
use App\Models\Trip;
use App\Models\Vehicle;
use Carbon\CarbonInterface;

/**
 * Kiểm tra vị trí khi tài xế gửi mốc hành trình.
 *
 * Lớp A: GPS điện thoại phải ở gần xe (vị trí EUP).
 * Lớp B: với mốc lấy/giao hàng, xe (lộ trình EUP vài phút trước lúc gửi, không có thì vị trí EUP hiện tại)
 * HOẶC GPS điện thoại phải ở gần location; chỉ ngoài phạm vi khi cả hai đều xa.
 * Thiếu dữ liệu thì trả Unverified — không bao giờ chặn vì thiếu dữ liệu. Mốc đảo lái không kiểm tra.
 */
class CheckpointGeofenceService
{
    private const PICKUP_TYPES = [CheckpointType::ArrivedPickup, CheckpointType::LeftPickup];

    private const DELIVERY_TYPES = [CheckpointType::ArrivedDelivery, CheckpointType::Completed];

    private const UNCHECKED_TYPES = [CheckpointType::DriverSwap];

    /** Mốc bấm khi xe đang/vừa rời điểm: xét lộ trình dài hơn. */
    private const LEAVING_TYPES = [CheckpointType::LeftPickup, CheckpointType::Completed];

    public function __construct(
        protected TripCheckpointOsmValidationService $geo,
    ) {}

    /**
     * @param  array<string, mixed>  $payload  Dữ liệu tài xế gửi lên (gps_lat, gps_lng, order_id, delivery_point_id, new_delivery_location_id)
     */
    public function check(Trip $trip, CheckpointType $type, array $payload): GeofenceResult
    {
        if (in_array($type, self::UNCHECKED_TYPES, true)) {
            return new GeofenceResult(null, null, null, null, GeoCheckStatus::Skipped, null, GeoCheckStatus::Skipped, null, null);
        }

        $vehicle = $trip->vehicle;
        $eup = $vehicle !== null ? ($this->recentLoggedPosition($vehicle) ?? $this->freshEupPosition($vehicle)) : null;
        $phone = $this->position($payload['gps_lat'] ?? null, $payload['gps_lng'] ?? null);

        $vehicleRadiusM = $this->effectiveVehicleRadiusM($eup);
        [$vehicleStatus, $vehicleDistanceM] = $this->compare($eup !== null ? $phone : null, $eup, $vehicleRadiusM);

        $location = $this->targetLocation($type, $payload);
        $locationPosition = $location !== null ? $this->position($location->lat, $location->lng) : null;
        $locationRadiusM = $this->effectiveLocationRadiusM($location);
        $eupConfirmedAtLocation = false;

        if ($locationPosition === null) {
            [$locationStatus, $locationDistanceM] = [GeoCheckStatus::Skipped, null];
        } else {
            $vehicleMatch = $this->compareTrackWithLocation($vehicle, $type, $locationPosition, $locationRadiusM)
                ?? $this->compare($eup, $locationPosition, $locationRadiusM);
            $eupConfirmedAtLocation = ($vehicleMatch[0] === GeoCheckStatus::Valid);

            [$locationStatus, $locationDistanceM] = $eupConfirmedAtLocation
                ? $vehicleMatch
                : $this->eitherInRange($vehicleMatch, $this->compare($phone, $locationPosition, $locationRadiusM));
        }

        return new GeofenceResult(
            eupLat: $eup['lat'] ?? null,
            eupLng: $eup['lng'] ?? null,
            eupRecordedAt: $eup['recorded_at'] ?? null,
            eupSpeed: $eup['speed'] ?? null,
            vehicleStatus: $vehicleStatus,
            vehicleDistanceM: $vehicleDistanceM,
            locationStatus: $locationStatus,
            locationDistanceM: $locationDistanceM,
            locationCode: $location?->code,
            vehicleRadiusM: $vehicleRadiusM,
            locationRadiusM: $locationRadiusM,
            eupConfirmedAtLocation: $eupConfirmedAtLocation,
        );
    }

    /**
     * Kiểm tra và từ chối mốc nếu sai vị trí theo các lớp đang bật chặn trong config.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws CheckpointGeofenceException
     */
    public function checkOrFail(Trip $trip, CheckpointType $type, array $payload): GeofenceResult
    {
        $result = $this->check($trip, $type, $payload);

        $rejection = $result->rejection(
            (bool) config('checkpoint_geofence.enforce_vehicle'),
            (bool) config('checkpoint_geofence.enforce_location'),
        );

        if ($rejection !== null) {
            throw new CheckpointGeofenceException($rejection['message'], [
                'layer' => $rejection['layer'],
                'distance_m' => $rejection['distance_m'],
                'radius_m' => $rejection['radius_m'] ?? (int) config('checkpoint_geofence.radius_m'),
                'target_code' => $rejection['target_code'],
            ]);
        }

        return $result;
    }

    /**
     * Bán kính Lớp A có bù độ trễ theo tốc độ xe khi xe đang di chuyển (> 15 km/h).
     *
     * @param  array{lat: float, lng: float, recorded_at: CarbonInterface, speed: ?float}|null  $eup
     */
    private function effectiveVehicleRadiusM(?array $eup): int
    {
        $baseRadiusM = (int) config('checkpoint_geofence.vehicle_radius_m', 3000);
        if ($eup === null) {
            return $baseRadiusM;
        }

        $speedKmh = (float) ($eup['speed'] ?? 0);
        $thresholdKmh = (float) config('checkpoint_geofence.moving_speed_threshold_kmh', 15);
        if ($speedKmh <= $thresholdKmh) {
            return $baseRadiusM;
        }

        $ageSeconds = isset($eup['recorded_at'])
            ? max(60, (int) abs(now()->diffInSeconds($eup['recorded_at'])))
            : 60;
        $compensationM = (int) round(($speedKmh / 3.6) * $ageSeconds);
        $maxRadiusM = (int) config('checkpoint_geofence.max_vehicle_radius_m', 4000);

        return min($maxRadiusM, $baseRadiusM + $compensationM);
    }

    /**
     * Bán kính Lớp B ưu tiên cấu hình riêng trên từng Location, mặc định fallback về config.
     */
    private function effectiveLocationRadiusM(?Location $location): int
    {
        $custom = $location?->geofence_radius_m;

        return (is_numeric($custom) && (int) $custom > 0)
            ? (int) $custom
            : (int) config('checkpoint_geofence.radius_m', 3000);
    }

    /**
     * @param  array{lat: float, lng: float}|null  $from
     * @param  array{lat: float, lng: float}|null  $to
     * @return array{0: GeoCheckStatus, 1: ?int}
     */
    private function compare(?array $from, ?array $to, ?int $radiusM = null): array
    {
        if ($from === null || $to === null) {
            return [GeoCheckStatus::Unverified, null];
        }

        $limitM = $radiusM ?? (int) config('checkpoint_geofence.radius_m', 3000);
        $distanceM = (int) round($this->geo->calculateHaversineKm($from['lat'], $from['lng'], $to['lat'], $to['lng']) * 1000);

        return [
            $distanceM <= $limitM ? GeoCheckStatus::Valid : GeoCheckStatus::OutOfRange,
            $distanceM,
        ];
    }

    /**
     * Lớp B đạt khi xe HOẶC điện thoại trong bán kính (hai nguồn dùng bản đồ khác nhau nên sai số khác nhau).
     * Chỉ ngoài phạm vi khi không nguồn nào trong bán kính mà có ít nhất một nguồn đo được; không nguồn nào có dữ liệu thì Unverified.
     *
     * @param  array{0: GeoCheckStatus, 1: ?int}  $vehicle
     * @param  array{0: GeoCheckStatus, 1: ?int}  $phone
     * @return array{0: GeoCheckStatus, 1: ?int}
     */
    private function eitherInRange(array $vehicle, array $phone): array
    {
        if ($phone[0] === GeoCheckStatus::Valid) {
            return $phone;
        }

        $distances = array_filter([$vehicle[1], $phone[1]], fn (?int $distanceM): bool => $distanceM !== null);

        return $distances === []
            ? [GeoCheckStatus::Unverified, null]
            : [GeoCheckStatus::OutOfRange, min($distances)];
    }

    /**
     * Điểm lộ trình EUP mới nhất trong khoảng track_match_seconds trước lúc gửi.
     *
     * @return array{lat: float, lng: float, recorded_at: CarbonInterface, speed: ?float}|null
     */
    private function recentLoggedPosition(Vehicle $vehicle): ?array
    {
        $log = $vehicle->gpsLogs()
            ->where('recorded_at', '>=', now()->subSeconds((int) config('checkpoint_geofence.track_match_seconds')))
            ->where('recorded_at', '<=', now())
            ->latest('recorded_at')
            ->first();

        return $log === null ? null : [
            'lat' => (float) $log->lat,
            'lng' => (float) $log->lng,
            'recorded_at' => $log->recorded_at,
            'speed' => $log->speed !== null ? (float) $log->speed : null,
        ];
    }

    /**
     * Lớp B theo lộ trình: khoảng cách gần nhất của xe tới location trong khoảng thời gian trước lúc gửi.
     * Null nếu không có điểm lộ trình nào trong khoảng (khi đó dùng vị trí hiện tại).
     *
     * @param  array{lat: float, lng: float}  $location
     * @return array{0: GeoCheckStatus, 1: int}|null
     */
    private function compareTrackWithLocation(?Vehicle $vehicle, CheckpointType $type, array $location, ?int $radiusM = null): ?array
    {
        if ($vehicle === null) {
            return null;
        }

        $windowSeconds = in_array($type, self::LEAVING_TYPES, true)
            ? (int) config('checkpoint_geofence.track_window_leave_seconds')
            : (int) config('checkpoint_geofence.track_window_arrive_seconds');

        $points = $vehicle->trackBetween(now()->subSeconds($windowSeconds), now());

        if ($points->isEmpty()) {
            return null;
        }

        $limitM = $radiusM ?? (int) config('checkpoint_geofence.radius_m', 3000);
        $closestM = $points
            ->map(fn ($log): int => (int) round($this->geo->calculateHaversineKm((float) $log->lat, (float) $log->lng, $location['lat'], $location['lng']) * 1000))
            ->min();

        return [
            $closestM <= $limitM ? GeoCheckStatus::Valid : GeoCheckStatus::OutOfRange,
            $closestM,
        ];
    }

    /**
     * Vị trí EUP hiện tại trên bảng vehicles còn dùng được: có toạ độ, không mất tín hiệu, và còn mới (hoặc xe đang dừng).
     *
     * @return array{lat: float, lng: float, recorded_at: CarbonInterface, speed: ?float}|null
     */
    private function freshEupPosition(Vehicle $vehicle): ?array
    {
        $position = $this->position($vehicle->gps_lat, $vehicle->gps_lng);

        if ($position === null || $vehicle->last_gps_update === null || $vehicle->car_status === EupCarStatus::SignalLost->value) {
            return null;
        }

        $isFresh = $vehicle->last_gps_update->gte(now()->subSeconds((int) config('checkpoint_geofence.eup_fresh_seconds')));

        if (! $isFresh && ! EupCarStatus::isStationary($vehicle->car_status)) {
            return null;
        }

        return [
            ...$position,
            'recorded_at' => $vehicle->last_gps_update,
            'speed' => $vehicle->gps_speed !== null ? (float) $vehicle->gps_speed : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function targetLocation(CheckpointType $type, array $payload): ?Location
    {
        if (in_array($type, self::PICKUP_TYPES, true)) {
            return Order::find($payload['order_id'] ?? null)?->pickupLocation;
        }

        if (in_array($type, self::DELIVERY_TYPES, true)) {
            if (! empty($payload['delivery_point_id'])) {
                return OrderDeliveryPoint::find($payload['delivery_point_id'])?->location;
            }

            if (! empty($payload['new_delivery_location_id'])) {
                return Location::find($payload['new_delivery_location_id']);
            }
        }

        return null;
    }

    /**
     * @return array{lat: float, lng: float}|null
     */
    private function position(mixed $lat, mixed $lng): ?array
    {
        if (! is_numeric($lat) || ! is_numeric($lng) || abs((float) $lat) < 0.001 || abs((float) $lng) < 0.001) {
            return null;
        }

        return ['lat' => (float) $lat, 'lng' => (float) $lng];
    }
}
