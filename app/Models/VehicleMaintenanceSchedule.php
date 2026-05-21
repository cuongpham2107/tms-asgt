<?php

namespace App\Models;

use App\Enums\MaintenanceJobType;
use App\Enums\MaintenanceTriggerType;
use App\Enums\Priority;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VehicleMaintenanceSchedule extends Model
{
    protected $fillable = [
        'vehicle_id',
        'name',
        'job_type',
        'priority',
        'description',
        'trigger_type',
        'km_interval',
        'km_current',
        'km_next_trigger',
        'km_remind_before',
        'date_interval_days',
        'last_service_date',
        'date_next_trigger',
        'date_remind_before_days',
        'estimated_cost',
        'garage',
        'is_mandatory',
        'auto_create_job',
        'is_active',
        'alert_status',
        'last_triggered_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'km_current' => 'decimal:1',
            'km_next_trigger' => 'decimal:1',
            'last_service_date' => 'date',
            'date_next_trigger' => 'date',
            'last_triggered_at' => 'datetime',
            'estimated_cost' => 'integer',
            'is_mandatory' => 'boolean',
            'auto_create_job' => 'boolean',
            'is_active' => 'boolean',
            'job_type' => MaintenanceJobType::class,
            'priority' => Priority::class,
            'trigger_type' => MaintenanceTriggerType::class,
        ];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function maintenanceJobs(): HasMany
    {
        return $this->hasMany(VehicleMaintenanceJob::class, 'schedule_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
