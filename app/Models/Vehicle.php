<?php

namespace App\Models;

use App\Enums\DriverWorkShift;
use App\Enums\VehicleOwnerType;
use App\Enums\VehicleStatus;
use App\Enums\VehicleType;
use App\Services\ShiftScheduleService;
use Database\Factories\VehicleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** @use HasFactory<VehicleFactory> */
class Vehicle extends Model
{
    use HasFactory;

    protected $attributes = [
        'current_mileage' => 10000,
    ];

    protected static function booted(): void
    {
        static::saved(function (Vehicle $vehicle) {
            if ($vehicle->wasChanged('even_driver_id')) {
                $oldDriverId = $vehicle->getOriginal('even_driver_id');
                if ($oldDriverId && $oldDriverId !== $vehicle->even_driver_id) {
                    User::where('id', $oldDriverId)->where('vehicle_id', $vehicle->id)->update(['vehicle_id' => null]);
                }
                if ($vehicle->even_driver_id) {
                    User::where('id', $vehicle->even_driver_id)->update([
                        'vehicle_id' => $vehicle->id,
                        'work_shift' => DriverWorkShift::Even->value,
                    ]);
                }
            }

            if ($vehicle->wasChanged('odd_driver_id')) {
                $oldDriverId = $vehicle->getOriginal('odd_driver_id');
                if ($oldDriverId && $oldDriverId !== $vehicle->odd_driver_id) {
                    User::where('id', $oldDriverId)->where('vehicle_id', $vehicle->id)->update(['vehicle_id' => null]);
                }
                if ($vehicle->odd_driver_id) {
                    User::where('id', $vehicle->odd_driver_id)->update([
                        'vehicle_id' => $vehicle->id,
                        'work_shift' => DriverWorkShift::Odd->value,
                    ]);
                }
            }
        });
    }

    protected $fillable = [
        'plate_number',
        'registration_number',
        'vehicle_type',
        'owner',
        'make',
        'model_year',
        'load_capacity',
        'total_weight',
        'cargo_volume',
        'box_length',
        'box_width',
        'box_height',
        'door_count',
        'fuel_type',
        'current_mileage',
        'gps_lat',
        'gps_lng',
        'gps_speed',
        'gps_direction',
        'gps_address',
        'last_gps_update',
        'current_driver_id',
        'even_driver_id',
        'odd_driver_id',
        'is_active',
        'status',
        'off_reason',
        'type',
        'notes',
        'dangerous_goods_permit_number',
        'dangerous_goods_permit_issue_date',
        'dangerous_goods_permit_expiry_date',
        'dangerous_goods_permit_image',
    ];

    protected function casts(): array
    {
        return [
            'model_year' => 'integer',
            'load_capacity' => 'decimal:2',
            'total_weight' => 'decimal:2',
            'cargo_volume' => 'decimal:2',
            'box_length' => 'integer',
            'box_width' => 'integer',
            'box_height' => 'integer',
            'current_mileage' => 'decimal:2',
            'gps_lat' => 'decimal:7',
            'gps_lng' => 'decimal:7',
            'gps_speed' => 'decimal:2',
            'gps_direction' => 'integer',
            'last_gps_update' => 'datetime',
            'is_active' => 'boolean',
            'vehicle_type' => VehicleType::class,
            'status' => VehicleStatus::class,
            'type' => VehicleOwnerType::class,
            'dangerous_goods_permit_issue_date' => 'date',
            'dangerous_goods_permit_expiry_date' => 'date',
        ];
    }

    /**
     * @return array{status: 'valid'|'expiring_soon'|'expired'|'missing', label: string, color: string, days_remaining: ?int, formatted_date: ?string}
     */
    public function getDangerousGoodsPermitStatus(): array
    {
        return User::calculateExpiryStatus($this->dangerous_goods_permit_expiry_date, 'GP Hàng nguy hiểm');
    }

    public function hasExpiredDangerousGoodsPermit(): bool
    {
        return $this->getDangerousGoodsPermitStatus()['status'] === 'expired';
    }

    public function hasExpiringSoonDangerousGoodsPermit(): bool
    {
        return $this->getDangerousGoodsPermitStatus()['status'] === 'expiring_soon';
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'current_driver_id');
    }

    public function evenDriver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'even_driver_id');
    }

    public function oddDriver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'odd_driver_id');
    }

    public function drivers(): HasMany
    {
        return $this->hasMany(User::class, 'vehicle_id');
    }

    public function getDriverForShift(DriverWorkShift $shift): ?User
    {
        if ($shift === DriverWorkShift::Even) {
            return $this->evenDriver ?? $this->drivers->firstWhere('work_shift', DriverWorkShift::Even);
        }

        return $this->oddDriver ?? $this->drivers->firstWhere('work_shift', DriverWorkShift::Odd);
    }

    public function getDriverForToday(): ?User
    {
        $shift = ShiftScheduleService::determineShift();

        return $this->getDriverForShift($shift) ?? $this->driver;
    }

    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class, 'vehicle_id');
    }

    public function driverShifts(): HasManyThrough
    {
        return $this->hasManyThrough(DriverShift::class, Trip::class, 'vehicle_id', 'id', 'id', 'shift_id');
    }

    public function orders(): HasManyThrough
    {
        return $this->hasManyThrough(Order::class, Trip::class, 'vehicle_id', 'trip_id', 'id', 'id');
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
