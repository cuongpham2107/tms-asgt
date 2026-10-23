<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Một điểm lộ trình xe do EUP ghi nhận (lưu trong lúc đồng bộ GPS).
 */
class VehicleGpsLog extends Model
{
    use MassPrunable;

    public const UPDATED_AT = null;

    protected $fillable = [
        'vehicle_id',
        'lat',
        'lng',
        'speed',
        'direction',
        'car_status',
        'address',
        'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'lat' => 'decimal:7',
            'lng' => 'decimal:7',
            'speed' => 'decimal:1',
            'direction' => 'integer',
            'car_status' => 'integer',
            'recorded_at' => 'datetime',
        ];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function prunable(): Builder
    {
        return static::where('recorded_at', '<', now()->subDays((int) config('services.eup.gps_log_retention_days')));
    }
}
