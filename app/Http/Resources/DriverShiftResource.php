<?php

namespace App\Http\Resources;

use App\Models\DriverShift;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property DriverShift $resource
 */
class DriverShiftResource extends JsonResource
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
            'driver_id' => $this->driver_id,
            'driver' => $this->whenLoaded('driver', fn () => UserResource::make($this->driver)),
            'vehicle_id' => $this->firstVehicle()?->id,
            'vehicle' => $this->firstVehicle() ? [
                'id' => $this->firstVehicle()->id,
                'plate_number' => $this->firstVehicle()->plate_number,
                'vehicle_type' => $this->firstVehicle()->vehicle_type,
                'load_capacity' => $this->firstVehicle()->load_capacity,
                'current_mileage' => $this->firstVehicle()->current_mileage,
            ] : null,
            'shift_type' => $this->shift_type,
            'start_time' => $this->start_time?->toDateTimeString(),
            'start_km' => $this->start_km,
            'start_gps_lat' => $this->start_gps_lat,
            'start_gps_lng' => $this->start_gps_lng,
            'end_time' => $this->end_time?->toDateTimeString(),
            'end_km' => $this->end_km,
            'end_gps_lat' => $this->end_gps_lat,
            'end_gps_lng' => $this->end_gps_lng,
            'total_km' => $this->total_km,
            'total_km_loaded' => $this->total_km_loaded,
            'total_km_empty' => $this->total_km_empty,
            'shift_vehicles' => $this->whenLoaded('shiftVehicles', fn () => $this->shiftVehicles->map(fn ($sv) => [
                'id' => $sv->id,
                'vehicle_id' => $sv->vehicle_id,
                'vehicle' => $sv->vehicle ? [
                    'id' => $sv->vehicle->id,
                    'plate_number' => $sv->vehicle->plate_number,
                ] : null,
                'start_time' => $sv->start_time?->toDateTimeString(),
                'end_time' => $sv->end_time?->toDateTimeString(),
                'start_km' => $sv->start_km,
                'end_km' => $sv->end_km,
                'calculated_km' => $sv->end_km && $sv->start_km ? $sv->end_km - $sv->start_km : null,
            ])),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
