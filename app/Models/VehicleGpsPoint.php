<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VehicleGpsPoint extends Model
{
    public const SOURCE_PHONE = 'phone';

    public const SOURCE_EUP = 'eup';

    public const UPDATED_AT = null;

    protected $fillable = [
        'vehicle_id',
        'driver_id',
        'shift_id',
        'device_id',
        'seq',
        'recorded_at',
        'lat',
        'lng',
        'speed',
        'heading',
        'accuracy',
        'mocked',
        'source',
    ];

    protected function casts(): array
    {
        return [
            'recorded_at' => 'datetime',
            'lat' => 'float',
            'lng' => 'float',
            'speed' => 'float',
            'heading' => 'float',
            'accuracy' => 'float',
            'mocked' => 'boolean',
        ];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }
}
