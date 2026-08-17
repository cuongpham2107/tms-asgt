<?php

namespace App\Models;

use App\Enums\TripKmReportStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TripKmReport extends Model
{
    protected $fillable = [
        'trip_id',
        'checkpoint_id',
        'driver_id',
        'vehicle_id',
        'reported_km',
        'system_km',
        'photo_path',
        'note',
        'status',
        'admin_note',
        'resolved_by',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'reported_km' => 'decimal:1',
            'system_km' => 'decimal:1',
            'status' => TripKmReportStatus::class,
            'resolved_at' => 'datetime',
        ];
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function checkpoint(): BelongsTo
    {
        return $this->belongsTo(TripCheckpoint::class, 'checkpoint_id');
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
