<?php

namespace App\Models;

use App\Enums\CheckpointType;
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
        'km_reading',
        'gps_lat',
        'gps_lng',
        'voice_note',
        'driver_id',
        'shift_id',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'occurred_at' => 'datetime',
            'km_reading' => 'decimal:1',
            'gps_lat' => 'decimal:7',
            'gps_lng' => 'decimal:7',
            'checkpoint_type' => CheckpointType::class,
        ];
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
}
