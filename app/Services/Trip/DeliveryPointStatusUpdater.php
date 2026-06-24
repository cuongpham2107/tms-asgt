<?php

namespace App\Services\Trip;

use App\Enums\OrderDeliveryPointStatus;
use App\Models\OrderDeliveryPoint;

class DeliveryPointStatusUpdater
{
    public function update(
        ?int $deliveryPointId,
        OrderDeliveryPointStatus $status,
        ?string $occurredAt = null,
    ): void {
        if ($deliveryPointId === null) {
            return;
        }

        /** @var OrderDeliveryPoint|null $point */
        $point = OrderDeliveryPoint::find($deliveryPointId);
        if ($point === null) {
            return;
        }

        if (! $this->canTransitionTo($point, $status)) {
            return;
        }

        $point->status = $status;
        $this->applyTimestamp($point, $status, $occurredAt);
        $point->save();
    }

    private function canTransitionTo(OrderDeliveryPoint $point, OrderDeliveryPointStatus $status): bool
    {
        return match ($status) {
            OrderDeliveryPointStatus::Arrived => $point->status === OrderDeliveryPointStatus::Pending,
            OrderDeliveryPointStatus::Delivered => ! ($point->status === OrderDeliveryPointStatus::Delivered && $point->delivered_at !== null),
            default => true,
        };
    }

    private function applyTimestamp(OrderDeliveryPoint $point, OrderDeliveryPointStatus $status, ?string $occurredAt): void
    {
        match ($status) {
            OrderDeliveryPointStatus::Arrived => $point->arrived_at = $occurredAt ?? now(),
            OrderDeliveryPointStatus::Delivered => $point->delivered_at = $occurredAt ?? now(),
            default => null,
        };
    }
}
