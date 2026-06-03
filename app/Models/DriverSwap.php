<?php

namespace App\Models;

use App\Enums\DriverSwapReason;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DriverSwap extends Model
{
    protected $fillable = [
        'order_id',
        'from_driver_id',
        'to_driver_id',
        'from_shift_id',
        'to_shift_id',
        'handover_km',
        'reason',
        'note',
        'created_by',
    ];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'handover_km' => 'decimal:1',
            'created_at' => 'datetime',
            'reason' => DriverSwapReason::class,
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function fromDriver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_driver_id');
    }

    public function toDriver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_driver_id');
    }

    public function fromShift(): BelongsTo
    {
        return $this->belongsTo(DriverShift::class, 'from_shift_id');
    }

    public function toShift(): BelongsTo
    {
        return $this->belongsTo(DriverShift::class, 'to_shift_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
