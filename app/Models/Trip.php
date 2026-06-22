<?php

namespace App\Models;

use App\Enums\TripStatus;
use Database\Factories\TripFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** @use HasFactory<TripFactory> */
class Trip extends Model
{
    use HasFactory;

    protected $fillable = [
        'trip_code',
        'vehicle_id',
        'driver_id',
        'shift_id',
        'status',
        'started_at',
        'completed_at',
        'start_km',
        'end_km',
        'total_km',
        'total_km_loaded',
        'total_km_empty',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'start_km' => 'decimal:1',
            'end_km' => 'decimal:1',
            'total_km' => 'decimal:1',
            'total_km_loaded' => 'decimal:1',
            'total_km_empty' => 'decimal:1',
            'status' => TripStatus::class,
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

    public function shift(): BelongsTo
    {
        return $this->belongsTo(DriverShift::class, 'shift_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function checkpoints(): HasMany
    {
        return $this->hasMany(TripCheckpoint::class);
    }

    public function driverSwaps(): HasMany
    {
        return $this->hasMany(DriverSwap::class);
    }

    public function getStatusLabel(): string
    {
        return $this->status?->getLabel() ?? 'Không xác định';
    }

    public function getStatusColor(): string
    {
        return $this->status?->getColor() ?? 'gray';
    }

    public function isPending(): bool
    {
        return $this->status === TripStatus::Pending;
    }

    public function isCompleted(): bool
    {
        return $this->status === TripStatus::Completed;
    }

    public function complete(?float $endKm = null, ?string $completedAt = null): void
    {
        $this->status = TripStatus::Completed;
        $this->completed_at = $completedAt ?? now();
        $this->end_km = $endKm ?? $this->end_km;
        $this->save();
    }
}
