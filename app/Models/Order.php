<?php

namespace App\Models;

use App\Enums\CargoType;
use App\Enums\OrderStatus;
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
        'order_type_id',
        'order_category_id',
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
        'vehicle_id',
        'driver_id',
        'status',
        'priority',
        'is_return_trip',
        'parent_order_id',
        'created_by',
        'sent_at',
        'cancelled_at',
        'cancel_reason',
        'sender_name',
        'sender_contact',
        'sender_phone',
        'receiver_name',
        'receiver_contact',
        'receiver_phone',
        'data_cargo_units',
        'data_cargo_weight',
        'freight_rate',
        'surcharges',
        'total_cost',
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
            'priority' => Priority::class,
        ];
    }

    public function orderType(): BelongsTo
    {
        return $this->belongsTo(OrderType::class);
    }

    public function orderCategory(): BelongsTo
    {
        return $this->belongsTo(OrderCategory::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function pickupLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'pickup_location_id');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
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
        return $this->hasMany(TripCheckpoint::class);
    }

    public function tripPhotos(): HasManyThrough
    {
        return $this->hasManyThrough(TripPhoto::class, TripCheckpoint::class, 'order_id', 'trip_checkpoint_id');
    }

    public function parentOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'parent_order_id');
    }

    public function returnTrips(): HasMany
    {
        return $this->hasMany(Order::class, 'parent_order_id');
    }

    public function driverSwaps(): HasMany
    {
        return $this->hasMany(DriverSwap::class);
    }
}
