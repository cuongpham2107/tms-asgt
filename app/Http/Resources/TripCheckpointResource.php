<?php

namespace App\Http\Resources;

use App\Models\TripCheckpoint;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property TripCheckpoint $resource
 */
class TripCheckpointResource extends JsonResource
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
            'order_id' => $this->order_id,
            'driver_id' => $this->driver_id,
            'shift_id' => $this->shift_id,
            'delivery_point_id' => $this->delivery_point_id,
            'checkpoint_type' => $this->checkpoint_type,
            /** @var string ISO 8601 */
            'occurred_at' => $this->occurred_at?->toIso8601String(),
            'km_reading' => $this->km_reading,
            /** @var float|null GPS latitude */
            'gps_lat' => $this->gps_lat,
            /** @var float|null GPS longitude */
            'gps_lng' => $this->gps_lng,
            'voice_note' => $this->voice_note,
            /** @var string ISO 8601 */
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
