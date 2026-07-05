<?php

namespace App\Http\Resources;

use App\Models\Trip;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property Trip $resource
 */
class TripResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'trip_code' => $this->trip_code,
            'vehicle_id' => $this->vehicle_id,
            'status' => $this->status,
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'start_km' => $this->start_km,
            'end_km' => $this->end_km,

            'vehicle' => $this->whenLoaded('vehicle', fn () => [
                'id' => $this->vehicle->id,
                'plate_number' => $this->vehicle->plate_number,
                'km_reading' => $this->vehicle->current_mileage,
            ]),

            'shift' => $this->whenLoaded('shift', fn () => DriverShiftResource::make($this->shift)),

            'orders' => OrderResource::collection($this->whenLoaded('orders')),

            'checkpoints' => TripCheckpointResource::collection($this->whenLoaded('checkpoints')),

            'driver_swaps' => DriverSwapResource::collection($this->whenLoaded('driverSwaps')),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
