<?php

namespace App\Models;

use App\Enums\CargoType;
use App\Enums\CheckpointType;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\Priority;
use App\Enums\TripStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Relaticle\ActivityLog\Concerns\InteractsWithTimeline;
use Relaticle\ActivityLog\Contracts\HasTimeline;
use Relaticle\ActivityLog\Timeline\TimelineBuilder;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Order extends Model implements HasTimeline
{
    use InteractsWithTimeline;
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function timeline(): TimelineBuilder
    {
        return TimelineBuilder::make($this)->fromActivityLog();
    }

    protected $fillable = [
        'order_code',
        'type',
        'area_id',
        'customer_id',
        'cargo_name',
        'cargo_type',
        'total_packages',
        'total_weight',
        'chargeable_weight',
        'rate_type',
        'pickup_location_id',
        'pickup_address',
        'pickup_contact',
        'pickup_phone',
        'planned_loading_at',
        'trip_id',
        'trip_sequence',
        'status',
        'priority',
        'is_return_trip',
        'parent_order_id',
        'created_by',
        'sent_at',
        'cancelled_at',
        'cancel_reason',
        'notes',
        'loaded_km',
        'bill_number',
        'pallet_count',
    ];

    protected function casts(): array
    {
        return [
            'planned_loading_at' => 'datetime',
            'sent_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'is_return_trip' => 'boolean',
            'chargeable_weight' => 'decimal:2',
            'cargo_type' => CargoType::class,
            'status' => OrderStatus::class,
            'type' => OrderType::class,
            'priority' => Priority::class,
            'loaded_km' => 'decimal:1',
        ];
    }

    public function isExternal(): bool
    {
        return $this->type === OrderType::External;
    }

    public function isHhhk(): bool
    {
        return $this->type === OrderType::Hhhk;
    }

    /**
     * Đơn cho phép tài xế tự chọn thêm điểm trả hàng trên App khi đơn chưa bấm Kết thúc.
     * - Khi `app.allow_driver_append_predefined_delivery_points = false` (mặc định):
     *   chỉ đơn chưa có điểm trả nào (0 điểm) mới được chọn 1 điểm trả; khi đã có >= 1 điểm trả thì không cho chọn thêm.
     * - Khi `app.allow_driver_append_predefined_delivery_points = true`:
     *   cho phép chọn thêm điểm trả tiếp theo sau khi đã giao xong các điểm hiện có.
     */
    public function canDriverAddDeliveryPoint(): bool
    {
        if (in_array($this->status, [OrderStatus::Draft, OrderStatus::Cancelled], true)) {
            return false;
        }

        $trip = $this->trip;
        if ($trip !== null && in_array($trip->status, [TripStatus::Completed, TripStatus::Cancelled, TripStatus::DriverSwap], true)) {
            return false;
        }

        if (! config('app.allow_driver_append_predefined_delivery_points', false)) {
            $hasAnyDeliveryPoint = $this->relationLoaded('deliveryPoints')
                ? $this->deliveryPoints->isNotEmpty()
                : $this->deliveryPoints()->exists();

            if ($hasAnyDeliveryPoint) {
                return false;
            }
        }

        $hasEndCheckpoint = $this->relationLoaded('tripCheckpoints')
            ? $this->tripCheckpoints->contains(fn (TripCheckpoint $cp): bool => $cp->checkpoint_type === CheckpointType::End)
            : $this->tripCheckpoints()->where('checkpoint_type', CheckpointType::End->value)->exists();

        return ! $hasEndCheckpoint;
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function pickupLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'pickup_location_id');
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function deliveryPoints(): HasMany
    {
        return $this->hasMany(OrderDeliveryPoint::class);
    }

    public function tripCheckpoints(): HasMany
    {
        return $this->hasMany(TripCheckpoint::class)->orderBy('id');
    }

    public function tripPhotos(): HasManyThrough
    {
        return $this->hasManyThrough(TripPhoto::class, TripCheckpoint::class, null, 'trip_checkpoint_id');
    }

    public function parentOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'parent_order_id');
    }

    public function returnTrips(): HasMany
    {
        return $this->hasMany(Order::class, 'parent_order_id');
    }

    public function charge(): HasOne
    {
        return $this->hasOne(OrderCharge::class);
    }

    public function getMapCoordsAttribute(): array
    {
        $latestCheckpoint = $this->tripCheckpoints
            ->sortByDesc('occurred_at')
            ->first(fn ($c) => $c->gps_lat !== null && $c->gps_lng !== null);

        if ($latestCheckpoint !== null) {
            return [
                'lat' => (float) $latestCheckpoint->gps_lat,
                'lng' => (float) $latestCheckpoint->gps_lng,
            ];
        }

        $lat = $this->pickupLocation?->lat ?? 10.8231;
        $lng = $this->pickupLocation?->lng ?? 106.6297;

        return ['lat' => (float) $lat, 'lng' => (float) $lng];
    }
}
