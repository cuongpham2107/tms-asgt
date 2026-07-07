<?php

namespace App\Services\Trip\Handlers;

use App\Enums\OrderDeliveryPointStatus;
use App\Enums\OrderStatus;
use App\Enums\VehicleStatus;
use App\Models\Order;
use App\Models\OrderDeliveryPoint;
use App\Models\Trip;
use App\Models\TripCheckpoint;
use App\Models\Vehicle;
use App\Services\Trip\DeliveryPointStatusUpdater;
use Illuminate\Support\Collection;

class CompletedHandler implements CheckpointHandlerInterface
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
        $occurredAt = $payload['occurred_at'] ?? now();

        $this->completeDeliveryPoints($checkpoints, $occurredAt);
        $this->completeOrders($checkpoints);
        $this->completeTripIfAllOrdersDone($trip, $payload, $occurredAt);
        $this->resetVehicleIfIdle($trip);
    }

    /** @param  Collection<int, TripCheckpoint>  $checkpoints */
    private function completeDeliveryPoints(Collection $checkpoints, string $occurredAt): void
    {
        foreach ($checkpoints as $checkpoint) {
            $this->statusUpdater->update(
                $checkpoint->delivery_point_id,
                OrderDeliveryPointStatus::Delivered,
                $occurredAt,
            );
        }
    }

    /** @param  Collection<int, TripCheckpoint>  $checkpoints */
    private function completeOrders(Collection $checkpoints): void
    {
        $orderIds = $checkpoints->pluck('order_id')->unique();

        foreach ($orderIds as $orderId) {
            $hasUndeliveredPoints = OrderDeliveryPoint::where('order_id', $orderId)
                ->where('status', '!=', OrderDeliveryPointStatus::Delivered)
                ->exists();

            if (! $hasUndeliveredPoints) {
                Order::where('id', $orderId)->update(['status' => OrderStatus::Completed]);

                // Record per-order loaded_km
                $pickupCheckpoint = TripCheckpoint::where('order_id', $orderId)
                    ->where('checkpoint_type', 'arrived_pickup')
                    ->whereNotNull('km_reading')
                    ->first();

                $completeCheckpoint = TripCheckpoint::where('order_id', $orderId)
                    ->where('checkpoint_type', 'completed')
                    ->whereNotNull('km_reading')
                    ->orderBy('km_reading', 'desc')
                    ->first();

                if ($pickupCheckpoint && $completeCheckpoint) {
                    $loadedKm = max(0, (float) $completeCheckpoint->km_reading - (float) $pickupCheckpoint->km_reading);
                    Order::where('id', $orderId)->update(['loaded_km' => $loadedKm]);
                }
            }
        }
    }

    /** @param  array<string, mixed>  $payload */
    private function completeTripIfAllOrdersDone(Trip $trip, array $payload, string $occurredAt): void
    {
        $allDone = $trip->orders()
            ->where('status', '!=', OrderStatus::Completed)
            ->doesntExist();

        if ($allDone) {
            $trip->complete(
                endKm: $payload['km_reading'] ?? null,
                completedAt: $occurredAt,
            );
        }
    }

    private function resetVehicleIfIdle(Trip $trip): void
    {
        $vehicleStillActive = Order::whereHas(
            'trip',
            fn ($q) => $q->where('vehicle_id', $trip->vehicle_id)
        )
            ->whereIn('status', [OrderStatus::Assigned, OrderStatus::Sent])
            ->exists();

        if (! $vehicleStillActive) {
            Vehicle::where('id', $trip->vehicle_id)->update(['status' => VehicleStatus::On]);
        }
    }
}
