<?php

namespace App\Http\Resources;

use App\Models\DriverSwap;
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

    private function adjustedKmForDriver(?int $driverId): ?array
    {
        if ($driverId === null) {
            return null;
        }

        // ——— Nhánh A: tài xế NHẬN chuyến (to_driver) → tính từ handover → end ———
        $toSwap = DriverSwap::where('trip_id', $this->id)
            ->where('to_driver_id', $driverId)
            ->where(function ($q) {
                $q->whereColumn('from_shift_id', '!=', 'to_shift_id')
                    ->orWhereColumn('from_driver_id', '!=', 'to_driver_id');
            })
            ->first();

        if ($toSwap) {
            return $this->adjustedForSwapIn($toSwap);
        }

        // ——— Nhánh B: tài xế BÀN GIAO (from_driver) → tính từ start → handover ———
        $fromSwap = DriverSwap::where('trip_id', $this->id)
            ->where('from_driver_id', $driverId)
            ->where(function ($q) {
                $q->whereColumn('from_shift_id', '!=', 'to_shift_id')
                    ->orWhereColumn('from_driver_id', '!=', 'to_driver_id');
            })
            ->first();

        if ($fromSwap) {
            return $this->adjustedForSwapOut($fromSwap);
        }

        return null;
    }

    private function adjustedForSwapIn(DriverSwap $swap): array
    {
        $handoverKm = (float) ($swap->handover_km ?? 0);

        if ($handoverKm <= 0) {
            $handoverKm = (float) $this->checkpoints()
                ->reorder()
                ->where('checkpoint_type', 'driver_swap')
                ->whereNotNull('km_reading')
                ->orderByDesc('occurred_at')
                ->first()?->km_reading ?? 0;
        }

        $shiftCheckpoints = $this->checkpoints()
            ->reorder()
            ->where('shift_id', $swap->to_shift_id)
            ->whereNotNull('km_reading')
            ->orderByDesc('occurred_at')
            ->get();

        $latestKm = $shiftCheckpoints->first()?->km_reading;
        $totalKm = $latestKm !== null ? max(0, (float) $latestKm - $handoverKm) : 0;

        $completedKm = $shiftCheckpoints
            ->where('checkpoint_type', 'completed')
            ->sortByDesc('occurred_at')
            ->first()?->km_reading;

        $loadedKm = $completedKm !== null ? max(0, (float) $completedKm - $handoverKm) : 0;

        return [
            'total_km' => $totalKm,
            'total_km_loaded' => $loadedKm,
            'total_km_empty' => max(0, $totalKm - $loadedKm),
        ];
    }

    private function adjustedForSwapOut(DriverSwap $swap): array
    {
        $handoverKm = (float) ($swap->handover_km ?? 0);

        if ($handoverKm <= 0) {
            $handoverKm = (float) $this->checkpoints()
                ->reorder()
                ->where('checkpoint_type', 'driver_swap')
                ->whereNotNull('km_reading')
                ->orderByDesc('occurred_at')
                ->first()?->km_reading ?? 0;
        }

        $totalKm = $handoverKm > 0 && $this->start_km > 0
            ? max(0, $handoverKm - (float) $this->start_km)
            : 0;

        $shiftCheckpoints = $this->checkpoints()
            ->reorder()
            ->where('shift_id', $swap->from_shift_id)
            ->whereNotNull('km_reading')
            ->orderBy('km_reading')
            ->get();

        $arrivedPickupKm = $shiftCheckpoints
            ->where('checkpoint_type', 'arrived_pickup')
            ->first()?->km_reading;

        $completedKm = $shiftCheckpoints
            ->where('checkpoint_type', 'completed')
            ->sortByDesc('km_reading')
            ->first()?->km_reading;

        if ($completedKm !== null && $arrivedPickupKm !== null) {
            $loadedKm = max(0, (float) $completedKm - (float) $arrivedPickupKm);
        } elseif ($arrivedPickupKm !== null) {
            $loadedKm = max(0, $handoverKm - (float) $arrivedPickupKm);
        } else {
            $loadedKm = 0;
        }

        return [
            'total_km' => $totalKm,
            'total_km_loaded' => $loadedKm,
            'total_km_empty' => max(0, $totalKm - $loadedKm),
        ];
    }
}
