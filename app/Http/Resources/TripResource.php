<?php

namespace App\Http\Resources;

use App\Models\Trip;
use App\Services\Trip\TripLegService;
use App\Services\Trip\TripStateMachine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property Trip $resource
 */
class TripResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $myKm = null;
        $myLoaded = null;
        $myEmpty = null;
        $isMultiDriver = false;

        if ($user) {
            $userAssignments = $this->relationLoaded('driverAssignments')
                ? $this->driverAssignments->where('driver_id', $user->id)
                : $this->driverAssignments()->where('driver_id', $user->id)->get();

            $totalAssignmentsCount = $this->relationLoaded('driverAssignments')
                ? $this->driverAssignments->count()
                : $this->driverAssignments()->count();

            $isMultiDriver = $totalAssignmentsCount > 1;

            if ($userAssignments->isNotEmpty()) {
                $myKm = (float) $userAssignments->sum('km');
                $myLoaded = (float) $userAssignments->sum('km_loaded');
                $myEmpty = (float) $userAssignments->sum('km_empty');
            } elseif ((int) $this->driver_id === (int) $user->id) {
                $myKm = $this->total_km !== null ? (float) $this->total_km : null;
                $myLoaded = $this->total_km_loaded !== null ? (float) $this->total_km_loaded : null;
                $myEmpty = $this->total_km_empty !== null ? (float) $this->total_km_empty : null;
            }
        }

        $data = [
            'id' => $this->id,
            'driver_id' => $this->driver_id,
            'shift_id' => $this->shift_id,
            'trip_code' => $this->trip_code,
            'vehicle_id' => $this->vehicle_id,
            'status' => $this->status,
            'status_label' => $this->status?->getLabel(),
            'available_actions' => app(TripStateMachine::class)->availableActions($this->resource, $request->user()),
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'total_km' => $this->total_km,
            'total_km_loaded' => $this->total_km_loaded,
            'total_km_empty' => $this->total_km_empty,
            'driver_km' => $myKm !== null ? round($myKm, 1) : null,
            'driver_km_loaded' => $myLoaded !== null ? round($myLoaded, 1) : null,
            'driver_km_empty' => $myEmpty !== null ? round($myEmpty, 1) : null,
            'is_multi_driver' => $isMultiDriver,
            'is_empty_run' => $this->is_empty_run,
            'note' => $this->note,

            'vehicle' => $this->whenLoaded('vehicle', fn () => [
                'id' => $this->vehicle->id,
                'plate_number' => $this->vehicle->plate_number,
            ]),

            'route' => $this->buildRoute(),

            'shift' => $this->whenLoaded('shift', fn () => DriverShiftResource::make($this->shift)),

            'orders' => OrderResource::collection($this->whenLoaded('orders')),

            'checkpoints' => TripCheckpointResource::collection($this->whenLoaded('checkpoints')),

            'legs' => $this->when(
                $this->relationLoaded('checkpoints'),
                fn () => app(TripLegService::class)->calculateLegs($this->resource)
            ),

            'driver_assignments' => TripDriverAssignmentResource::collection($this->whenLoaded('driverAssignments')),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];

        return $data;
    }

    private function buildRoute(): ?string
    {
        if ($this->relationLoaded('orders') && $this->orders->isNotEmpty()) {
            $codes = [];
            $orders = $this->orders->sortBy('planned_loading_at');
            foreach ($orders as $order) {
                if ($order->relationLoaded('pickupLocation')) {
                    $code = $order->pickupLocation?->code ?? $order->pickup_address;
                    if ($code) {
                        $codes[] = $code;
                    }
                }
                if ($order->relationLoaded('deliveryPoints')) {
                    foreach ($order->deliveryPoints->sortBy('sequence') as $dp) {
                        $code = $dp->location?->code ?? $dp->address;
                        if ($code) {
                            $codes[] = $code;
                        }
                    }
                }
            }

            if (! empty($codes)) {
                $deduped = [];
                foreach ($codes as $c) {
                    if (empty($deduped) || end($deduped) !== $c) {
                        $deduped[] = $c;
                    }
                }

                return implode(' → ', $deduped);
            }
        }

        if ($this->relationLoaded('startLocation') || $this->relationLoaded('endLocation')) {
            $from = $this->startLocation?->code;
            $to = $this->endLocation?->code;

            if ($from && $to) {
                return "{$from} → {$to}";
            }

            return $from ?? $to;
        }

        return null;
    }
}
