<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Một chặng hành trình liên tiếp trong chuyến xe (Bắt đầu -> Đến lấy -> Rời lấy -> Đến giao -> Hoàn thành -> Kết thúc).
 * Lưu khoảng cách tính từ GPS / OSRM và cho phép điều hành điều chỉnh (distance_adjusted_km).
 */
class TripLeg extends Model
{
    use HasFactory;

    protected $fillable = [
        'trip_id',
        'driver_id',
        'leg_index',
        'from_checkpoint_id',
        'to_checkpoint_id',
        'from_name',
        'to_name',
        'from_time',
        'to_time',
        'distance_km',
        'distance_adjusted_km',
        'is_loaded',
        'source',
        'adjusted_by',
        'adjust_reason',
        'adjusted_at',
    ];

    protected function casts(): array
    {
        return [
            'leg_index' => 'integer',
            'from_time' => 'datetime',
            'to_time' => 'datetime',
            'distance_km' => 'decimal:1',
            'distance_adjusted_km' => 'decimal:1',
            'is_loaded' => 'boolean',
            'adjusted_at' => 'datetime',
        ];
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function fromCheckpoint(): BelongsTo
    {
        return $this->belongsTo(TripCheckpoint::class, 'from_checkpoint_id');
    }

    public function toCheckpoint(): BelongsTo
    {
        return $this->belongsTo(TripCheckpoint::class, 'to_checkpoint_id');
    }

    public function adjustedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'adjusted_by');
    }

    public function getEffectiveDistanceKmAttribute(): float
    {
        return (float) ($this->distance_adjusted_km ?? $this->distance_km ?? 0.0);
    }

    public function isAdjusted(): bool
    {
        return $this->distance_adjusted_km !== null;
    }
}
