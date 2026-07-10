<?php

namespace App\Models;

use App\Enums\CheckpointType;
use App\Enums\ShiftType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class DriverShift extends Model
{
    protected $fillable = [
        'driver_id',
        'shift_type',
        'start_time',
        'end_time',
        'start_km',
        'end_km',
        'start_gps_lat',
        'start_gps_lng',
        'end_gps_lat',
        'end_gps_lng',
        'total_km',
        'total_km_loaded',
        'total_km_empty',
    ];

    protected function casts(): array
    {
        return [
            'start_time' => 'datetime',
            'end_time' => 'datetime',
            'start_km' => 'decimal:1',
            'end_km' => 'decimal:1',
            'start_gps_lat' => 'decimal:7',
            'start_gps_lng' => 'decimal:7',
            'end_gps_lat' => 'decimal:7',
            'end_gps_lng' => 'decimal:7',
            'total_km' => 'decimal:1',
            'total_km_loaded' => 'decimal:1',
            'total_km_empty' => 'decimal:1',
            'shift_type' => ShiftType::class,
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (DriverShift $shift) {
            $shift->trips()->update(['shift_id' => null]);
            $shift->tripCheckpoints()->delete();
            $shift->driverSwaps()->delete();
        });
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class, 'shift_id');
    }

    public function tripCheckpoints(): HasMany
    {
        return $this->hasMany(TripCheckpoint::class, 'shift_id');
    }

    public function driverSwaps(): HasMany
    {
        return $this->hasMany(DriverSwap::class, 'from_shift_id');
    }

    public function getActivityTimelineAttribute(): Collection
    {
        $this->loadMissing([
            'trips.vehicle',
            'tripCheckpoints.order.deliveryPoints',
        ]);

        $activities = collect();

        $sortedSegments = $this->trips->sortBy('started_at')->values();

        // Trip start/end markers
        $sortedSegments->each(function ($trip, $index) use ($activities) {
            if ($trip->started_at) {
                $activities->push([
                    'time' => $trip->started_at,
                    'display' => view('filament.resources.driver-shifts.components.timeline-trip-start', [
                        'trip_code' => $trip->trip_code,
                        'km' => $trip->start_km ? number_format((float) $trip->start_km, 1).' km' : null,
                    ])->render(),
                    'vehicle' => $trip->vehicle?->plate_number,
                    'group_index' => $index,
                    'sub_priority' => 0,
                    'time_for_sort' => $trip->started_at,
                ]);
            }
            if ($trip->completed_at) {
                $activities->push([
                    'time' => $trip->completed_at,
                    'display' => view('filament.resources.driver-shifts.components.timeline-trip-end', [
                        'trip_code' => $trip->trip_code,
                        'km' => $trip->end_km ? number_format((float) $trip->end_km, 1).' km' : null,
                        'total_km' => max(0, (float) $trip->total_km),
                    ])->render(),
                    'vehicle' => $trip->vehicle?->plate_number,
                    'group_index' => $index,
                    'sub_priority' => 2,
                    'time_for_sort' => $trip->completed_at,
                ]);
            }
        });

        // Group checkpoints by (type, km, time) to collapse duplicates
        $groupedCheckpoints = $this->tripCheckpoints
            ->filter(fn ($tc) => ! ($tc->checkpoint_type === CheckpointType::End && ! $tc->order_id))
            ->groupBy(function ($tc) {
                $km = $tc->km_reading ? number_format((float) $tc->km_reading, 1, '.', '') : 'null';
                $time = $tc->occurred_at?->format('Y-m-d H:i:s') ?? 'null';
                return $tc->checkpoint_type->value.'|'.$km.'|'.$time;
            });

        foreach ($groupedCheckpoints as $group) {
            $first = $group->first();
            $orderCodes = $group->pluck('order.order_code')->filter()->unique()->values();
            $deliveryPointId = $first->delivery_point_id;

            // Get DP sequence if multiple DPs
            $dpLabel = '';
            if ($deliveryPointId && $first->order?->deliveryPoints->count() > 1) {
                $dp = $first->order->deliveryPoints->firstWhere('id', $deliveryPointId);
                if ($dp?->sequence) {
                    $dpLabel = " (Điểm {$dp->sequence})";
                }
            }

            $groupIndex = -1;
            foreach ($sortedSegments as $index => $trip) {
                if ($first->occurred_at && $trip->started_at && $first->occurred_at->gte($trip->started_at)) {
                    $groupIndex = $index;
                }
            }

            $activities->push([
                'time' => $first->occurred_at,
                'display' => view('filament.resources.driver-shifts.components.timeline-checkpoint', [
                    'checkpoint' => [
                        'checkpoint_type' => $first->checkpoint_type,
                        'order_codes' => $orderCodes->toArray(),
                        'km' => $first->km_reading ? number_format((float) $first->km_reading, 1).' km' : null,
                        'voice_note' => $first->voice_note,
                        'dp_label' => $dpLabel,
                    ],
                ])->render(),
                'vehicle' => $first->trip?->vehicle?->plate_number,
                'gps' => $first->gps_lat && $first->gps_lng ? "{$first->gps_lat}, {$first->gps_lng}" : null,
                'group_index' => $groupIndex,
                'sub_priority' => 1,
                'time_for_sort' => $first->occurred_at,
            ]);
        }

        return $activities->sort(function ($a, $b) {
            if ($a['group_index'] !== $b['group_index']) {
                return $a['group_index'] <=> $b['group_index'];
            }
            if ($a['sub_priority'] !== $b['sub_priority']) {
                return $a['sub_priority'] <=> $b['sub_priority'];
            }

            return $a['time_for_sort'] <=> $b['time_for_sort'];
        })->values();
    }

    public function getOrdersWithKmDetailsAttribute(): Collection
    {
        return $this->trips->flatMap(function ($trip) {
            return $trip->orders->map(function ($order) use ($trip) {
                $arrivedCheckpoint = $trip->checkpoints
                    ->where('order_id', $order->id)
                    ->where('checkpoint_type', CheckpointType::ArrivedPickup)
                    ->first();

                $completedCheckpoint = $trip->checkpoints
                    ->where('order_id', $order->id)
                    ->where('checkpoint_type', CheckpointType::Completed)
                    ->first();

                $startKm = $arrivedCheckpoint?->km_reading;
                $endKm = $completedCheckpoint?->km_reading;

                $loadedKm = 0;
                if ($startKm !== null && $endKm !== null) {
                    $loadedKm = max(0, (float) $endKm - (float) $startKm);
                }

                return [
                    'id' => $order->id,
                    'order_code' => $order->order_code,
                    'vehicle_plate' => $trip->vehicle?->plate_number ?? '-',
                    'start_km' => $startKm ? number_format((float) $startKm, 1).' km' : '-',
                    'end_km' => $endKm ? number_format((float) $endKm, 1).' km' : '-',
                    'loaded_km' => $loadedKm > 0 ? number_format((float) $loadedKm, 1).' km' : '-',
                    'status' => $order->status?->getLabel() ?? $order->status,
                    'pickup_time' => $arrivedCheckpoint?->occurred_at?->format('d/m/Y H:i') ?? '-',
                    'completed_time' => $completedCheckpoint?->occurred_at?->format('d/m/Y H:i') ?? '-',
                ];
            });
        });
    }
}
