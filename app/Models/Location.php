<?php

namespace App\Models;

use App\Enums\LocationType;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

class Location extends Model
{
    protected $fillable = [
        'code',
        'name',
        'address',
        'old_address',
        'lat',
        'lng',
        'geofence_radius_m',
        'geofence_type',
        'geofence_bounds',
        'coordinates',
        'loc_type',
        'is_active',
        'area_id',
        'billing_zone_id',
    ];

    protected $appends = ['coordinates'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'lat' => 'decimal:7',
            'lng' => 'decimal:7',
            'geofence_radius_m' => 'integer',
            'geofence_bounds' => 'array',
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

    public function areas(): BelongsToMany
    {
        return $this->belongsToMany(Area::class, 'area_location')->withTimestamps();
    }

    public function billingZone(): BelongsTo
    {
        return $this->belongsTo(BillingZone::class);
    }

    /**
     * @return array{south: float, west: float, north: float, east: float}|null
     */
    public function getNormalizedBounds(): ?array
    {
        $bounds = $this->geofence_bounds;
        if (is_string($bounds)) {
            $decoded = json_decode($bounds, true);
            $bounds = is_array($decoded) ? $decoded : null;
        }

        if (! is_array($bounds)) {
            return null;
        }

        if (
            ! isset($bounds['south'], $bounds['west'], $bounds['north'], $bounds['east'])
            || ! is_numeric($bounds['south'])
            || ! is_numeric($bounds['west'])
            || ! is_numeric($bounds['north'])
            || ! is_numeric($bounds['east'])
        ) {
            return null;
        }

        $s = (float) $bounds['south'];
        $n = (float) $bounds['north'];
        $w = (float) $bounds['west'];
        $e = (float) $bounds['east'];

        $south = min($s, $n);
        $north = max($s, $n);
        $west = min($w, $e);
        $east = max($w, $e);

        if ($north - $south < 0.0001 || $east - $west < 0.0001) {
            return null;
        }

        return [
            'south' => $south,
            'west' => $west,
            'north' => $north,
            'east' => $east,
        ];
    }

    public function hasRectangleGeofence(): bool
    {
        return in_array($this->geofence_type, ['rectangle', 'square'], true)
            && $this->getNormalizedBounds() !== null;
    }

    public function isPointInsideBounds(float $lat, float $lng): bool
    {
        $bounds = $this->getNormalizedBounds();
        if ($bounds === null) {
            return false;
        }

        return $lat >= $bounds['south']
            && $lat <= $bounds['north']
            && $lng >= $bounds['west']
            && $lng <= $bounds['east'];
    }

    /**
     * Khoảng cách ngắn nhất (mét) từ điểm (lat, lng) tới vùng chữ nhật.
     * Nếu điểm nằm bên trong hình chữ nhật thì trả về 0.
     */
    public function distanceToBoundsMeters(float $lat, float $lng): ?int
    {
        $bounds = $this->getNormalizedBounds();
        if ($bounds === null) {
            return null;
        }

        if ($this->isPointInsideBounds($lat, $lng)) {
            return 0;
        }

        $clampedLat = max($bounds['south'], min($bounds['north'], $lat));
        $clampedLng = max($bounds['west'], min($bounds['east'], $lng));

        return self::haversineMeters($lat, $lng, $clampedLat, $clampedLng);
    }

    /**
     * @return array{width_m: int, height_m: int}|null
     */
    public function getBoundsDimensionsMeters(): ?array
    {
        $bounds = $this->getNormalizedBounds();
        if ($bounds === null) {
            return null;
        }

        $midLat = ($bounds['south'] + $bounds['north']) / 2;
        $midLng = ($bounds['west'] + $bounds['east']) / 2;

        $widthM = self::haversineMeters($midLat, $bounds['west'], $midLat, $bounds['east']);
        $heightM = self::haversineMeters($bounds['south'], $midLng, $bounds['north'], $midLng);

        return [
            'width_m' => $widthM,
            'height_m' => $heightM,
        ];
    }

    public static function haversineMeters(float $lat1, float $lng1, float $lat2, float $lng2): int
    {
        $earthRadiusM = 6371000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return (int) round(2 * $earthRadiusM * asin(min(1.0, sqrt($a))));
    }

    protected static function booted(): void
    {
        static::saving(function (Location $location) {
            if (! empty($location->code)) {
                $location->code = mb_strtoupper(trim((string) $location->code));
            }
            if (! empty($location->area_id) && ! is_numeric($location->area_id)) {
                $area = Area::query()->where('code', $location->area_id)->first();
                $location->area_id = $area?->id;
            }
        });

        static::saved(function (Location $location) {
            Cache::forget('location-options-all');
            if ($location->area_id) {
                Cache::forget('location-options-'.$location->area_id);
            }
            foreach ($location->areas()->pluck('areas.id') as $areaId) {
                Cache::forget('location-options-'.$areaId);
            }
            Cache::forget('active-locations-with-coords-v2');
        });

        static::deleted(function (Location $location) {
            Cache::forget('location-options-all');
            if ($location->area_id) {
                Cache::forget('location-options-'.$location->area_id);
            }
            foreach ($location->areas()->pluck('areas.id') as $areaId) {
                Cache::forget('location-options-'.$areaId);
            }
            Cache::forget('active-locations-with-coords-v2');
        });
    }
}
