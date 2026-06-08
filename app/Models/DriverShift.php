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
        'shift_type',
        'start_time',
        'end_time',
        'total_km',
        'total_km_loaded',
        'total_km_empty',
    ];

    protected function casts(): array
    {
        return [
            'start_time' => 'datetime',
            'end_time' => 'datetime',
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
        return $this->shiftVehicles()->first()?->vehicle;
    }

    public function lastVehicle(): ?Vehicle
    {
        return $this->shiftVehicles()->whereNotNull('vehicle_id')->latest('start_time')->first()?->vehicle;
    }

    public function getEffectiveStartKmAttribute(): ?float
    {
        return $this->firstSegment()?->start_km;
    }

    public function getEffectiveEndKmAttribute(): ?float
    {
        return $this->lastSegment()?->end_km;
    }

    public function getEffectiveStartGpsLatAttribute(): ?float
    {
        return $this->firstSegment()?->start_gps_lat;
    }

    public function getEffectiveStartGpsLngAttribute(): ?float
    {
        return $this->firstSegment()?->start_gps_lng;
    }

    public function getEffectiveEndGpsLatAttribute(): ?float
    {
        return $this->lastSegment()?->end_gps_lat;
    }

    public function getEffectiveEndGpsLngAttribute(): ?float
    {
        return $this->lastSegment()?->end_gps_lng;
    }

    public function getVehicleIdAttribute($value): ?int
    {
        return $this->firstVehicle()?->id ?? $value;
    }

    public function getStartKmAttribute($value): ?float
    {
        return $this->firstSegment()?->start_km ?? $value;
    }

    public function getEndKmAttribute($value): ?float
    {
        return $this->lastSegment()?->end_km ?? $value;
    }

    public function getStartGpsLatAttribute($value): ?float
    {
        return $this->firstSegment()?->start_gps_lat ?? $value;
    }

    public function getStartGpsLngAttribute($value): ?float
    {
        return $this->firstSegment()?->start_gps_lng ?? $value;
    }

    public function getEndGpsLatAttribute($value): ?float
    {
        return $this->lastSegment()?->end_gps_lat ?? $value;
    }

    public function getEndGpsLngAttribute($value): ?float
    {
        return $this->lastSegment()?->end_gps_lng ?? $value;
    }
}
