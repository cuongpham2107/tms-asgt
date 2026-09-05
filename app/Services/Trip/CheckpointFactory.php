<?php

namespace App\Services\Trip;

use App\Enums\CheckpointType;
use App\Enums\OrderDeliveryPointStatus;
use App\Models\DriverShift;
use App\Models\OrderDeliveryPoint;
use App\Models\Trip;
use App\Models\TripCheckpoint;
use Illuminate\Support\Collection;

class CheckpointFactory
{
    /** Các checkpoint_type áp dụng cho toàn bộ orders trong trip (nhánh A). */
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
     * Nhánh A: tạo 1 checkpoint cho mỗi order trong trip.
     *
     * @return Collection<int, TripCheckpoint>
     */
    private function createForAllOrders(Trip $trip, array $payload, CheckpointType $type): Collection
    {
        return $trip->orders
            ->reject(function ($order) use ($trip, $type) {
                if ($type === CheckpointType::DriverSwap) {
                    return false;
                }

                $existing = TripCheckpoint::where('trip_id', $trip->id)
                    ->where('order_id', $order->id)
                    ->where('checkpoint_type', $type->value)
                    ->first();

                if ($existing && $existing->driver_id !== $trip->driver_id && $existing->km_reading === null) {
                    $existing->delete();

                    return false;
                }

                return $existing !== null;
            })
            ->map(fn ($order) => TripCheckpoint::create(
                $this->buildData($trip, $payload, $type, $order->id, $payload['delivery_point_id'] ?? null)
            ));
    }

    /**
     * Nhánh B: tạo checkpoint cho nhóm orders cùng location_id (hoặc đơn lẻ nếu không có location).
     *
     * @return Collection<int, TripCheckpoint>
     */
    private function createForDeliveryGroup(Trip $trip, array $payload, CheckpointType $type): Collection
    {
        $deliveryPoints = $this->resolveDeliveryGroup($trip, $payload);

        $created = collect();

        foreach ($deliveryPoints as $dp) {
            $existing = TripCheckpoint::where('trip_id', $trip->id)
                ->where('order_id', $dp->order_id)
                ->where('checkpoint_type', $type->value)
                ->where('delivery_point_id', $dp->id)
                ->first();

            if ($existing && $existing->driver_id !== $trip->driver_id && $existing->km_reading === null) {
                $existing->delete();
                $existing = null;
            }

            if ($existing !== null) {
                continue;
            }

            $created->push(TripCheckpoint::create(
                $this->buildData($trip, $payload, $type, $dp->order_id, $dp->id)
            ));
        }

        return $created;
    }

    /**
     * Tìm tất cả OrderDeliveryPoints cùng location_id với điểm được chọn.
     * Đối với order sở hữu điểm chọn, giữ nguyên điểm được chọn (tránh ghi đè khi 1 order có nhiều điểm trùng location).
     * Đối với các order khác trong trip có cùng location_id, chọn điểm chưa giao tiếp theo.
     * Fallback về order đơn lẻ nếu không có location grouping.
     *
     * Luôn trả về Collection<int, OrderDeliveryPoint> — không dùng stdClass.
     *
     * @return Collection<int, OrderDeliveryPoint>
     */
    private function resolveDeliveryGroup(Trip $trip, array $payload): Collection
    {
        $deliveryPointId = $payload['delivery_point_id'] ?? null;

        if ($deliveryPointId !== null) {
            $point = OrderDeliveryPoint::find($deliveryPointId);

            if ($point !== null) {
                if ($point->location_id === null) {
                    return collect([$point]);
                }

                $groupedPoints = collect([$point]);

                $otherOrderPoints = OrderDeliveryPoint::where('location_id', $point->location_id)
                    ->where('order_id', '!=', $point->order_id)
                    ->whereHas('order', fn ($q) => $q->where('trip_id', $trip->id))
                    ->where('status', '!=', OrderDeliveryPointStatus::Delivered->value)
                    ->orderBy('sequence')
                    ->get()
                    ->groupBy('order_id')
                    ->map(fn ($points) => $points->first());

                return $groupedPoints->merge($otherOrderPoints->values());
            }
        }

        // Không có delivery_point_id → fallback về order đơn lẻ từ payload
        $orderId = $payload['order_id'] ?? null;
        if ($orderId === null) {
            return collect();
        }

        // Tạo unsaved model để giữ type nhất quán, id = null (giao không qua điểm cố định)
        $stub = new OrderDeliveryPoint;
        $stub->order_id = $orderId;
        $stub->id = null;

        return collect([$stub]);
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
            'gps_lat' => $payload['gps_lat'] ?? $trip->vehicle?->gps_lat,
            'gps_lng' => $payload['gps_lng'] ?? $trip->vehicle?->gps_lng,
            'voice_note' => $payload['voice_note'] ?? null,
        ];
    }
}
