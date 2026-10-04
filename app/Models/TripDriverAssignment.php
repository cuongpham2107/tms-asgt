<?php

namespace App\Models;

use App\Enums\AssignmentEndReason;
use Database\Factories\TripDriverAssignmentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Một lượt lái: tài xế giữ chuyến trong khoảng [started_at, ended_at].
 */
class TripDriverAssignment extends Model
{
    /** @use HasFactory<TripDriverAssignmentFactory> */
    use HasFactory;

    protected $fillable = [
        'trip_id',
        'driver_id',
        'shift_id',
        'started_at',
        'ended_at',
        'end_reason',
        'note',
        'created_by',
        'km',
        'km_loaded',
        'km_empty',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'end_reason' => AssignmentEndReason::class,
            'km' => 'decimal:1',
            'km_loaded' => 'decimal:1',
            'km_empty' => 'decimal:1',
        ];
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('ended_at');
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(DriverShift::class, 'shift_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
