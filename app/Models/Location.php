<?php

namespace App\Models;

use App\Enums\LocationType;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Location extends Model
{
    protected $fillable = [
        'code',
        'name',
        'address',
        'lat',
        'lng',
        'coordinates',
        'loc_type',
        'is_active',
    ];

    protected $appends = ['coordinates'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'lat' => 'decimal:7',
            'lng' => 'decimal:7',
            'loc_type' => LocationType::class,
        ];
    }

    protected function coordinates(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->lat !== null && $this->lng !== null
                ? ['lat' => (float) $this->lat, 'lng' => (float) $this->lng]
                : null,
            set: function ($value) {
                // Accept numeric arrays [lat, lng], associative arrays ['lat' => ..., 'lng' => ...]
                // and objects with ->lat and ->lng (Livewire map field sends {lat, lng}).
                if (is_array($value)) {
                    if (array_key_exists('lat', $value) && array_key_exists('lng', $value)) {
                        return [
                            'lat' => (float) $value['lat'],
                            'lng' => (float) $value['lng'],
                        ];
                    }

                    if (count($value) === 2) {
                        return [
                            'lat' => (float) $value[0],
                            'lng' => (float) $value[1],
                        ];
                    }
                }

                if (is_object($value)) {
                    if (isset($value->lat) && isset($value->lng)) {
                        return [
                            'lat' => (float) $value->lat,
                            'lng' => (float) $value->lng,
                        ];
                    }
                }

                return ['lat' => null, 'lng' => null];
            },
        );
    }

    public function pickupOrders(): HasMany
    {
        return $this->hasMany(Order::class, 'pickup_location_id');
    }

    public function deliveryPoints(): HasMany
    {
        return $this->hasMany(OrderDeliveryPoint::class);
    }
}
