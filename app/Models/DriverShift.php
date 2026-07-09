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
            'tripCheckpoints.order',
        ]);

        $activities = collect();

        $sortedSegments = $this->trips->sortBy('started_at')->values();

        $sortedSegments->each(function ($trip, $index) use ($activities) {
            if ($trip->started_at) {
                $label = 'Bắt đầu chuyến';
                $color = 'background-color: #f0fdf4; color: #15803d;';
                $text = sprintf('Mã chuyến: %s | Km: %s km',
                    $trip->trip_code,
                    number_format((float) $trip->start_km, 1),
                );
                $html = "<span style='padding: 2px 6px; {$color} border-radius: 4px; font-size: 0.85rem; font-weight: 600; margin-right: 8px;'>{$label}</span> {$text}";

                $activities->push([
                    'time' => $trip->started_at,
                    'type' => 'trip_start',
                    'vehicle' => $trip->vehicle?->plate_number,
                    'details' => $html,
                    'group_index' => $index,
                    'sub_priority' => 0,
                    'time_for_sort' => $trip->started_at,
                ]);
            }
            if ($trip->completed_at) {
                $label = 'Kết thúc chuyến';
                $color = 'background-color: #fef2f2; color: #b91c1c;';
                $text = sprintf('Mã chuyến: %s | Km: %s km (Tổng chạy: %s km)',
                    $trip->trip_code,
                    number_format((float) $trip->end_km, 1),
                    number_format(max(0, (float) $trip->total_km), 1),
                );
                $html = "<span style='padding: 2px 6px; {$color} border-radius: 4px; font-size: 0.85rem; font-weight: 600; margin-right: 8px;'>{$label}</span> {$text}";

                $activities->push([
                    'time' => $trip->completed_at,
                    'type' => 'trip_end',
                    'vehicle' => $trip->vehicle?->plate_number,
                    'details' => $html,
                    'group_index' => $index,
                    'sub_priority' => 2,
                    'time_for_sort' => $trip->completed_at,
                ]);
            }
        });

        $this->tripCheckpoints->each(function ($tc) use ($activities, $sortedSegments) {
            // Bỏ qua end checkpoint không có đơn hàng (return trip)
            if ($tc->checkpoint_type === CheckpointType::End && ! $tc->order_id) {
                return;
            }

            $checkpointLabel = $tc->checkpoint_type?->getLabel() ?? $tc->checkpoint_type;

            $orderCode = $tc->order?->order_code ?? '-';

            $text = "Đơn: {$orderCode}";
            if ($tc->km_reading) {
                $text .= sprintf(' | Số Km: %s km', number_format((float) $tc->km_reading, 1));
            }
            if ($tc->voice_note) {
                $text .= " | Ghi chú: {$tc->voice_note}";
            }

            $html = "<span style='padding: 2px 6px; background-color: #e0f2fe; color: #0369a1; border-radius: 4px; font-size: 0.85rem; font-weight: 600; margin-right: 8px;'>{$checkpointLabel}</span> {$text}";

            $groupIndex = -1;
            foreach ($sortedSegments as $index => $trip) {
                if ($tc->occurred_at && $trip->started_at && $tc->occurred_at->greaterThanOrEqualTo($trip->started_at)) {
                    $groupIndex = $index;
                }
            }

            $activities->push([
                'time' => $tc->occurred_at,
                'type' => 'order_checkpoint',
                'checkpoint_label' => $checkpointLabel,
                'order_code' => $tc->order?->order_code,
                'order_id' => $tc->order_id,
                'vehicle' => $tc->trip?->vehicle?->plate_number,
                'details' => $html,
                'gps' => $tc->gps_lat && $tc->gps_lng ? "{$tc->gps_lat}, {$tc->gps_lng}" : null,
                'group_index' => $groupIndex,
                'sub_priority' => 1,
                'time_for_sort' => $tc->occurred_at,
            ]);
        });

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
