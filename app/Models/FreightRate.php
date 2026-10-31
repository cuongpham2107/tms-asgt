<?php

namespace App\Models;

use App\Enums\CargoType;
use App\Enums\VehicleType;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FreightRate extends Model
{
    protected $fillable = [
        'customer_id',
        'origin_location_id',
        'destination_location_id',
        'cargo_type',
        'rate_type',
        'vehicle_type',
        'prices',
        'effective_from',
        'effective_to',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'cargo_type' => CargoType::class,
            'vehicle_type' => VehicleType::class,
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    /**
     * Bảng đơn giá theo trọng tải: khoá = tải (tấn) dạng "1.25", "5"; giá trị = đơn giá VND/chuyến.
     * Chuẩn hoá khi lưu để nhập "2,5" hay "1.000.000" từ form vẫn tra được.
     */
    protected function prices(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): array => json_decode($value ?? '[]', true) ?: [],
            set: fn (?array $value): string => json_encode(
                collect($value ?? [])
                    ->mapWithKeys(fn ($price, $tonnage): array => [self::tonnageKey($tonnage) => self::parseMoney($price)])
                    ->filter(fn (?float $price, string $tonnage): bool => $tonnage !== '' && $price !== null && $price > 0)
                    ->sortKeysUsing(fn (string $a, string $b): int => (float) $a <=> (float) $b)
                    ->all(),
                JSON_FORCE_OBJECT,
            ),
        );
    }

    public static function tonnageKey(mixed $tonnage): string
    {
        $number = str_replace(',', '.', trim((string) $tonnage));

        return is_numeric($number) ? rtrim(rtrim(number_format((float) $number, 2, '.', ''), '0'), '.') : '';
    }

    private static function parseMoney(mixed $value): ?float
    {
        $text = str_replace(' ', '', trim((string) $value));

        // "1.000.000" hoặc "1,000,000" → bỏ dấu phân cách hàng nghìn
        if (preg_match('/^\d{1,3}([.,]\d{3})+$/', $text)) {
            $text = preg_replace('/[.,]/', '', $text);
        }

        $text = str_replace(',', '.', $text);

        return is_numeric($text) ? round((float) $text, 2) : null;
    }

    public function priceFor(float|string|null $tonnage): ?float
    {
        $price = $this->prices[self::tonnageKey($tonnage)] ?? null;

        return $price === null ? null : (float) $price;
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function originLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'origin_location_id');
    }

    public function destinationLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'destination_location_id');
    }

    /**
     * Các loại hình tính cước đã có trong bảng giá (gợi ý cho ô nhập).
     *
     * @return list<string>
     */
    public static function rateTypesFor(?int $customerId): array
    {
        return self::query()
            ->when($customerId, fn (Builder $q) => $q->where('customer_id', $customerId))
            ->where('rate_type', '!=', '')
            ->distinct()
            ->orderBy('rate_type')
            ->pluck('rate_type')
            ->all();
    }

    /**
     * Giá còn hiệu lực tại ngày $date (null = không giới hạn).
     */
    public function scopeEffectiveOn(Builder $query, CarbonInterface $date): Builder
    {
        $day = $date->toDateString();

        return $query
            ->where(fn (Builder $q) => $q->whereNull('effective_from')->orWhereDate('effective_from', '<=', $day))
            ->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $day));
    }

    /**
     * Mã giá dạng Excel: KH + điểm đi + điểm đến + loại hàng + loại hình + loại xe + trọng tải.
     */
    public function priceCode(float|string|null $tonnage): string
    {
        return implode('', array_filter([
            $this->customer?->code,
            $this->originLocation?->code,
            $this->destinationLocation?->code,
            $this->cargo_type?->value,
            $this->rate_type,
            $this->vehicle_type?->value,
        ])).'-'.self::tonnageKey($tonnage);
    }
}
