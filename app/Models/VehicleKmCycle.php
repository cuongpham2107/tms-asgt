<?php

namespace App\Models;

use Database\Factories\VehicleKmCycleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Km của một xe trong một chu kỳ chốt KM over (12:00 ngày 23 → 12:00 ngày 23 tháng sau), ghi lúc job chốt chạy và không đổi về sau.
 */
class VehicleKmCycle extends Model
{
    /** @use HasFactory<VehicleKmCycleFactory> */
    use HasFactory;

    protected $fillable = [
        'vehicle_id',
        'cycle_start',
        'cycle_end',
        'start_km',
        'end_km',
        'km_run',
        'tracks_km_over',
        'km_limit',
        'over_km',
        'over_fee_per_km',
        'over_fee',
        'vehicle_owner',
    ];

    protected function casts(): array
    {
        return [
            'cycle_start' => 'datetime',
            'cycle_end' => 'datetime',
            'start_km' => 'decimal:1',
            'end_km' => 'decimal:1',
            'km_run' => 'decimal:1',
            'over_km' => 'decimal:1',
            'tracks_km_over' => 'boolean',
            'km_limit' => 'integer',
            'over_fee_per_km' => 'integer',
            'over_fee' => 'integer',
        ];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }
}
