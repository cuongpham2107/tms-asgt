<?php

namespace App\Http\Resources;

use App\Models\TripDriverAssignment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property TripDriverAssignment $resource
 */
class TripDriverAssignmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'driver_id' => $this->driver_id,
            'driver_name' => $this->whenLoaded('driver', fn () => $this->driver?->name),
            'shift_id' => $this->shift_id,
            'started_at' => $this->started_at?->toIso8601String(),
            'ended_at' => $this->ended_at?->toIso8601String(),
            'end_reason' => $this->end_reason,
            'end_reason_label' => $this->end_reason?->getLabel(),
            'note' => $this->note,
        ];
    }
}
