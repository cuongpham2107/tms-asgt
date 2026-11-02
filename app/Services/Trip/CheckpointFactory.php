<?php

namespace App\Services\Trip;

use App\Enums\CheckpointType;
use App\Models\DriverShift;
use App\Models\OrderDeliveryPoint;
use App\Models\Trip;
use App\Models\TripCheckpoint;
use Illuminate\Support\Collection;

class CheckpointFactory
{
    /** Các checkpoint_type áp dụng cho cả trip, gắn vào đơn duy nhất của trip (nhánh A). */
    private const TRIP_WIDE_TYPES = [
        CheckpointType::Started,
        CheckpointType::ArrivedPickup,
        CheckpointType::LeftPickup,
        CheckpointType::DriverSwap,
    ];

    /**
     * @param  array<string, mixed>  $payload
     * @return Collection<int, TripCheckpoint>
     */
    public function create(Trip $trip, array $payload, CheckpointType $type): Collection
    {
        return in_array($type, self::TRIP_WIDE_TYPES, true)
            ? $this->createForAllOrders($trip, $payload, $type)
            : $this->createForDeliveryGroup($trip, $payload, $type);
    }

    /**
     * Nhánh A: tạo checkpoint cho đơn duy nhất của trip (trip không có đơn → không tạo).
     *
     * @return Collection<int, TripCheckpoint>
     */
    private function createForAllOrders(Trip $trip, array $payload, CheckpointType $type): Collection
    {
        $order = $trip->orders->first();

        if ($order === null) {
            return collect();
        }

        if ($type !== CheckpointType::DriverSwap) {
            $existing = TripCheckpoint::where('trip_id', $trip->id)
                ->where('order_id', $order->id)
                ->where('checkpoint_type', $type->value)
                ->first();

            if ($existing && $existing->driver_id !== $trip->driver_id && $existing->km_reading === null) {
                $existing->delete();
            } elseif ($existing !== null) {
                return collect();
            }
        }

        return collect([TripCheckpoint::create(
            $this->buildData($trip, $payload, $type, $order->id, $payload['delivery_point_id'] ?? null)
        )]);
    }

    /**
     * Nhánh B: tạo checkpoint cho điểm giao được chọn (hoặc đơn lẻ nếu không qua điểm cố định).
     *
     * @return Collection<int, TripCheckpoint>
     */
    private function createForDeliveryGroup(Trip $trip, array $payload, CheckpointType $type): Collection
    {
        $deliveryPointId = $payload['delivery_point_id'] ?? null;
        $point = $deliveryPointId !== null ? OrderDeliveryPoint::find($deliveryPointId) : null;
        $orderId = $point?->order_id ?? $payload['order_id'] ?? null;

        if ($orderId === null) {
            return collect();
        }

        $existing = TripCheckpoint::where('trip_id', $trip->id)
            ->where('order_id', $orderId)
            ->where('checkpoint_type', $type->value)
            ->where('delivery_point_id', $point?->id)
            ->first();

        if ($existing && $existing->driver_id !== $trip->driver_id && $existing->km_reading === null) {
            $existing->delete();
        } elseif ($existing !== null) {
            return collect();
        }

        return collect([TripCheckpoint::create(
            $this->buildData($trip, $payload, $type, $orderId, $point?->id)
        )]);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildData(
        Trip $trip,
        array $payload,
        CheckpointType $type,
        int $orderId,
        ?int $deliveryPointId,
    ): array {
        // Auto-fill km_reading from vehicle for started checkpoint
        $kmReading = $payload['km_reading'] ?? null;
        if ($kmReading === null && $type === CheckpointType::Started) {
            $kmReading = $trip->vehicle?->current_mileage;
        }

        return [
            'trip_id' => $trip->id,
            'order_id' => $orderId,
            'delivery_point_id' => $deliveryPointId,
            'driver_id' => $trip->driver_id,
            'shift_id' => $trip->shift_id
                ?? DriverShift::where('driver_id', $trip->driver_id)->whereNull('end_time')->value('id'),
            'vehicle_id' => $trip->vehicle_id,
            'checkpoint_type' => $type->value,
            'occurred_at' => $payload['occurred_at'] ?? now(),
            'km_reading' => $kmReading,
            'gps_lat' => $payload['gps_lat'] ?? null,
            'gps_lng' => $payload['gps_lng'] ?? null,
            'gps_accuracy' => isset($payload['gps_accuracy']) ? (int) round((float) $payload['gps_accuracy']) : null,
            'voice_note' => $payload['voice_note'] ?? null,
            ...($payload['geofence'] ?? []),
        ];
    }
}
