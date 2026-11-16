<?php

namespace App\Models;

use App\Enums\OrderDeliveryPointStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderDeliveryPoint extends Model
{
    protected $fillable = [
        'order_id',
        'location_id',
        'sequence',
        'address',
        'contact_person',
        'contact_phone',
        'total_packages',
        'total_weight',
        'status',
        'is_driver_added',
        'arrived_at',
        'delivered_at',
    ];

    protected function casts(): array
    {
        return [
            'arrived_at' => 'datetime',
            'delivered_at' => 'datetime',
            'total_packages' => 'integer',
            'total_weight' => 'decimal:2',
            'status' => OrderDeliveryPointStatus::class,
            'is_driver_added' => 'boolean',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function tripCheckpoints(): HasMany
    {
        return $this->hasMany(TripCheckpoint::class);
    }
}
