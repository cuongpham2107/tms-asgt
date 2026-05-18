<?php

namespace App\Http\Resources;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property Order $resource
 */
class OrderResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_code' => $this->order_code,
            'status' => $this->status,
            'priority' => $this->priority,
            'cargo_name' => $this->cargo_name,
            'cargo_type' => $this->cargo_type,
            'total_packages' => $this->total_packages,
            'total_weight' => $this->total_weight,
            /** @var string|null ISO 8601 */
            'planned_loading_at' => $this->planned_loading_at?->toIso8601String(),
            // Pickup
            'pickup_address' => $this->pickup_address,
            'pickup_contact' => $this->pickup_contact,
            'pickup_phone' => $this->pickup_phone,
            // Sender/Receiver
            'sender_name' => $this->sender_name,
            'sender_contact' => $this->sender_contact,
            'sender_phone' => $this->sender_phone,
            'receiver_name' => $this->receiver_name,
            'receiver_contact' => $this->receiver_contact,
            'receiver_phone' => $this->receiver_phone,
            // Vehicle (compact)
            /** @var array{id: int, plate_number: string, load_capacity: float}|null */
            'vehicle' => $this->whenLoaded('vehicle', fn () => [
                'id' => $this->vehicle->id,
                'plate_number' => $this->vehicle->plate_number,
                'load_capacity' => $this->vehicle->load_capacity,
            ]),
            // Freight
            'freight_rate' => $this->freight_rate,
            'surcharges' => $this->surcharges,
            'total_cost' => $this->total_cost,
            'notes' => $this->notes,
            // Delivery points (only when loaded)
            'delivery_points' => OrderDeliveryPointResource::collection($this->whenLoaded('deliveryPoints')),
            // Latest checkpoint time (progress indicator)
            /** @var string|null ISO 8601 */
            'last_checkpoint_at' => $this->when(
                $this->relationLoaded('tripCheckpoints') && $this->tripCheckpoints->isNotEmpty(),
                fn () => $this->tripCheckpoints->max('occurred_at')?->toIso8601String()
            ),
            // Timestamps
            /** @var string|null ISO 8601 */
            'sent_at' => $this->sent_at?->toIso8601String(),
            /** @var string ISO 8601 */
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
