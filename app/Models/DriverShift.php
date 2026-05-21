<?php

namespace App\Models;

use App\Enums\ShiftType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DriverShift extends Model
{
    protected $fillable = [
        'driver_id',
        'vehicle_id',
        'shift_type',
        'start_time',
        'start_km',
        'start_gps_lat',
        'start_gps_lng',
        'end_time',
        'end_km',
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

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function tripCheckpoints(): HasMany
    {
        return $this->hasMany(TripCheckpoint::class, 'shift_id');
    }

    public function driverSwaps(): HasMany
    {
        return $this->hasMany(DriverSwap::class, 'from_shift_id');
    }
}
