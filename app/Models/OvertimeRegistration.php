<?php

namespace App\Models;

use App\Enums\OvertimeStatus;
use App\Enums\ShiftType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OvertimeRegistration extends Model
{
    protected $fillable = [
        'driver_id',
        'shift_type',
        'overtime_date',
        'status',
        'notes',
        'confirmed_at',
        'confirmed_by',
    ];

    protected function casts(): array
    {
        return [
            'overtime_date' => 'date',
            'shift_type' => ShiftType::class,
            'status' => OvertimeStatus::class,
            'confirmed_at' => 'datetime',
        ];
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }
}
