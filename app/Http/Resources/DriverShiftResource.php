<?php

namespace App\Http\Resources;

use App\Enums\TripStatus;
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
        $nonPendingTrips = $this->trips()->whereNotIn('status', [TripStatus::Pending, TripStatus::Cancelled]);
        $firstTrip = (clone $nonPendingTrips)->first();
        $latestTrip = (clone $nonPendingTrips)->latest('started_at')->first();
        $displayTrip = $latestTrip ?? $firstTrip;

        return [
            'id' => $this->id,
            'driver_id' => $this->driver_id,
            'driver' => $this->whenLoaded('driver', fn () => UserResource::make($this->driver)),
            'vehicle_id' => $displayTrip?->vehicle_id,
            'vehicle' => $displayTrip?->vehicle ? [
                'id' => $displayTrip->vehicle->id,
                'plate_number' => $displayTrip->vehicle->plate_number,
                'vehicle_type' => $displayTrip->vehicle->vehicle_type,
                'load_capacity' => $displayTrip->vehicle->load_capacity,
                'current_mileage' => $displayTrip->vehicle->current_mileage,
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
            'trips' => $this->whenLoaded('trips', fn () => $this->trips
                ->filter(fn ($trip) => ! in_array(
                    $trip->status instanceof TripStatus ? $trip->status : TripStatus::tryFrom($trip->status),
                    [TripStatus::Pending, TripStatus::Cancelled],
                    true
                ))
                ->values()
                ->map(fn ($trip) => [
                    'id' => $trip->id,
                    'trip_code' => $trip->trip_code,
                    'vehicle_id' => $trip->vehicle_id,
                    'status' => $trip->status,
                    'vehicle' => $trip->vehicle ? [
                        'id' => $trip->vehicle->id,
                        'plate_number' => $trip->vehicle->plate_number,
                    ] : null,
                    'orders' => $trip->relationLoaded('orders') ? $trip->orders->map(fn ($o) => [
                        'id' => $o->id,
                        'order_code' => $o->order_code,
                    ]) : [],
                    'started_at' => $trip->started_at?->toDateTimeString(),
                    'completed_at' => $trip->completed_at?->toDateTimeString(),
                    'start_km' => $trip->start_km,
                    'end_km' => $trip->end_km,
                    'total_km' => $trip->total_km,
                    'total_km_loaded' => $trip->total_km_loaded,
                    'total_km_empty' => $trip->total_km_empty,
                ])),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
