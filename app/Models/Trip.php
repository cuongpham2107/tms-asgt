<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Trip extends Model
{
    protected $fillable = [
        'vehicle_id',
        'status',
        'started_at',
        'completed_at',
        'start_km',
        'end_km',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'start_km' => 'decimal:1',
            'end_km' => 'decimal:1',
        ];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function checkpoints(): HasMany
    {
        return $this->hasMany(TripCheckpoint::class);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function getStatusLabel(): string
    {
        return match ($this->status) {
            'pending' => 'Đã gửi',
            'in_progress' => 'Đang chạy',
            'completed' => 'Hoàn thành',
            default => 'Không xác định',
        };
    }

    public function getStatusColor(): string
    {
        return match ($this->status) {
            'pending' => 'warning',
            'in_progress' => 'info',
            'completed' => 'success',
            default => 'gray',
        };
    }

    public function complete(?float $endKm = null, ?string $completedAt = null): void
    {
        $this->status = 'completed';
        $this->completed_at = $completedAt ?? now();
        $this->end_km = $endKm ?? $this->end_km;
        $this->save();
    }
}
