<?php

namespace App\Models;

use App\Enums\CheckpointType;
use App\Enums\GeoCheckStatus;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TripCheckpoint extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'trip_id',
        'order_id',
        'delivery_point_id',
        'checkpoint_type',
        'occurred_at',
        'adjusted_at',
        'km_reading',
        'gps_lat',
        'gps_lng',
        'gps_accuracy',
        'eup_lat',
        'eup_lng',
        'eup_recorded_at',
        'eup_speed',
        'vehicle_distance_m',
        'vehicle_geo_status',
        'location_distance_m',
        'location_geo_status',
        'voice_note',
        'driver_id',
        'shift_id',
        'vehicle_id',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'occurred_at' => 'datetime',
            'adjusted_at' => 'datetime',
            'km_reading' => 'decimal:1',
            'gps_lat' => 'decimal:7',
            'gps_lng' => 'decimal:7',
            'gps_accuracy' => 'integer',
            'checkpoint_type' => CheckpointType::class,
            'eup_lat' => 'decimal:7',
            'eup_lng' => 'decimal:7',
            'eup_recorded_at' => 'datetime',
            'eup_speed' => 'decimal:1',
            'vehicle_distance_m' => 'integer',
            'vehicle_geo_status' => GeoCheckStatus::class,
            'location_distance_m' => 'integer',
            'location_geo_status' => GeoCheckStatus::class,
        ];
    }

    /**
     * Mốc thời gian hiệu lực (ưu tiên mốc đã điều chỉnh phục vụ đối soát cước).
     */
    public function effectiveOccurredAt(): ?Carbon
    {
        return $this->adjusted_at ?? $this->occurred_at;
    }

    /**
     * Sắp xếp các mốc theo trình tự thời gian và logic vận hành:
     * 1. Thời gian xảy ra mốc (`occurred_at` tăng dần)
     * 2. Thứ tự logic theo loại mốc (Bắt đầu -> Đến lấy -> Rời lấy -> Đảo lái -> Đến giao -> Hoàn thành -> Kết thúc xe -> Huỷ)
     * 3. ID tăng dần (mốc tạo trước đứng trước)
     */
    public function scopeChronological(Builder $query): Builder
    {
        return $query->orderBy('occurred_at', 'asc')
            ->orderByRaw("CASE checkpoint_type 
                WHEN 'started' THEN 1 
                WHEN 'arrived_pickup' THEN 2 
                WHEN 'left_pickup' THEN 3 
                WHEN 'driver_swap' THEN 4 
                WHEN 'arrived_delivery' THEN 5 
                WHEN 'completed' THEN 6 
                WHEN 'end' THEN 7 
                WHEN 'cancelled' THEN 8 
                ELSE 9 END ASC")
            ->orderBy('id', 'asc');
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function deliveryPoint(): BelongsTo
    {
        return $this->belongsTo(OrderDeliveryPoint::class, 'delivery_point_id');
    }

    /**
     * Địa điểm của mốc: mốc lấy hàng là điểm lấy của đơn (không lưu riêng), còn lại là điểm giao.
     * Cần eager load `order.pickupLocation` và `deliveryPoint.location`.
     */
    public function pointLocation(): ?Location
    {
        return in_array($this->checkpoint_type, [CheckpointType::ArrivedPickup, CheckpointType::LeftPickup], true)
            ? $this->order?->pickupLocation
            : $this->deliveryPoint?->location;
    }

    public function photos(): HasMany
    {
        return $this->hasMany(TripPhoto::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(DriverShift::class, 'shift_id');
    }

    protected function shiftLabel(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->shift?->shift_type?->getLabel(),
        );
    }
}
