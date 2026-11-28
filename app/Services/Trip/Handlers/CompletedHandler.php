<?php

namespace App\Services\Trip\Handlers;

use App\Enums\OrderDeliveryPointStatus;
use App\Enums\OrderStatus;
use App\Enums\TripStatus;
use App\Models\Order;
use App\Models\OrderDeliveryPoint;
use App\Models\Trip;
use App\Models\TripCheckpoint;
use App\Models\Vehicle;
use App\Services\Km\OrderLoadedKm;
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
        $orderIds = $checkpoints->pluck('order_id')->filter()->unique();

        foreach ($orderIds as $orderId) {
            $order = Order::find($orderId);
            if (! $order) {
                continue;
            }

            $order->syncDeliveryPointSequencesFromCheckpoints();

            $hasUndeliveredPoints = OrderDeliveryPoint::where('order_id', $orderId)
                ->where('status', '!=', OrderDeliveryPointStatus::Delivered)
                ->exists();

            if (! $hasUndeliveredPoints) {
                $order->status = OrderStatus::Completed;

                $loadedKm = OrderLoadedKm::fromCheckpoints(TripCheckpoint::query()
                    ->where('order_id', $orderId)
                    ->whereIn('checkpoint_type', ['arrived_pickup', 'completed'])
                    ->whereNotNull('km_reading')
                    ->chronological()
                    ->get(['checkpoint_type', 'order_id', 'km_reading']))[(int) $orderId] ?? null;

                if ($loadedKm !== null) {
                    $order->loaded_km = $loadedKm;
                }

                $order->save();
            }
        }
    }

    /** @param  array<string, mixed>  $payload */
    private function completeTripIfAllOrdersDone(Trip $trip, array $payload, string $occurredAt): void
    {
        $allDone = $trip->orders()
            ->where('status', '!=', OrderStatus::Completed->value)
            ->doesntExist();

        if ($allDone) {
            $trip->status = TripStatus::Delivered;
            $trip->save();
        }
    }

    private function resetVehicleIfIdle(Trip $trip): void
    {
        Vehicle::syncIdleStatus($trip->vehicle_id);
    }
}
