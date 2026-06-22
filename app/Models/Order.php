<?php

namespace App\Models;

use App\Enums\CargoType;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\Priority;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;

class Order extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'order_code',
        'type',
        'area_id',
        'customer_id',
        'cargo_name',
        'cargo_type',
        'total_packages',
        'total_weight',
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
    ];

    protected function casts(): array
    {
        return [
            'planned_loading_at' => 'datetime',
            'sent_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'is_return_trip' => 'boolean',
            'cargo_type' => CargoType::class,
            'status' => OrderStatus::class,
            'type' => OrderType::class,
            'priority' => Priority::class,
        ];
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
