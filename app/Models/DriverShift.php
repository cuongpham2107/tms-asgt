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
            // Nullify shift reference in orders to keep the orders
            $shift->orders()->update(['shift_id' => null]);

            // Delete dependent records
            $shift->shiftVehicles()->delete();
            $shift->tripCheckpoints()->delete();
            $shift->driverSwaps()->delete();

            // Vehicle.current_driver_id is a static/default field — not modified here.
        });
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function tripCheckpoints(): HasMany
    {
        return $this->hasMany(TripCheckpoint::class, 'shift_id');
    }

    public function driverSwaps(): HasMany
    {
        return $this->hasMany(DriverSwap::class, 'from_shift_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'shift_id');
    }

    public function shiftVehicles(): HasMany
    {
        return $this->hasMany(ShiftVehicle::class, 'shift_id');
    }

    public function currentShiftVehicle(): ?ShiftVehicle
    {
        return $this->shiftVehicles()->whereNull('end_time')->latest('start_time')->first();
    }

    public function lastSegment(): ?ShiftVehicle
    {
        return $this->shiftVehicles()->whereNotNull('end_time')->latest('end_time')->first();
    }

    public function firstSegment(): ?ShiftVehicle
    {
        return $this->shiftVehicles()->oldest('start_time')->first();
    }

    public function firstVehicle(): ?Vehicle
    {
        return $this->shiftVehicles()->oldest('start_time')->first()?->vehicle;
    }

    public function lastVehicle(): ?Vehicle
    {
        return $this->shiftVehicles()->whereNotNull('vehicle_id')->latest('start_time')->first()?->vehicle;
    }

    /**
     * Lấy dòng lịch sử hoạt động chi tiết của ca trực gộp từ hai bảng:
     * nhận/trả xe (shift_vehicles) và check-point đơn hàng (trip_checkpoints).
     */
    public function getActivityTimelineAttribute(): Collection
    {
        $this->loadMissing([
            'shiftVehicles.vehicle',
            'tripCheckpoints.order.vehicle',
        ]);

        $activities = collect();

        // Sắp xếp các segments theo start_time tăng dần làm trục nhóm
        $sortedSegments = $this->shiftVehicles->sortBy('start_time')->values();

        // 1. Nạp các mốc dùng xe
        $sortedSegments->each(function ($sv, $index) use ($activities) {
            if ($sv->start_time) {
                $label = 'Nhận xe';
                $color = 'background-color: #f0fdf4; color: #15803d;';
                $text = sprintf('Số Km: %s km', number_format((float) $sv->start_km, 1));
                $html = "<span style='padding: 2px 6px; {$color} border-radius: 4px; font-size: 0.85rem; font-weight: 600; margin-right: 8px;'>{$label}</span> {$text}";

                $activities->push([
                    'time' => $sv->start_time,
                    'type' => 'vehicle_start',
                    'vehicle' => $sv->vehicle?->plate_number,
                    'details' => $html,
                    'gps' => $sv->start_gps_lat && $sv->start_gps_lng ? "{$sv->start_gps_lat}, {$sv->start_gps_lng}" : null,
                    'group_index' => $index,
                    'sub_priority' => 0,
                    'time_for_sort' => $sv->start_time,
                ]);
            }
            if ($sv->end_time) {
                $label = 'Trả xe';
                $color = 'background-color: #fef2f2; color: #b91c1c;';
                $text = sprintf('Số Km: %s km (Tổng chạy: %s km)',
                    number_format((float) $sv->end_km, 1),
                    number_format(max(0, (float) $sv->end_km - (float) $sv->start_km), 1)
                );
                $html = "<span style='padding: 2px 6px; {$color} border-radius: 4px; font-size: 0.85rem; font-weight: 600; margin-right: 8px;'>{$label}</span> {$text}";

                $activities->push([
                    'time' => $sv->end_time,
                    'type' => 'vehicle_end',
                    'vehicle' => $sv->vehicle?->plate_number,
                    'details' => $html,
                    'gps' => $sv->end_gps_lat && $sv->end_gps_lng ? "{$sv->end_gps_lat}, {$sv->end_gps_lng}" : null,
                    'group_index' => $index,
                    'sub_priority' => 2,
                    'time_for_sort' => $sv->end_time,
                ]);
            }
        });

        // 2. Nạp các mốc xử lý đơn hàng
        $this->tripCheckpoints->each(function ($tc) use ($activities, $sortedSegments) {
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

            // Tìm xem checkpoint này thuộc segment nào dựa trên mốc thời gian
            $groupIndex = -1;
            foreach ($sortedSegments as $index => $sv) {
                if ($tc->occurred_at && $sv->start_time && $tc->occurred_at->greaterThanOrEqualTo($sv->start_time)) {
                    $groupIndex = $index;
                }
            }

            $activities->push([
                'time' => $tc->occurred_at,
                'type' => 'order_checkpoint',
                'checkpoint_label' => $checkpointLabel,
                'order_code' => $tc->order?->order_code,
                'order_id' => $tc->order_id,
                'vehicle' => $tc->order?->vehicle?->plate_number,
                'details' => $html,
                'gps' => $tc->gps_lat && $tc->gps_lng ? "{$tc->gps_lat}, {$tc->gps_lng}" : null,
                'group_index' => $groupIndex,
                'sub_priority' => 1,
                'time_for_sort' => $tc->occurred_at,
            ]);
        });

        // Sắp xếp các hoạt động theo trình tự logic: group_index -> sub_priority -> time_for_sort
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

    /**
     * Lấy danh sách đơn hàng thực hiện trong ca trực cùng với chi tiết chỉ số km.
     */
    public function getOrdersWithKmDetailsAttribute(): Collection
    {
        return $this->orders->map(function ($order) {
            $arrivedCheckpoint = $this->tripCheckpoints
                ->where('order_id', $order->id)
                ->where('checkpoint_type', CheckpointType::ArrivedPickup)
                ->first();

            $completedCheckpoint = $this->tripCheckpoints
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
                'vehicle_plate' => $order->vehicle?->plate_number ?? '-',
                'start_km' => $startKm ? number_format((float) $startKm, 1).' km' : '-',
                'end_km' => $endKm ? number_format((float) $endKm, 1).' km' : '-',
                'loaded_km' => $loadedKm > 0 ? number_format((float) $loadedKm, 1).' km' : '-',
                'status' => $order->status?->getLabel() ?? $order->status,
                'pickup_time' => $arrivedCheckpoint?->occurred_at?->format('d/m/Y H:i') ?? '-',
                'completed_time' => $completedCheckpoint?->occurred_at?->format('d/m/Y H:i') ?? '-',
            ];
        });
    }
}
