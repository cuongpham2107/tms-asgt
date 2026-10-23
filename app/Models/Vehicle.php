<?php

namespace App\Models;

use App\Enums\CheckpointType;
use App\Enums\DriverWorkShift;
use App\Enums\TripStatus;
use App\Enums\VehicleOwnerType;
use App\Enums\VehicleStatus;
use App\Enums\VehicleType;
use App\Services\ShiftScheduleService;
use Carbon\CarbonInterface;
use Database\Factories\VehicleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
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
        static::creating(function (Vehicle $vehicle) {
            if ($vehicle->monthly_start_km === null && $vehicle->current_mileage !== null) {
                $vehicle->monthly_start_km = $vehicle->current_mileage;
            }
        });

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
        'monthly_start_km',
        'gps_lat',
        'gps_lng',
        'gps_speed',
        'car_status',
        'idle_engine_since',
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
            'monthly_start_km' => 'decimal:2',
            'gps_lat' => 'decimal:7',
            'gps_lng' => 'decimal:7',
            'gps_speed' => 'decimal:2',
            'gps_direction' => 'integer',
            'last_gps_update' => 'datetime',
            'idle_engine_since' => 'datetime',
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

    public function isRent(): bool
    {
        return $this->type === VehicleOwnerType::Rent
            || $this->type === VehicleOwnerType::Rent->value
            || $this->type === 'rent';
    }

    public function isCompany(): bool
    {
        return $this->type === VehicleOwnerType::Company
            || $this->type === VehicleOwnerType::Company->value
            || $this->type === 'company';
    }

    public function getActiveTripAttribute(): ?Trip
    {
        if ($this->relationLoaded('trips')) {
            $trips = $this->trips;

            // 0. ƯU TIÊN CAO NHẤT: Bất kỳ chuyến nào của xe đang bị Tạm dừng (Báo sai Km chưa xử lý)
            $pausedTrip = $trips
                ->filter(fn (Trip $t) => $t->status !== TripStatus::Cancelled && $t->isPaused())
                ->sortByDesc('id')
                ->first();

            if ($pausedTrip !== null) {
                return $pausedTrip;
            }

            // 1. Ưu tiên chuyến đang chạy thực tế (từ đã bắt đầu đến hoàn thành nhưng chưa kết thúc/end)
            $inProgressTrip = $trips
                ->filter(function (Trip $t): bool {
                    if ($t->status === TripStatus::Cancelled || $t->status === TripStatus::Pending) {
                        return false;
                    }

                    if ($t->hasEnded()) {
                        return false;
                    }

                    if (in_array($t->status, [
                        TripStatus::Started,
                        TripStatus::ArrivedPickup,
                        TripStatus::Delivering,
                        TripStatus::ArrivedDelivery,
                        TripStatus::Delivered,
                        TripStatus::DriverSwap,
                        TripStatus::ReturnTrip,
                    ], true)) {
                        return true;
                    }

                    if ($t->status === TripStatus::Completed) {
                        return ($t->completed_at && $t->completed_at->isToday())
                            || ($t->started_at && $t->started_at->isToday())
                            || ($t->created_at && $t->created_at->isToday());
                    }

                    return false;
                })
                ->sortByDesc('id')
                ->first();

            if ($inProgressTrip !== null) {
                return $inProgressTrip;
            }

            // 2. Nếu không có chuyến đang chạy, lấy chuyến tiếp theo đang chờ chạy (Pending)
            return $trips
                ->filter(fn (Trip $t) => $t->status === TripStatus::Pending)
                ->sortBy('id')
                ->first();
        }

        // Khi relation chưa được eager-load:
        // 0. Ưu tiên chuyến đang bị Tạm dừng (Báo sai Km)
        $pausedTrip = $this->trips()
            ->where('status', '!=', TripStatus::Cancelled->value)
            ->where(function (Builder $q) {
                $q->where('is_paused', true)
                    ->orWhereHas('kmReports', fn ($kr) => $kr->where('status', 'pending'));
            })
            ->latest('id')
            ->first();

        if ($pausedTrip !== null) {
            return $pausedTrip;
        }

        // 1. Tìm chuyến đang chạy thực tế
        $inProgressTrip = $this->trips()
            ->whereNotIn('status', [TripStatus::Cancelled->value, TripStatus::Pending->value])
            ->whereDoesntHave('checkpoints', fn (Builder $q) => $q->where('checkpoint_type', CheckpointType::End->value))
            ->whereNull('end_km')
            ->where(function (Builder $q) {
                $q->whereIn('status', [
                    TripStatus::Started->value,
                    TripStatus::ArrivedPickup->value,
                    TripStatus::Delivering->value,
                    TripStatus::ArrivedDelivery->value,
                    TripStatus::Delivered->value,
                    TripStatus::DriverSwap->value,
                    TripStatus::ReturnTrip->value,
                ])->orWhere(function (Builder $sub) {
                    $sub->where('status', TripStatus::Completed->value)
                        ->where(function (Builder $dateQ) {
                            $dateQ->whereDate('completed_at', today())
                                ->orWhereDate('started_at', today())
                                ->orWhereDate('created_at', today());
                        });
                });
            })
            ->latest('id')
            ->first();

        if ($inProgressTrip !== null) {
            return $inProgressTrip;
        }

        // 2. Chuyến chờ chạy kế tiếp
        return $this->trips()
            ->where('status', TripStatus::Pending->value)
            ->oldest('id')
            ->first();
    }

    public function gpsLogs(): HasMany
    {
        return $this->hasMany(VehicleGpsLog::class);
    }

    /**
     * Lộ trình EUP của xe trong khoảng thời gian, theo thứ tự thời gian.
     *
     * @return Collection<int, VehicleGpsLog>
     */
    public function trackBetween(CarbonInterface $from, CarbonInterface $to): Collection
    {
        return $this->gpsLogs()
            ->whereBetween('recorded_at', [$from, $to])
            ->orderBy('recorded_at')
            ->get();
    }
}
