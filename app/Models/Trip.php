<?php

namespace App\Models;

use App\Enums\CheckpointType;
use App\Enums\OrderStatus;
use App\Enums\TripStatus;
use App\Services\Trip\TripCompletionService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/** @use HasFactory<TripFactory> */
class Trip extends Model
{
    use HasFactory;
    use LogsActivity;

    /**
     * Chuyến của tài xế còn chưa xong — chặn Rời xe / Kết thúc ca (kể cả đã giao hàng nhưng chưa bấm Kết thúc đơn hàng).
     *
     * @param  Builder<Trip>  $query
     */
    public function scopeUnfinishedForDriver(Builder $query, int $driverId): void
    {
        $running = TripStatus::unfinishedStatuses();

        $query->where('driver_id', $driverId)
            ->where(function (Builder $query) use ($running): void {
                $query->whereHas('orders', fn (Builder $orders) => $orders->whereIn('status', [
                    OrderStatus::Sent->value,
                    OrderStatus::InTransit->value,
                    OrderStatus::Completed->value,
                ]))->orWhere(fn (Builder $empty) => $empty->where('is_empty_run', true)->whereIn('status', $running));
            })
            ->whereIn('status', $running);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    // protected static function booted(): void
    // {
    //     static::creating(function (Trip $trip) {
    //         if ($trip->shift_id === null && $trip->driver_id !== null) {
    //             $activeShift = DriverShift::where('driver_id', $trip->driver_id)
    //                 ->whereNull('end_time')
    //                 ->first();

    //             if ($activeShift !== null) {
    //                 $trip->shift_id = $activeShift->id;
    //             }
    //         }
    //     });
    // }

    protected $fillable = [
        'trip_code',
        'vehicle_id',
        'driver_id',
        'external_driver_name',
        'shift_id',
        'created_by',
        'status',
        'started_at',
        'completed_at',
        'cancelled_at',
        'start_km',
        'end_km',
        'total_km',
        'total_km_loaded',
        'total_km_empty',
        'is_empty_run',
        'note',
        'start_location_id',
        'end_location_id',
        'is_paused',
        'paused_reason',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'start_km' => 'decimal:1',
            'end_km' => 'decimal:1',
            'total_km' => 'decimal:1',
            'total_km_loaded' => 'decimal:1',
            'total_km_empty' => 'decimal:1',
            'is_empty_run' => 'boolean',
            'is_paused' => 'boolean',
            'status' => TripStatus::class,
        ];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
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

    public function startLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'start_location_id');
    }

    public function endLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'end_location_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function checkpoints(): HasMany
    {
        return $this->hasMany(TripCheckpoint::class)->chronological();
    }

    /**
     * Chữ ký mốc hành trình để phát hiện thay đổi do điều hành sửa chuyến từ web.
     */
    public function checkpointsSignature(): string
    {
        return $this->checkpoints()
            ->reorder()
            ->orderBy('id')
            ->get([
                'id',
                'checkpoint_type',
                'order_id',
                'delivery_point_id',
                'driver_id',
                'km_reading',
                'occurred_at',
            ])
            ->toJson();
    }

    public function photos(): HasManyThrough
    {
        return $this->hasManyThrough(TripPhoto::class, TripCheckpoint::class, 'trip_id', 'trip_checkpoint_id');
    }

    public function tripPhotos(): HasManyThrough
    {
        return $this->photos();
    }

    public function driverSwaps(): HasMany
    {
        return $this->hasMany(DriverSwap::class);
    }

    public function driverSwapCheckpoints(): HasMany
    {
        return $this->hasMany(TripCheckpoint::class)->where('checkpoint_type', 'driver_swap');
    }

    public function kmReports(): HasMany
    {
        return $this->hasMany(TripKmReport::class);
    }

    public function latestPendingKmReport(): HasOne
    {
        return $this->hasOne(TripKmReport::class)->where('status', 'pending')->latestOfMany();
    }

    public function getSentAtAttribute(): ?Carbon
    {
        if (isset($this->attributes['first_sent_at']) && $this->attributes['first_sent_at']) {
            return Carbon::parse($this->attributes['first_sent_at']);
        }

        if ($this->relationLoaded('orders') && $this->orders->isNotEmpty()) {
            $minSent = $this->orders->whereNotNull('sent_at')->min('sent_at');
            if ($minSent) {
                return $minSent instanceof Carbon ? $minSent : Carbon::parse($minSent);
            }
        }

        return $this->created_at;
    }

    public function isPaused(): bool
    {
        return (bool) $this->is_paused || $this->latestPendingKmReport !== null;
    }

    public function getStatusLabel(): string
    {
        if ($this->isPaused()) {
            return 'Tạm dừng (Sửa Km)';
        }

        if ($this->status === TripStatus::Pending) {
            return 'Chờ chạy';
        }

        return $this->status?->getLabel() ?? 'Không xác định';
    }

    public function getStatusColor(): string
    {
        if ($this->isPaused()) {
            return 'warning';
        }

        if ($this->status === TripStatus::Pending) {
            return 'gray';
        }

        return $this->status?->getColor() ?? 'gray';
    }

    public function isPending(): bool
    {
        return $this->status === TripStatus::Pending;
    }

    public function isCompleted(): bool
    {
        return $this->status === TripStatus::Completed;
    }

    public function hasEndCheckpoint(): bool
    {
        if ($this->relationLoaded('checkpoints')) {
            return $this->checkpoints->contains(function ($cp): bool {
                $type = $cp->checkpoint_type;

                return $type === CheckpointType::End
                    || $type === CheckpointType::End->value
                    || $type === 'end';
            });
        }

        return $this->checkpoints()
            ->where('checkpoint_type', CheckpointType::End->value)
            ->exists();
    }

    public function hasEnded(): bool
    {
        return $this->hasEndCheckpoint()
            || ($this->status === TripStatus::Completed && $this->end_km !== null);
    }

    /**
     * Tìm chuyến có hàng đang chạy của một tài xế (bao gồm cả chuyến đã giao hàng nhưng chưa bấm Kết thúc đơn hàng).
     * Chuyến có hàng đang chạy: is_empty_run = false, status thuộc nhóm đang chạy (không tính Pending).
     */
    public static function getActiveCargoTripForDriver(int $driverId, ?int $excludeTripId = null): ?self
    {
        return self::query()
            ->with(['vehicle', 'driver'])
            ->where('driver_id', $driverId)
            ->where('is_empty_run', false)
            ->when($excludeTripId, fn ($q) => $q->where('id', '!=', $excludeTripId))
            ->whereIn('status', [
                TripStatus::Started,
                TripStatus::ArrivedPickup,
                TripStatus::Delivering,
                TripStatus::ArrivedDelivery,
                TripStatus::Delivered,
            ])
            ->first();
    }

    /**
     * Tìm chuyến có hàng đang chạy trên cùng một xe (kể cả khi tài xế khác để treo chưa bấm Kết thúc đơn hàng).
     */
    public static function getActiveCargoTripForVehicle(int $vehicleId, ?int $excludeTripId = null): ?self
    {
        return self::query()
            ->with(['vehicle', 'driver'])
            ->where('vehicle_id', $vehicleId)
            ->where('is_empty_run', false)
            ->when($excludeTripId, fn ($q) => $q->where('id', '!=', $excludeTripId))
            ->whereIn('status', [
                TripStatus::Started,
                TripStatus::ArrivedPickup,
                TripStatus::Delivering,
                TripStatus::ArrivedDelivery,
                TripStatus::Delivered,
            ])
            ->first();
    }

    /**
     * Chuyến đã đảo lái (tài xế cũ đã bàn giao) nhưng còn đơn chưa giao xong và chưa ai nhận: hàng vẫn trên xe,
     * nên xe chưa rảnh dù tài xế cũ đã rảnh.
     */
    public static function getPendingHandoverTripForVehicle(int $vehicleId, ?int $excludeTripId = null): ?self
    {
        return self::query()
            ->with(['vehicle', 'driver'])
            ->where('vehicle_id', $vehicleId)
            ->when($excludeTripId, fn ($q) => $q->where('id', '!=', $excludeTripId))
            ->where('status', TripStatus::DriverSwap)
            ->whereHas('orders', fn ($orders) => $orders->whereNotIn('status', [OrderStatus::Completed->value, OrderStatus::Cancelled->value]))
            ->first();
    }

    public static function generateTripCode(): string
    {
        $nextId = (self::max('id') ?? 0) + 1;

        return 'CD-'.now()->format('Y-m-d').'-'.$nextId;
    }

    /**
     * @param  array<string, mixed>  $checkpointAttributes
     */
    public function complete(?float $endKm = null, ?string $completedAt = null, array $checkpointAttributes = []): void
    {
        app(TripCompletionService::class)->complete($this, $endKm, $completedAt, $checkpointAttributes);
    }

    /**
     * Lộ trình EUP thật của xe trong thời gian chuyến (bắt đầu → kết thúc, hoặc tới hiện tại nếu đang chạy).
     * Rút gọn đều còn tối đa $maxPoints điểm, luôn giữ điểm đầu và cuối.
     * $since: chỉ lấy từ mốc này trở đi (chuyến treo trạng thái nhiều ngày thì không vẽ cả tuần GPS).
     *
     * @return array<int, array{0: float, 1: float}>
     */
    public function gpsTrack(int $maxPoints = 500, ?Carbon $since = null): array
    {
        if ($this->vehicle_id === null || $this->started_at === null) {
            return [];
        }

        // Chỉ lấy 2 cột dạng thô (không dựng model) vì chuyến dài có thể có hàng nghìn điểm
        $points = VehicleGpsLog::query()
            ->where('vehicle_id', $this->vehicle_id)
            ->whereBetween('recorded_at', [$since?->max($this->started_at) ?? $this->started_at, $this->completed_at ?? $this->cancelled_at ?? now()])
            ->orderBy('recorded_at')
            ->toBase()
            ->get(['lat', 'lng'])
            ->map(fn (object $row): array => [(float) $row->lat, (float) $row->lng]);

        if ($points->count() <= $maxPoints) {
            return $points->all();
        }

        $step = (int) ceil($points->count() / ($maxPoints - 1));

        $sampled = $points->nth($step);

        return ($points->count() - 1) % $step === 0
            ? $sampled->values()->all()
            : $sampled->push($points->last())->values()->all();
    }

    /**
     * Tên tài xế hiển thị cho chuyến: ưu tiên tài xế gán trên chuyến,
     * tiếp theo là snapshot tên lái xe ngoài lưu trên chuyến, sau cùng mới dự phòng từ xe thuê.
     */
    public function resolveDriverName(): ?string
    {
        if (filled($this->driver?->name)) {
            return trim((string) $this->driver->name);
        }

        if (filled($this->external_driver_name)) {
            return trim((string) $this->external_driver_name);
        }

        $vehicle = $this->vehicle;

        if ($vehicle?->isRent() && filled($vehicle->external_driver_name)) {
            return trim((string) $vehicle->external_driver_name);
        }

        return null;
    }
}
