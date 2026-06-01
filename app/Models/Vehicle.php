<?php

namespace App\Models;

use App\Enums\VehicleOwnerType;
use App\Enums\VehicleStatus;
use App\Enums\VehicleType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Vehicle extends Model
{
    protected $fillable = [
        'plate_number',
        'registration_number',
        'vehicle_type',
        'owner',
        'make',
        'model_year',
        'load_capacity',
        'door_count',
        'fuel_type',
        'current_mileage',
        'gps_lat',
        'gps_lng',
        'current_driver_id',
        'is_active',
        'status',
        'off_reason',
        'type',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'model_year' => 'integer',
            'load_capacity' => 'decimal:2',
            'current_mileage' => 'decimal:2',
            'gps_lat' => 'decimal:7',
            'gps_lng' => 'decimal:7',
            'is_active' => 'boolean',
            'vehicle_type' => VehicleType::class,
            'status' => VehicleStatus::class,
            'type' => VehicleOwnerType::class,
        ];
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'current_driver_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function driverShifts(): HasMany
    {
        return $this->hasMany(DriverShift::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(VehicleDocument::class);
    }

    public function maintenanceJobs(): HasMany
    {
        return $this->hasMany(VehicleMaintenanceJob::class);
    }

    public function maintenanceSchedules(): HasMany
    {
        return $this->hasMany(VehicleMaintenanceSchedule::class);
    }

    public function latestMaintenance(): HasOne
    {
        return $this->hasOne(VehicleMaintenanceJob::class)
            ->where('status', 'completed')
            ->latest('completed_at');
    }

    public function getVehicleTypeLabel(): string
    {
        $vehicleType = $this->vehicle_type;

        return $vehicleType instanceof VehicleType
            ? $vehicleType->getLabel()
            : 'Khác';
    }

    public function getStatusLabel(): string
    {
        $status = $this->status;

        return $status instanceof VehicleStatus
            ? $status->getLabel()
            : 'Không xác định';
    }

    public function getStatusColor(): string
    {
        $status = $this->status;

        if (! $status instanceof VehicleStatus) {
            return 'gray';
        }

        $color = $status->getColor();

        return is_string($color) ? $color : 'gray';
    }

    public function getTypeLabel(): string
    {
        $type = $this->type;

        return $type instanceof VehicleOwnerType
            ? $type->getLabel()
            : 'Khác';
    }
}
