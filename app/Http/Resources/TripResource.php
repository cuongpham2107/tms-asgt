<?php

namespace App\Http\Resources;

use App\Models\Trip;
use App\Services\TripKmSplitService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property Trip $resource
 */
class TripResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = [
            'id' => $this->id,
            'driver_id' => $this->driver_id,
            'trip_code' => $this->trip_code,
            'vehicle_id' => $this->vehicle_id,
            'status' => $this->status,
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'start_km' => $this->start_km,
            'end_km' => $this->end_km,
            'total_km' => $this->total_km,
            'total_km_loaded' => $this->total_km_loaded,
            'total_km_empty' => $this->total_km_empty,
            'is_empty_run' => $this->is_empty_run,
            'note' => $this->note,

            'vehicle' => $this->whenLoaded('vehicle', fn () => [
                'id' => $this->vehicle->id,
                'plate_number' => $this->vehicle->plate_number,
                'km_reading' => $this->vehicle->current_mileage,
            ]),

            'route' => $this->whenLoaded('startLocation', function () {
                $from = $this->startLocation?->code;
                $to = $this->endLocation?->code;

                if ($from && $to) {
                    return "{$from} → {$to}";
                }

                return null;
            }),

            'shift' => $this->whenLoaded('shift', fn () => DriverShiftResource::make($this->shift)),

            'orders' => OrderResource::collection($this->whenLoaded('orders')),

            'checkpoints' => TripCheckpointResource::collection($this->whenLoaded('checkpoints')),

            'driver_swaps' => DriverSwapResource::collection($this->whenLoaded('driverSwaps')),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];

        $adjusted = $this->adjustedKmForDriver($request->user()?->id);
        if ($adjusted !== null) {
            $data['total_km'] = $adjusted['total_km'];
            $data['total_km_loaded'] = $adjusted['total_km_loaded'];
            $data['total_km_empty'] = $adjusted['total_km_empty'];
        }

        return $data;
    }

    /**
     * Điều chỉnh km của chuyến theo phần mà tài xế hiện tại thực sự chạy,
     * cộng dồn tất cả các đoạn khi đảo lái qua lại nhiều lần.
     *
     * @return array{total_km: float, total_km_loaded: float, total_km_empty: float}|null
     */
    private function adjustedKmForDriver(?int $driverId): ?array
    {
        if ($driverId === null) {
            return null;
        }

        $segments = TripKmSplitService::segments($this->resource);

        if ($segments === []) {
            return null;
        }

        $loadedRanges = TripKmSplitService::loadedRanges($this->resource);

        $totalKm = 0.0;
        $loadedKm = 0.0;
        $found = false;

        foreach ($segments as [$segStart, $segEnd, $segShiftId, $segDriverId]) {
            if ((int) $segDriverId !== (int) $driverId) {
                continue;
            }

            $found = true;
            $totalKm += max(0.0, (float) $segEnd - (float) $segStart);

            foreach ($loadedRanges as [$loadStart, $loadEnd]) {
                $loadedKm += max(0.0, min((float) $segEnd, (float) $loadEnd) - max((float) $segStart, (float) $loadStart));
            }
        }

        if (! $found) {
            return null;
        }

        return [
            'total_km' => $totalKm,
            'total_km_loaded' => $loadedKm,
            'total_km_empty' => max(0.0, $totalKm - $loadedKm),
        ];
    }
}
