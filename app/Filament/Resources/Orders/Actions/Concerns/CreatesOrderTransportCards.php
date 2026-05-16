<?php

namespace App\Filament\Resources\Orders\Actions\Concerns;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use App\Models\Vehicle;

abstract class CreatesOrderTransportCards
{
    /**
     * @return array<int, array<string, mixed>>
     */
    protected static function resolveDriverCards(): array
    {
        return User::query()
            ->where(function ($query): void {
                $query->whereHas('driverShifts')
                    ->orWhereHas('vehiclesAsDriver');
            })
            ->withCount('orders')
            ->with(['driverShifts' => fn ($query) => $query->latest('start_time')])
            ->orderBy('name')
            ->get()
            ->map(function (User $driver): array {
                $latestShift = $driver->driverShifts->first();

                return [
                    'value' => $driver->id,
                    'leading' => '👤',
                    'title' => $driver->name,
                    'subtitle' => $driver->email.' · '.$driver->phone,
                    'meta' => [
                        number_format((int) $driver->orders_count, 0, ',', '.').' chuyến',
                        $latestShift?->shift_type?->getLabel() ?? 'Chưa có ca',
                    ],
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
                $query->where('status', 'on')
                    ->when($selectedVehicleId, fn ($query): mixed => $query->orWhere('id', $selectedVehicleId));
            })
            ->with('driver')
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
                $statusClasses = self::getStatusBadgeClasses($vehicle->getStatusColor());

                return [
                    'value' => $vehicle->id,
                    'leading' => '🚚',
                    'title' => $vehicle->plate_number,
                    'subtitle' => $make.' · '.$vehicle->getVehicleTypeLabel().' · '.$loadCapacity.' tấn',
                    'meta' => [
                        $vehicle->driver?->name ?? 'Chưa phân xe',
                        'Vị trí hiện tại: '.($currentLocation['name'] ?? 'Chưa xác định'),
                        $isCapacityMatch ? 'Đủ tải cho đơn' : 'Không đủ tải cho đơn',
                    ],
                    'badge' => $statusLabel,
                    'badgeClasses' => $statusClasses,
                    'suggestedBadge' => 'Gợi ý',
                    'suggestedBadgeClasses' => 'border border-primary-200 bg-primary-50 text-primary-700 dark:border-primary-800/40 dark:bg-primary-900/30 dark:text-primary-200',
                    'isSuggested' => $isSuggested,
                    'suggestionScore' => $suggestionScore,
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
            ])
            ->with('pickupLocation')
            ->latest('created_at')
            ->first();

        if (! $activeOrder) {
            return ['id' => null, 'name' => null];
        }

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
