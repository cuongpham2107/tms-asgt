<?php

namespace App\Http\Resources;

use App\Models\DriverShift;
use App\Models\TripDriverAssignment;
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
        $firstTrip = $this->trips()->first();
        $latestTrip = $this->trips()->latest('started_at')->first();
        $displayTrip = $latestTrip ?? $firstTrip;

        $totalKm = $this->total_km;
        $totalKmLoaded = $this->total_km_loaded;
        $totalKmEmpty = $this->total_km_empty;

        if ($totalKm === null) {
            $assignments = TripDriverAssignment::query()
                ->where('shift_id', $this->id)
                ->where('driver_id', $this->driver_id)
                ->with('trip')
                ->get();

            $sumKm = 0.0;
            $sumLoaded = 0.0;
            $hasAny = false;

            foreach ($assignments as $a) {
                if ($a->km !== null && (float) $a->km > 0) {
                    $sumKm += (float) $a->km;
                    $sumLoaded += (float) ($a->km_loaded ?? 0);
                    $hasAny = true;
                } elseif ($a->trip) {
                    $tKm = $a->trip->total_km;
                    if ($tKm !== null && (float) $tKm > 0) {
                        $sumKm += (float) $tKm;
                        $sumLoaded += (float) ($a->trip->total_km_loaded ?? 0);
                        $hasAny = true;
                    }
                }
            }

            if ($hasAny) {
                $totalKm = round($sumKm, 1);
                $totalKmLoaded = round($sumLoaded, 1);
                $totalKmEmpty = round(max(0.0, $totalKm - $totalKmLoaded), 1);
            }
        }

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
            ] : null,
            'shift_type' => $this->shift_type,
            'start_time' => $this->start_time?->toDateTimeString(),
            'start_gps_lat' => $this->start_gps_lat,
            'start_gps_lng' => $this->start_gps_lng,
            'end_time' => $this->end_time?->toDateTimeString(),
            'end_gps_lat' => $this->end_gps_lat,
            'end_gps_lng' => $this->end_gps_lng,
            'total_km' => $totalKm,
            'total_km_loaded' => $totalKmLoaded,
            'total_km_empty' => $totalKmEmpty,
            'trips' => $this->whenLoaded('trips', fn () => $this->trips->map(function ($trip) {
                $userAssignment = $trip->relationLoaded('driverAssignments')
                    ? $trip->driverAssignments->where('driver_id', $this->driver_id)
                    : $trip->driverAssignments()->where('driver_id', $this->driver_id)->get();

                // Không có lượt lái của chủ ca trên chuyến này: chỉ quy về cả chuyến nếu chủ ca là
                // tài xế chính, ngược lại = 0 (không gán nhầm km cả chuyến cho người không lái).
                $isTripDriver = (int) $trip->driver_id === (int) $this->driver_id;
                $myKm = $userAssignment->isNotEmpty() ? (float) $userAssignment->sum('km') : ($isTripDriver ? (float) $trip->total_km : 0.0);
                $myLoaded = $userAssignment->isNotEmpty() ? (float) $userAssignment->sum('km_loaded') : ($isTripDriver ? (float) $trip->total_km_loaded : 0.0);
                $myEmpty = $userAssignment->isNotEmpty() ? (float) $userAssignment->sum('km_empty') : ($isTripDriver ? (float) $trip->total_km_empty : 0.0);

                return [
                    'id' => $trip->id,
                    'trip_code' => $trip->trip_code,
                    'vehicle_id' => $trip->vehicle_id,
                    'status' => $trip->status,
                    'vehicle' => $trip->vehicle ? [
                        'id' => $trip->vehicle->id,
                        'plate_number' => $trip->vehicle->plate_number,
                    ] : null,
                    'started_at' => $trip->started_at?->toDateTimeString(),
                    'completed_at' => $trip->completed_at?->toDateTimeString(),
                    'total_km' => $trip->total_km,
                    'total_km_loaded' => $trip->total_km_loaded,
                    'total_km_empty' => $trip->total_km_empty,
                    'driver_km' => round($myKm, 1),
                    'driver_km_loaded' => round($myLoaded, 1),
                    'driver_km_empty' => round($myEmpty, 1),
                ];
            })),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
