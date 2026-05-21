<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmptyKilometer extends Model
{
    protected $fillable = [
        'driver_id',
        'vehicle_id',
        'shift_id',
        'start_km',
        'end_km',
        'distance',
        'start_gps_lat',
        'start_gps_lng',
        'end_gps_lat',
        'end_gps_lng',
        'started_at',
        'ended_at',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'start_km' => 'decimal:1',
            'end_km' => 'decimal:1',
            'distance' => 'decimal:1',
            'start_gps_lat' => 'decimal:7',
            'start_gps_lng' => 'decimal:7',
            'end_gps_lat' => 'decimal:7',
            'end_gps_lng' => 'decimal:7',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
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

    public function shift(): BelongsTo
    {
        return $this->belongsTo(DriverShift::class, 'shift_id');
    }
}
