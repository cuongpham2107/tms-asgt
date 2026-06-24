<?php

namespace App\Services\Trip;

use App\Enums\OrderDeliveryPointStatus;
use App\Models\Location;
use App\Models\OrderDeliveryPoint;

class DeliveryPointResolver
{
    /**
     * Tạo OrderDeliveryPoint mới nếu payload có new_delivery_location_id.
     * Ghi delivery_point_id ngược lại vào $payload (pass-by-reference).
     *
     * @param  array<string, mixed>  $payload
     */
    public function resolve(array &$payload): void
    {
        if (! empty($payload['delivery_point_id'])) {
            return;
        }

        $newLocationId = $payload['new_delivery_location_id'] ?? null;
        $orderId = $payload['order_id'] ?? null;

        if ($newLocationId === null || $orderId === null) {
            return;
        }

        /** @var Location|null $location */
        $location = Location::find($newLocationId);
        if ($location === null) {
            return;
        }

        $deliveryPoint = $this->createDeliveryPoint((int) $orderId, $location);

        $payload['delivery_point_id'] = $deliveryPoint->id;
    }

    private function createDeliveryPoint(int $orderId, Location $location): OrderDeliveryPoint
    {
        $maxSequence = OrderDeliveryPoint::where('order_id', $orderId)->max('sequence') ?? 0;

        return OrderDeliveryPoint::create([
            'order_id' => $orderId,
            'location_id' => $location->id,
            'sequence' => $maxSequence + 1,
            'address' => $location->address ?? $location->name,
            'contact_person' => $location->contact_person,
            'contact_phone' => $location->contact_phone,
            'total_packages' => 0,
            'total_weight' => 0,
            'status' => OrderDeliveryPointStatus::Pending,
        ]);
    }
}
