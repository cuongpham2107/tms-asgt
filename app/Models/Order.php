<?php

namespace App\Models;

use App\Enums\CargoType;
use App\Enums\CheckpointType;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\Priority;
use App\Enums\TripStatus;
use Illuminate\Database\Eloquent\Collection;
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
        return $this->hasMany(OrderDeliveryPoint::class)
            ->orderBy('sequence')
            ->orderBy('id');
    }

    /**
     * Chữ ký danh sách điểm giao để phát hiện thay đổi do điều hành sửa đơn từ web.
     */
    public function deliveryPointsSignature(): string
    {
        return $this->deliveryPoints()
            ->reorder('id')
            ->get([
                'id',
                'location_id',
                'sequence',
                'address',
                'contact_person',
                'contact_phone',
                'total_packages',
                'total_weight',
                'status',
            ])
            ->toJson();
    }

    public function tripCheckpoints(): HasMany
    {
        return $this->hasMany(TripCheckpoint::class)->chronological();
    }

    /**
     * Danh sách điểm giao theo lộ trình thực tế: các điểm đã có mốc hành trình đứng trước
     * theo thứ tự thời gian đến/hoàn thành thực tế, tiếp theo là các điểm chưa giao theo `sequence`.
     *
     * @return Collection<int, OrderDeliveryPoint>
     */
    public function orderedDeliveryPoints(): Collection
    {
        $deliveryPoints = $this->relationLoaded('deliveryPoints')
            ? $this->deliveryPoints->sortBy(fn (OrderDeliveryPoint $dp): array => [(int) ($dp->sequence ?? 0), (int) $dp->id])->values()
            : $this->deliveryPoints()->get();

        if ($deliveryPoints->count() <= 1) {
            return $deliveryPoints;
        }

        $checkpoints = $this->relationLoaded('tripCheckpoints')
            ? $this->tripCheckpoints
            : $this->tripCheckpoints()->get();

        $deliveryTypes = [
            CheckpointType::ArrivedDelivery,
            CheckpointType::Completed,
            CheckpointType::End,
        ];

        $typeRank = [
            CheckpointType::ArrivedDelivery->value => 1,
            CheckpointType::Completed->value => 2,
            CheckpointType::End->value => 3,
        ];

        $visitedIds = $checkpoints
            ->filter(fn (TripCheckpoint $cp): bool => $cp->delivery_point_id !== null
                && in_array($cp->checkpoint_type, $deliveryTypes, true))
            ->sortBy(fn (TripCheckpoint $cp): array => [
                $cp->occurred_at?->getTimestamp() ?? PHP_INT_MAX,
                $typeRank[$cp->checkpoint_type?->value ?? ''] ?? 9,
                (int) $cp->id,
            ])
            ->pluck('delivery_point_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        if ($visitedIds->isEmpty()) {
            return $deliveryPoints;
        }

        $pointsById = $deliveryPoints->keyBy('id');
        $ordered = new Collection;

        foreach ($visitedIds as $dpId) {
            if ($pointsById->has($dpId)) {
                $ordered->push($pointsById->get($dpId));
            }
        }

        foreach ($deliveryPoints as $dp) {
            if (! $visitedIds->contains((int) $dp->id)) {
                $ordered->push($dp);
            }
        }

        return $ordered->values();
    }

    /**
     * Đồng bộ lại `sequence` của các điểm giao trong DB theo thứ tự mốc hành trình thực tế.
     */
    public function syncDeliveryPointSequencesFromCheckpoints(): bool
    {
        $this->load(['deliveryPoints', 'tripCheckpoints']);

        $ordered = $this->orderedDeliveryPoints();
        if ($ordered->count() <= 1) {
            return false;
        }

        $changed = false;

        foreach ($ordered->values() as $index => $dp) {
            $targetSequence = $index + 1;
            if ((int) $dp->sequence !== $targetSequence) {
                $dp->update(['sequence' => $targetSequence]);
                $changed = true;
            }
        }

        if ($changed) {
            $this->unsetRelation('deliveryPoints');
        }

        return $changed;
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
