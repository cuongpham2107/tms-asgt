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
            'vehicle_id' => $this->vehicle_id,
            'shift_type' => $this->shift_type,
            'start_time' => $this->start_time?->toDateTimeString(),
            'start_km' => $this->start_km,
            /** @var float|null GPS latitude of shift start */
            'start_gps_lat' => $this->start_gps_lat,
            /** @var float|null GPS longitude of shift start */
            'start_gps_lng' => $this->start_gps_lng,
            'end_time' => $this->end_time?->toDateTimeString(),
            'end_km' => $this->end_km,
            /** @var float|null GPS latitude of shift end */
            'end_gps_lat' => $this->end_gps_lat,
            /** @var float|null GPS longitude of shift end */
            'end_gps_lng' => $this->end_gps_lng,
            'total_km' => $this->total_km,
            'total_km_loaded' => $this->total_km_loaded,
            'total_km_empty' => $this->total_km_empty,
            /** @var string ISO 8601 */
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
