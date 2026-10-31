<?php

namespace App\Models;

use Database\Factories\BillingZoneFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Khu vực tính cước: nhóm các địa điểm dùng chung đơn giá (VD "KCN YÊN PHONG" gồm SEV, ALSB, SDV…).
 */
class BillingZone extends Model
{
    /** @use HasFactory<BillingZoneFactory> */
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'notes',
    ];

    /**
     * Mã viết tắt chuẩn hoá để so khớp: "Kcn  Yên Phong" → "KCN YÊN PHONG".
     */
    public static function normalizeCode(?string $value): ?string
    {
        $code = trim((string) preg_replace('/\s+/u', ' ', (string) $value));

        return $code === '' ? null : mb_strtoupper($code);
    }

    protected function code(): Attribute
    {
        return Attribute::make(set: fn (?string $value): ?string => self::normalizeCode($value));
    }

    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }

    public function originRates(): HasMany
    {
        return $this->hasMany(FreightRate::class, 'origin_zone_id');
    }

    public function destinationRates(): HasMany
    {
        return $this->hasMany(FreightRate::class, 'destination_zone_id');
    }
}
