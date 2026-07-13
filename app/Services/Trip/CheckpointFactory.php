<?php

namespace App\Services\Trip;

use App\Enums\CheckpointType;
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
     * Nếu đã có checkpoint template (km_reading = null) cùng type+order → update.
     *
     * @return Collection<int, TripCheckpoint>
     */
    private function createForAllOrders(Trip $trip, array $payload, CheckpointType $type): Collection
    {
        $hasKm = ! empty($payload['km_reading'] ?? null);

        return $trip->orders
            ->map(function ($order) use ($trip, $payload, $type, $hasKm) {
                $data = $this->buildData($trip, $payload, $type, $order->id, $payload['delivery_point_id'] ?? null);

                if ($hasKm) {
                    $existing = TripCheckpoint::where('trip_id', $trip->id)
                        ->where('order_id', $order->id)
                        ->where('checkpoint_type', $type->value)
                        ->whereNull('km_reading')
                        ->first();

                    if ($existing) {
                        $existing->update($data);

                        return $existing;
                    }
                }

                return TripCheckpoint::create($data);
            });
    }

    /**
     * Nhánh B: tạo checkpoint cho nhóm orders cùng location_id (hoặc đơn lẻ nếu không có location).
     * Nếu có payload km thật + đã có template (km_reading = null) → update.
     *
     * @return Collection<int, TripCheckpoint>
     */
    private function createForDeliveryGroup(Trip $trip, array $payload, CheckpointType $type): Collection
    {
        $deliveryPoints = $this->resolveDeliveryGroup($trip, $payload);
        $hasKm = ! empty($payload['km_reading'] ?? null);

        $created = collect();

        foreach ($deliveryPoints as $dp) {
            $data = $this->buildData($trip, $payload, $type, $dp->order_id, $dp->id);

            if ($hasKm) {
                $existing = TripCheckpoint::where('trip_id', $trip->id)
                    ->where('order_id', $dp->order_id)
                    ->where('checkpoint_type', $type->value)
                    ->where('delivery_point_id', $dp->id)
                    ->whereNull('km_reading')
                    ->first();

                if ($existing) {
                    $existing->update($data);
                    $created->push($existing);

                    continue;
                }
            }

            $alreadyExists = TripCheckpoint::where('trip_id', $trip->id)
                ->where('order_id', $dp->order_id)
                ->where('checkpoint_type', $type->value)
                ->where('delivery_point_id', $dp->id)
                ->exists();

            if ($alreadyExists) {
                continue;
            }

            $created->push(TripCheckpoint::create($data));
        }

        return $created;
    }

    /**
     * Tìm tất cả OrderDeliveryPoints cùng location_id với điểm được chọn.
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

            // Nếu điểm này thuộc một location cụ thể → group tất cả orders cùng location
            if ($point?->location_id !== null) {
                return OrderDeliveryPoint::where('location_id', $point->location_id)
                    ->whereHas('order', fn ($q) => $q->where('trip_id', $trip->id))
                    ->get()
                    ->keyBy('order_id');
            }

            // Có delivery_point_id nhưng không có location_id → dùng model thật
            if ($point !== null) {
                return collect([$point->order_id => $point]);
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

        return collect([$orderId => $stub]);
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
            'shift_id' => $trip->shift_id,
            'checkpoint_type' => $type->value,
            'occurred_at' => $payload['occurred_at'] ?? now(),
            'km_reading' => $kmReading,
            'gps_lat' => $payload['gps_lat'] ?? null,
            'gps_lng' => $payload['gps_lng'] ?? null,
            'voice_note' => $payload['voice_note'] ?? null,
        ];
    }
}
