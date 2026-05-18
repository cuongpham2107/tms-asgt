<?php

namespace App\Http\Resources;

use App\Models\OrderDeliveryPoint;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property OrderDeliveryPoint $resource
 */
class OrderDeliveryPointResource extends JsonResource
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
            'sequence' => $this->sequence,
            'address' => $this->address,
            'contact_person' => $this->contact_person,
            'contact_phone' => $this->contact_phone,
            'total_packages' => $this->total_packages,
            'total_weight' => $this->total_weight,
            'status' => $this->status,
            /** @var string|null ISO 8601 */
            'arrived_at' => $this->arrived_at?->toIso8601String(),
            /** @var string|null ISO 8601 */
            'delivered_at' => $this->delivered_at?->toIso8601String(),
        ];
    }
}
