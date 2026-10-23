<?php

namespace App\Services\Trip;

use App\Enums\TripStatus;
use App\Models\Order;
use App\Models\Trip;
use App\Services\OsrmService;
use Carbon\CarbonInterface;

/**
 * Dựng dữ liệu cho trang theo dõi chuyến công khai (khách hàng).
 *
 * Không phụ thuộc Filament/Livewire để có thể test và tái sử dụng.
 * Chỉ trả dữ liệu khách được phép thấy: không SĐT/CCCD tài xế, không giá cước.
 */
class TripTrackingMapService
{
    /** GPS cũ hơn ngưỡng này được coi là mất tín hiệu. */
    public const STALE_MINUTES = 15;

    private const FALLBACK_CENTER = [21.0285, 105.8542]; // Hà Nội

    /**
     * Toạ độ, marker và tuyến đường dự kiến của chuyến.
     *
     * @return array{
     *     points: array<int, array{0: float, 1: float}>,
     *     markers: array<int, array{id: string, type: string, lat: float, lng: float, label: string, title: string, description: string}>,
     *     route: array<int, array{0: float, 1: float}>,
     *     route_source: string,
     *     traveled: array<int, array{0: float, 1: float}>,
     *     traveled_route: array<int, array{0: float, 1: float}>,
     *     traveled_route_source: string,
     *     center: array{0: float, 1: float},
     *     has_points: bool,
     *     pickups: array<int, array{order_code: string|null, name: string, address: string|null, planned_at: string|null}>,
     *     deliveries: array<int, array{order_code: string|null, name: string, address: string|null, sequence: int|null, status: string|null}>
     * }
     */
    public function buildMapData(Trip $trip): array
    {
        $trip->loadMissing([
            'vehicle',
            'startLocation',
            'endLocation',
            'orders.pickupLocation',
            'orders.deliveryPoints.location',
            'checkpoints',
        ]);

        $points = [];
        $markers = [];
        $pickups = [];
        $deliveries = [];

        // Gộp các điểm trùng nhau liền kề (cùng một kho lấy/giao nhiều đơn).
        $addPoint = function (float $lat, float $lng) use (&$points): void {
            $last = end($points);

            if ($last === false || $last[0] !== $lat || $last[1] !== $lng) {
                $points[] = [$lat, $lng];
            }
        };

        $startLocation = $trip->startLocation;
        if ($startLocation?->lat !== null && $startLocation?->lng !== null) {
            $lat = (float) $startLocation->lat;
            $lng = (float) $startLocation->lng;
            $addPoint($lat, $lng);

            $markers[] = [
                'id' => 'start-'.$trip->getKey(),
                'type' => 'start',
                'lat' => $lat,
                'lng' => $lng,
                'label' => 'A',
                'title' => 'Điểm xuất phát',
                'description' => $this->locationDescription($startLocation->name, $startLocation->address),
            ];
        }

        $orders = $trip->orders
            ->sortBy(fn (Order $order): int => $order->planned_loading_at?->getTimestamp() ?? PHP_INT_MAX)
            ->values();

        $pickupIndex = 0;

        foreach ($orders as $order) {
            $location = $order->pickupLocation;

            $pickups[] = [
                'order_code' => $order->order_code,
                'name' => $location?->name ?? $order->pickup_address ?? 'Điểm lấy hàng',
                'address' => $order->pickup_address ?? $location?->address,
                'planned_at' => $order->planned_loading_at?->toIso8601String(),
            ];

            if ($location?->lat === null || $location?->lng === null) {
                continue;
            }

            $lat = (float) $location->lat;
            $lng = (float) $location->lng;
            $addPoint($lat, $lng);
            $pickupIndex++;

            $markers[] = [
                'id' => 'pickup-'.$order->getKey(),
                'type' => 'pickup',
                'lat' => $lat,
                'lng' => $lng,
                'label' => (string) $pickupIndex,
                'title' => 'Điểm lấy hàng'.($order->order_code ? ' — '.$order->order_code : ''),
                'description' => $this->locationDescription($location->name ?? $order->pickup_address, $order->pickup_address ?? $location->address),
            ];
        }

        $deliveryIndex = 0;

        foreach ($orders as $order) {
            foreach ($order->deliveryPoints->sortBy('sequence') as $deliveryPoint) {
                $location = $deliveryPoint->location;

                $deliveries[] = [
                    'order_code' => $order->order_code,
                    'name' => $location?->name ?? $deliveryPoint->address ?? 'Điểm giao hàng',
                    'address' => $deliveryPoint->address ?? $location?->address,
                    'sequence' => $deliveryPoint->sequence,
                    'status' => $deliveryPoint->status?->value,
                ];

                if ($location?->lat === null || $location?->lng === null) {
                    continue;
                }

                $lat = (float) $location->lat;
                $lng = (float) $location->lng;
                $addPoint($lat, $lng);
                $deliveryIndex++;

                $markers[] = [
                    'id' => 'delivery-'.$deliveryPoint->getKey(),
                    'type' => 'delivery',
                    'lat' => $lat,
                    'lng' => $lng,
                    'label' => (string) $deliveryIndex,
                    'title' => 'Điểm giao hàng'.($order->order_code ? ' — '.$order->order_code : ''),
                    'description' => $this->locationDescription($deliveryPoint->address, $location?->address),
                ];
            }
        }

        $endLocation = $trip->endLocation;
        if ($endLocation?->lat !== null && $endLocation?->lng !== null) {
            $lat = (float) $endLocation->lat;
            $lng = (float) $endLocation->lng;
            $addPoint($lat, $lng);

            $markers[] = [
                'id' => 'end-'.$trip->getKey(),
                'type' => 'end',
                'lat' => $lat,
                'lng' => $lng,
                'label' => 'B',
                'title' => 'Điểm kết thúc',
                'description' => $this->locationDescription($endLocation->name, $endLocation->address),
            ];
        }

        $route = [];
        if (count($points) >= 2) {
            $route = app(OsrmService::class)->getRouteFromPoints($points);
        }

        // Lộ trình EUP thật của xe trong chuyến; không có thì nối các mốc GPS của tài xế qua OSRM.
        $gpsTrack = $trip->gpsTrack();

        $traveled = count($gpsTrack) >= 2 ? $gpsTrack : $trip->checkpoints
            ->filter(fn ($checkpoint): bool => $checkpoint->gps_lat !== null && $checkpoint->gps_lng !== null)
            ->sortBy(fn ($checkpoint): int => $checkpoint->occurred_at?->getTimestamp() ?? 0)
            ->map(fn ($checkpoint): array => [(float) $checkpoint->gps_lat, (float) $checkpoint->gps_lng])
            ->values()
            ->all();

        $traveledRoute = [];
        if (count($gpsTrack) >= 2) {
            $traveledRoute = $gpsTrack;
        } elseif (count($traveled) >= 2) {
            $traveledRoute = app(OsrmService::class)->getRouteFromPoints($traveled);
        }

        $vehicle = $trip->vehicle;
        $hasVehicleGps = $vehicle?->gps_lat !== null && $vehicle?->gps_lng !== null;

        $center = $hasVehicleGps
            ? [(float) $vehicle->gps_lat, (float) $vehicle->gps_lng]
            : ($points[0] ?? $traveled[0] ?? self::FALLBACK_CENTER);

        return [
            'points' => $points,
            'markers' => $markers,
            'route' => $route,
            'route_source' => count($route) >= 2 ? 'osrm' : (count($points) >= 2 ? 'direct' : 'none'),
            'traveled' => $traveled,
            'traveled_route' => $traveledRoute,
            'traveled_route_source' => match (true) {
                count($gpsTrack) >= 2 => 'gps',
                count($traveledRoute) >= 2 => 'osrm',
                count($traveled) >= 2 => 'direct',
                default => 'none',
            },
            'center' => $center,
            'has_points' => $points !== [] || $traveled !== [],
            'pickups' => $pickups,
            'deliveries' => $deliveries,
        ];
    }

    /**
     * Payload JSON cho endpoint cập nhật vị trí xe.
     *
     * @return array{
     *     status: string|null,
     *     status_label: string,
     *     status_color: string,
     *     is_finished: bool,
     *     has_position: bool,
     *     position: array{latitude: float, longitude: float, speed_kmh: float|null, address: string|null, updated_at: string|null, updated_at_human: string|null, is_stale: bool}|null,
     *     polled_at: string
     * }
     */
    public function positionPayload(Trip $trip): array
    {
        $trip->loadMissing('vehicle');

        $vehicle = $trip->vehicle;
        $hasPosition = $vehicle?->gps_lat !== null && $vehicle?->gps_lng !== null;
        $lastUpdate = $vehicle?->last_gps_update;

        return [
            'status' => $trip->status?->value,
            'status_label' => $trip->getStatusLabel(),
            'status_color' => $trip->getStatusColor(),
            'is_finished' => $this->isFinished($trip),
            'has_position' => $hasPosition,
            'position' => $hasPosition ? [
                'latitude' => (float) $vehicle->gps_lat,
                'longitude' => (float) $vehicle->gps_lng,
                'speed_kmh' => $vehicle->gps_speed !== null ? (float) $vehicle->gps_speed : null,
                'address' => $vehicle->gps_address,
                'updated_at' => $lastUpdate?->toIso8601String(),
                'updated_at_human' => $lastUpdate?->diffForHumans(),
                'is_stale' => $this->isStale($lastUpdate),
            ] : null,
            'polled_at' => now()->toIso8601String(),
        ];
    }

    public function isFinished(Trip $trip): bool
    {
        return in_array($trip->status, [TripStatus::Completed, TripStatus::Cancelled], true);
    }

    private function isStale(?CarbonInterface $lastUpdate): bool
    {
        return $lastUpdate === null || $lastUpdate->lt(now()->subMinutes(self::STALE_MINUTES));
    }

    private function locationDescription(?string $name, ?string $address): string
    {
        $name = trim((string) $name);
        $address = trim((string) $address);

        if ($name !== '' && $address !== '' && $name !== $address) {
            return $name.' — '.$address;
        }

        return $name !== '' ? $name : $address;
    }
}
