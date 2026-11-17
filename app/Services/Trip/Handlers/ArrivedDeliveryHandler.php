<?php

namespace App\Services\Trip\Handlers;

use App\Enums\OrderDeliveryPointStatus;
use App\Enums\OrderStatus;
use App\Enums\TripStatus;
use App\Enums\VehicleStatus;
use App\Models\Order;
use App\Models\Trip;
use App\Models\TripCheckpoint;
use App\Models\Vehicle;
use App\Services\Trip\DeliveryPointStatusUpdater;
use Illuminate\Support\Collection;

class ArrivedDeliveryHandler implements CheckpointHandlerInterface
{
    public function __construct(
        private readonly DeliveryPointStatusUpdater $statusUpdater,
    ) {}

    /**
     * @param  Collection<int, TripCheckpoint>  $checkpoints
     * @param  array<string, mixed>  $payload
     */
    public function handle(Trip $trip, array $payload, Collection $checkpoints): void
    {
        $trip->status = TripStatus::ArrivedDelivery;
        $trip->save();

        $occurredAt = $payload['occurred_at'] ?? now();

        foreach ($checkpoints as $checkpoint) {
            $this->statusUpdater->update(
                $checkpoint->delivery_point_id,
                OrderDeliveryPointStatus::Arrived,
                $occurredAt,
            );
        }

        // Nếu tài xế chọn giao thêm điểm trả tiếp theo sau khi điểm trước đã completed → mở lại InTransit cho đơn và Running cho xe
        $orderIds = $checkpoints->pluck('order_id')->filter()->unique();
        if ($orderIds->isNotEmpty()) {
            Order::whereIn('id', $orderIds)
                ->where('status', OrderStatus::Completed->value)
                ->update(['status' => OrderStatus::InTransit->value]);
        }

        if ($trip->vehicle_id) {
            Vehicle::where('id', $trip->vehicle_id)
                ->where('status', VehicleStatus::On->value)
                ->update(['status' => VehicleStatus::Running->value]);
        }
    }
}
