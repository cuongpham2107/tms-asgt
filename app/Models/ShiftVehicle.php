<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShiftVehicle extends Model
{
    protected $fillable = [
        'shift_id',
        'vehicle_id',
        'order_id',
        'start_time',
        'end_time',
        'start_km',
        'end_km',
        'start_gps_lat',
        'start_gps_lng',
        'end_gps_lat',
        'end_gps_lng',
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
        ];
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(DriverShift::class, 'shift_id');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
