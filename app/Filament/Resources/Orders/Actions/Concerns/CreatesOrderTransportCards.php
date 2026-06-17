<?php

namespace App\Filament\Resources\Orders\Actions\Concerns;

use App\Enums\OrderStatus;
use App\Models\Location;
use App\Models\Order;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

abstract class CreatesOrderTransportCards
{
    /**
     * @return array<int, array<string, mixed>>
     */
    protected static function resolveDriverCards(): array
    {
        return User::query()
            ->role('driver')
            ->withCount([
                'orders',
                'orders as active_orders_count' => fn ($q) => $q->whereIn('status', [
                    OrderStatus::Assigned->value,
                    OrderStatus::Sent->value,
                    OrderStatus::Started->value,
                    OrderStatus::ArrivedPickup->value,
                    OrderStatus::Delivering->value,
                    OrderStatus::ArrivedDelivery->value,
                ], 'and', false),
            ])
            ->with([
                'driverShifts' => fn ($query) => $query->latest('start_time')->limit(1),
                'vehiclesAsDriver' => fn ($query) => $query->select('id', 'plate_number', 'gps_lat', 'gps_lng'),
            ])
            ->orderBy('name')
            ->get()
            ->map(function (User $driver): array {
                $latestShift = $driver->driverShifts->first();
                $hasActiveShift = $latestShift && $latestShift->end_time === null;
                $assignedVehicle = $driver->vehiclesAsDriver->first();
                $activeOrders = (int) $driver->active_orders_count;
                $isAvailable = $activeOrders === 0;

                $driverLocation = null;
                if ($assignedVehicle?->gps_lat && $assignedVehicle?->gps_lng) {
                    $driverLocation = self::findNearestLocation(
                        (float) $assignedVehicle->gps_lat,
                        (float) $assignedVehicle->gps_lng,
                    );
                }

                return [
                    'value' => $driver->id,
                    'leading' => '👤',
                    'title' => $driver->name,
                    'subtitle' => $driver->phone ?: ($driver->email ?? ''),
                    'badge' => $isAvailable ? 'Sẵn sàng' : 'Đang chạy ('.$activeOrders.')',
                    'badgeClasses' => $isAvailable
                        ? 'border border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900/40 dark:bg-emerald-950/30 dark:text-emerald-200'
                        : 'border border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-900/40 dark:bg-amber-950/30 dark:text-amber-200',
                    'statusDot' => $isAvailable ? 'success' : 'warning',
                    'details' => array_values(array_filter([
                        ['icon' => 'heroicon-m-identification', 'label' => 'GPLX', 'value' => $driver->license_class ? ($driver->license_class.($driver->license_number ? ' · '.$driver->license_number : '')) : 'Chưa cập nhật'],
                        ['icon' => 'heroicon-m-truck', 'label' => 'Xe gán', 'value' => $assignedVehicle?->plate_number ?? 'Chưa gán xe'],
                        ['icon' => 'heroicon-m-clock', 'label' => 'Ca trực', 'value' => $hasActiveShift ? ('Đang '.$latestShift->shift_type?->getLabel()) : ($latestShift?->shift_type?->getLabel() ?? 'Chưa có ca')],
                        ['icon' => 'heroicon-m-map-pin', 'label' => 'Vị trí', 'value' => $driverLocation['name'] ?? 'Chưa xác định'],
                        ['icon' => 'heroicon-m-document-text', 'label' => 'Tổng chuyến', 'value' => number_format((int) $driver->orders_count, 0, ',', '.')],
                    ])),
                    'meta' => [
                        $driver->license_class ?? '',
                        $assignedVehicle?->plate_number ?? '',
                    ],
                    'isSuggested' => $isAvailable && $hasActiveShift,
                    'suggestionScore' => $isAvailable ? ($hasActiveShift ? 1000 : 500) : 0,
                    'suggestedBadge' => 'Gợi ý',
                    'suggestedBadgeClasses' => 'border border-primary-200 bg-primary-50 text-primary-700 dark:border-primary-800/40 dark:bg-primary-900/30 dark:text-primary-200',
                ];
            })
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected static function resolveVehicleCards(?float $requiredWeight, ?int $pickupLocationId, ?int $selectedVehicleId = null): array
    {
        return Vehicle::query()
            ->where(function ($query) use ($selectedVehicleId): void {
                $query->where('status', 'on');

                if ($selectedVehicleId) {
                    $query->orWhere('id', $selectedVehicleId);
                }
            })
            ->with(['driver' => fn ($q) => $q
                ->with(['driverShifts' => fn ($q) => $q
                    ->whereNull('end_time')
                    ->withCount(['shiftVehicles as orders_in_shift' => fn ($q) => $q->whereNotNull('order_id')]),
                ]),
            ])
            ->withCount([
                'orders as active_orders_count' => fn ($q) => $q->whereIn('status', [
                    OrderStatus::Assigned->value,
                    OrderStatus::Sent->value,
                    OrderStatus::Started->value,
                    OrderStatus::ArrivedPickup->value,
                    OrderStatus::Delivering->value,
                    OrderStatus::ArrivedDelivery->value,
                ], 'and', false),
            ])
            ->orderBy('plate_number')
            ->get()
            ->map(function (Vehicle $vehicle) use ($requiredWeight, $pickupLocationId): array {
                $loadCapacity = number_format((float) $vehicle->load_capacity, 1, ',', '.');
                $make = $vehicle->make ?: 'Chưa rõ hãng';

                $requiredWeight = $requiredWeight ?? 0;
                $isCapacityMatch = $requiredWeight <= 0 || (float) $vehicle->load_capacity >= $requiredWeight;

                $currentLocation = self::resolveCurrentVehicleLocation($vehicle);
                $isLocationMatch = ! $pickupLocationId || (($currentLocation['id'] ?? null) === $pickupLocationId);
                $isSuggested = $isCapacityMatch && $isLocationMatch;
                $capacityDelta = max(0, (float) $vehicle->load_capacity - $requiredWeight);
                $suggestionScore = $isSuggested
                    ? (1000 - min(999, (int) round($capacityDelta * 10)))
                    : 0;

                $statusLabel = $vehicle->getStatusLabel();
                $statusColor = $vehicle->getStatusColor();
                $statusClasses = self::getStatusBadgeClasses($statusColor);
                $activeOrders = (int) $vehicle->active_orders_count;
                $mileage = $vehicle->current_mileage ? number_format((float) $vehicle->current_mileage, 0, ',', '.').' km' : 'N/A';
                $ordersInShift = (int) ($vehicle->driver?->driverShifts->first()?->orders_in_shift ?? 0);

                $fuelLabels = [
                    'Diesel' => 'Diesel',
                    'Gasoline' => 'Xăng',
                    'Electric' => 'Điện',
                    'Hybrid' => 'Hybrid',
                ];
                $fuelLabel = $fuelLabels[$vehicle->fuel_type] ?? 'Chưa rõ';

                return [
                    'value' => $vehicle->id,
                    'leading' => '🚚',
                    'title' => $vehicle->plate_number,
                    'subtitle' => $make.' · '.$vehicle->getVehicleTypeLabel(),
                    'badge' => $statusLabel,
                    'badgeClasses' => $statusClasses,
                    'statusDot' => $statusColor,
                    'details' => array_values(array_filter([
                        ['icon' => 'heroicon-m-scale', 'label' => 'Tải trọng', 'value' => $loadCapacity.' tấn'.($requiredWeight > 0 ? ($isCapacityMatch ? ' ✓' : ' ✗') : '')],
                        ['icon' => 'heroicon-m-user', 'label' => 'Lái xe', 'value' => $vehicle->driver?->name ?? 'Chưa phân lái'],
                        ['icon' => 'heroicon-m-map-pin', 'label' => 'Vị trí', 'value' => $currentLocation['name'] ?? 'Chưa xác định'],
                        ['icon' => 'heroicon-m-cog-6-tooth', 'label' => 'ODO', 'value' => $mileage],
                        $activeOrders > 0 ? ['icon' => 'heroicon-m-document-text', 'label' => 'Đơn đang chạy', 'value' => (string) $activeOrders] : null,
                        ['icon' => 'heroicon-m-document-text', 'label' => 'Đơn trong ca', 'value' => (string) $ordersInShift],
                    ])),
                    'meta' => [
                        $vehicle->driver?->name ?? '',
                        $vehicle->getVehicleTypeLabel(),
                        $loadCapacity.' tấn',
                        $currentLocation['name'] ?? '',
                    ],
                    'suggestedBadge' => 'Phù hợp nhất',
                    'suggestedBadgeClasses' => 'border border-primary-200 bg-primary-50 text-primary-700 dark:border-primary-800/40 dark:bg-primary-900/30 dark:text-primary-200',
                    'isSuggested' => $isSuggested,
                    'suggestionScore' => $suggestionScore,
                    'capacityMatch' => $isCapacityMatch,
                ];
            })
            ->all();
    }

    /**
     * @return array{id: ?int, name: ?string}
     */
    protected static function resolveCurrentVehicleLocation(Vehicle $vehicle): array
    {
        /** @var Order|null $activeOrder */
        $activeOrder = Order::query()
            ->where('vehicle_id', $vehicle->id)
            ->whereIn('status', [
                OrderStatus::Assigned->value,
                OrderStatus::Sent->value,
                OrderStatus::Started->value,
                OrderStatus::ArrivedPickup->value,
                OrderStatus::Delivering->value,
                OrderStatus::ArrivedDelivery->value,
                OrderStatus::Delivered->value,
                OrderStatus::DriverSwap->value,
            ], 'and', false)
            ->with('pickupLocation')
            ->latest('created_at')
            ->first();

        if ($activeOrder) {
            $latestCheckpoint = $activeOrder->tripCheckpoints()
                ->with('deliveryPoint.location')
                ->latest('occurred_at')
                ->first();

            $deliveryLocation = $latestCheckpoint?->deliveryPoint?->location;

            if ($deliveryLocation) {
                return ['id' => (int) $deliveryLocation->id, 'name' => $deliveryLocation->name];
            }

            $pickupLocation = $activeOrder->pickupLocation;

            return [
                'id' => $pickupLocation?->id ? (int) $pickupLocation->id : null,
                'name' => $pickupLocation?->name,
            ];
        }

        return self::resolveVehicleGpsLocation($vehicle);
    }

    /**
     * @return array{id: ?int, name: ?string}
     */
    protected static function resolveVehicleGpsLocation(Vehicle $vehicle): array
    {
        if (! $vehicle->gps_lat || ! $vehicle->gps_lng) {
            return ['id' => null, 'name' => null];
        }

        return self::findNearestLocation(
            (float) $vehicle->gps_lat,
            (float) $vehicle->gps_lng,
        );
    }

    /**
     * @return array{id: ?int, name: ?string}
     */
    public static function findNearestLocation(float $lat, float $lng): array
    {
        /** @var Collection<int, Location> $locations */
        $locations = Location::query()
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->where('is_active', true)
            ->get(['id', 'name', 'code', 'lat', 'lng']);

        $nearest = null;
        $minDistance = PHP_FLOAT_MAX;

        foreach ($locations as $loc) {
            $dist = self::haversineDistance($lat, $lng, (float) $loc->lat, (float) $loc->lng);
            if ($dist < $minDistance) {
                $minDistance = $dist;
                $nearest = $loc;
            }
        }

        if ($nearest && $minDistance <= 50) {
            return [
                'id' => (int) $nearest->id,
                'name' => $nearest->name.' ('.$nearest->code.')',
            ];
        }

        return ['id' => null, 'name' => null];
    }

    protected static function haversineDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) * sin($dLat / 2)
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
            * sin($dLng / 2) * sin($dLng / 2);

        return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    protected static function normalizeDecimal(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            $value = str_replace(',', '.', $value);
        }

        return is_numeric($value) ? (float) $value : null;
    }

    protected static function normalizeInteger(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    protected static function generateOrderCode(): string
    {
        $prefix = 'ASG-';

        $latestOrderCode = Order::query()
            ->withTrashed()
            ->where('order_code', 'like', $prefix.'%')
            ->orderByDesc('order_code')
            ->value('order_code');

        $nextSequence = 1;

        if (is_string($latestOrderCode) && str_starts_with($latestOrderCode, $prefix)) {
            $suffix = substr($latestOrderCode, strlen($prefix));

            if (ctype_digit($suffix)) {
                $nextSequence = ((int) $suffix) + 1;
            }
        }

        return sprintf('%s%02d', $prefix, $nextSequence);
    }

    protected static function isOrderCodeDuplicate(Throwable $throwable): bool
    {
        return str_contains($throwable->getMessage(), 'UNIQUE constraint failed: orders.order_code');
    }

    protected static function getStatusBadgeClasses(string $color): string
    {
        return match ($color) {
            'danger' => 'border border-red-200 bg-red-50 text-red-700 dark:border-red-900/40 dark:bg-red-950/30 dark:text-red-200',
            'warning' => 'border border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-900/40 dark:bg-amber-950/30 dark:text-amber-200',
            'success' => 'border border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900/40 dark:bg-emerald-950/30 dark:text-emerald-200',
            'info' => 'border border-sky-200 bg-sky-50 text-sky-700 dark:border-sky-900/40 dark:bg-sky-950/30 dark:text-sky-200',
            'primary' => 'border border-primary-200 bg-primary-50 text-primary-700 dark:border-primary-800/40 dark:bg-primary-900/30 dark:text-primary-200',
            default => 'border border-gray-200 bg-gray-50 text-gray-700 dark:border-gray-700 dark:bg-gray-900/30 dark:text-gray-200',
        };
    }
}
