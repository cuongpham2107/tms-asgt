<?php

namespace App\Models;

use App\Enums\MaintenanceJobStatus;
use App\Enums\MaintenanceJobType;
use App\Enums\Priority;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VehicleMaintenanceJob extends Model
{
    protected $fillable = [
        'vehicle_id',
        'title',
        'job_type',
        'priority',
        'description',
        'planned_date',
        'remind_before_days',
        'estimated_cost',
        'actual_cost',
        'garage',
        'technician',
        'km_at_service',
        'next_service_date',
        'notes',
        'status',
        'completed_at',
        'schedule_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'planned_date' => 'date',
            'next_service_date' => 'date',
            'completed_at' => 'datetime',
            'km_at_service' => 'decimal:1',
            'estimated_cost' => 'integer',
            'actual_cost' => 'integer',
            'job_type' => MaintenanceJobType::class,
            'priority' => Priority::class,
            'status' => MaintenanceJobStatus::class,
        ];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(VehicleMaintenanceSchedule::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
