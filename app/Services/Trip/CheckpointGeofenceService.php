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
 * Lớp B: với mốc lấy/giao hàng, xe phải vào gần location trong vài phút trước lúc gửi (theo lộ trình EUP;
 * không có lộ trình thì dùng vị trí EUP hiện tại, thiếu EUP thì dùng GPS điện thoại).
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

        [$vehicleStatus, $vehicleDistanceM] = $this->compare($eup !== null ? $phone : null, $eup);

        $location = $this->targetLocation($type, $payload);
        $locationPosition = $location !== null ? $this->position($location->lat, $location->lng) : null;

        [$locationStatus, $locationDistanceM] = $locationPosition === null
            ? [GeoCheckStatus::Skipped, null]
            : ($this->compareTrackWithLocation($vehicle, $type, $locationPosition) ?? $this->compare($eup ?? $phone, $locationPosition));

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
                'radius_m' => (int) config('checkpoint_geofence.radius_m'),
                'target_code' => $rejection['target_code'],
            ]);
        }

        return $result;
    }

    /**
     * @param  array{lat: float, lng: float}|null  $from
     * @param  array{lat: float, lng: float}|null  $to
     * @return array{0: GeoCheckStatus, 1: ?int}
     */
    private function compare(?array $from, ?array $to): array
    {
        if ($from === null || $to === null) {
            return [GeoCheckStatus::Unverified, null];
        }

        $distanceM = (int) round($this->geo->calculateHaversineKm($from['lat'], $from['lng'], $to['lat'], $to['lng']) * 1000);

        return [
            $distanceM <= (int) config('checkpoint_geofence.radius_m') ? GeoCheckStatus::Valid : GeoCheckStatus::OutOfRange,
            $distanceM,
        ];
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
    private function compareTrackWithLocation(?Vehicle $vehicle, CheckpointType $type, array $location): ?array
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

        $closestM = $points
            ->map(fn ($log): int => (int) round($this->geo->calculateHaversineKm((float) $log->lat, (float) $log->lng, $location['lat'], $location['lng']) * 1000))
            ->min();

        return [
            $closestM <= (int) config('checkpoint_geofence.radius_m') ? GeoCheckStatus::Valid : GeoCheckStatus::OutOfRange,
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
