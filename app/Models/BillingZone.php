<?php

namespace App\Models;

use Database\Factories\BillingZoneFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Khu vực tính cước: nhóm các địa điểm dùng chung đơn giá (VD "KCN YÊN PHONG" gồm SEV, ALSB, SDV…).
 * Tên lấy theo sheet 5 cột D, mã tự tạo từ tên (sửa được).
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
     * Tên khu vực = cột "Địa điểm viết tắt (áp giá tính cước vc)" sheet 5, chuẩn hoá để so khớp:
     * "Kcn  Yên Bình" → "KCN YÊN BÌNH".
     */
    public static function normalizeName(?string $value): ?string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', (string) $value));

        return $name === '' ? null : mb_strtoupper($name);
    }

    /**
     * Mã tự tạo từ tên, không dấu: "KCN YÊN BÌNH" → "KCN-YEN-BINH"; trùng thì thêm hậu tố "-2", "-3"…
     */
    public static function uniqueCodeFor(string $name, ?int $ignoreId = null): string
    {
        $base = Str::upper(Str::slug($name)) ?: 'KV';
        $code = $base;
        for ($suffix = 2; self::query()->where('code', $code)->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists(); $suffix++) {
            $code = "{$base}-{$suffix}";
        }

        return $code;
    }

    protected static function booted(): void
    {
        static::saving(function (self $zone): void {
            if (blank($zone->code)) {
                $zone->code = self::uniqueCodeFor($zone->name, $zone->id);
            }
        });
    }

    protected function name(): Attribute
    {
        return Attribute::make(set: fn (?string $value): ?string => self::normalizeName($value));
    }

    protected function code(): Attribute
    {
        return Attribute::make(set: fn (?string $value): ?string => blank($value) ? null : Str::upper(trim($value)));
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
