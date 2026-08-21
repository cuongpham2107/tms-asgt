<?php

namespace App\Models;

use App\Enums\LocationType;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
        'area_id',
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

    public function customers(): BelongsToMany
    {
        return $this->belongsToMany(Customer::class, 'customer_location')
            ->withPivot('loc_type')
            ->withTimestamps();
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class, 'area_id');
    }

    protected static function booted(): void
    {
        static::saving(function (Location $location) {
            if (! empty($location->area_id) && ! is_numeric($location->area_id)) {
                $area = Area::query()->where('code', $location->area_id)->first();
                $location->area_id = $area?->id;
            }
        });

        static::created(function (Location $location) {
            $location->loadMissing('area');

            if (! $location->area) {
                return;
            }

            $siblingAreas = Area::query()
                ->where('code', $location->area->code)
                ->where('id', '!=', $location->area_id)
                ->get();

            foreach ($siblingAreas as $siblingArea) {
                static::withoutEvents(function () use ($location, $siblingArea) {
                    Location::firstOrCreate(
                        [
                            'code' => $location->code,
                            'area_id' => $siblingArea->id,
                        ],
                        [
                            'name' => $location->name,
                            'address' => $location->address,
                            'lat' => $location->lat,
                            'lng' => $location->lng,
                            'loc_type' => $location->loc_type,
                            'is_active' => $location->is_active,
                        ]
                    );
                });
            }
        });

        static::updated(function (Location $location) {
            $location->loadMissing('area');

            if (! $location->area) {
                return;
            }

            $siblingAreaIds = Area::query()
                ->where('code', $location->area->code)
                ->where('id', '!=', $location->area_id)
                ->pluck('id');

            if ($siblingAreaIds->isNotEmpty()) {
                $originalCode = $location->getOriginal('code') ?? $location->code;

                static::withoutEvents(function () use ($location, $siblingAreaIds, $originalCode) {
                    Location::query()
                        ->whereIn('area_id', $siblingAreaIds)
                        ->where('code', $originalCode)
                        ->update([
                            'code' => $location->code,
                            'name' => $location->name,
                            'address' => $location->address,
                            'lat' => $location->lat,
                            'lng' => $location->lng,
                            'loc_type' => $location->loc_type?->value ?? $location->loc_type,
                            'is_active' => $location->is_active,
                        ]);
                });
            }
        });
    }
}
