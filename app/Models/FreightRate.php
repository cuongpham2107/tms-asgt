<?php

namespace App\Models;

use App\Enums\CargoType;
use App\Enums\FreightPricingMethod;
use App\Enums\VehicleType;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FreightRate extends Model
{
    protected $attributes = [
        'pricing_method' => 'by_vehicle',
        'prices' => '{}',
    ];

    protected $fillable = [
        'customer_id',
        'origin_zone_id',
        'destination_zone_id',
        'cargo_type',
        'rate_type',
        'vehicle_type',
        'pricing_method',
        'prices',
        'range_prices',
        'effective_from',
        'effective_to',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'cargo_type' => CargoType::class,
            'vehicle_type' => VehicleType::class,
            'pricing_method' => FreightPricingMethod::class,
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

    /**
     * Bảng đơn giá theo khoảng trọng tải: mảng các tier gồm from, to, label, price.
     */
    protected function rangePrices(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): array => json_decode($value ?? '[]', true) ?: [],
            set: fn (mixed $value): ?string => empty($value) ? null : json_encode(
                collect($value)
                    ->filter(fn ($item) => is_array($item) && isset($item['price']) && filled($item['price']))
                    ->map(function ($item): array {
                        $from = isset($item['from']) && is_numeric(str_replace(',', '.', (string) $item['from']))
                            ? (float) str_replace(',', '.', (string) $item['from'])
                            : 0.0;
                        $to = isset($item['to']) && is_numeric(str_replace(',', '.', (string) $item['to']))
                            ? (float) str_replace(',', '.', (string) $item['to'])
                            : null;
                        $price = self::parseMoney($item['price']);
                        $label = trim((string) ($item['label'] ?? ''));

                        if ($label === '') {
                            if ($from == 0 && $to !== null) {
                                $label = "Dưới {$to}T";
                            } elseif ($to !== null) {
                                $label = "Từ {$from}T đến {$to}T";
                            } else {
                                $label = "Trên {$from}T";
                            }
                        }

                        return [
                            'from' => $from,
                            'to' => $to,
                            'label' => $label,
                            'price' => $price,
                        ];
                    })
                    ->sortBy('from')
                    ->values()
                    ->all(),
                JSON_UNESCAPED_UNICODE,
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

    public function matchedTier(float|string|null $tonnage): ?array
    {
        if ($tonnage === null || $this->pricing_method !== FreightPricingMethod::ByWeightRange || empty($this->range_prices)) {
            return null;
        }

        $weight = (float) str_replace(',', '.', (string) $tonnage);
        $tierCount = count($this->range_prices);

        foreach ($this->range_prices as $index => $tier) {
            $from = (float) ($tier['from'] ?? 0);
            $to = isset($tier['to']) && is_numeric($tier['to']) ? (float) $tier['to'] : null;
            $isLast = ($index === $tierCount - 1);

            $matchesFrom = ($from == 0.0) ? ($weight >= 0) : ($weight >= $from);
            $matchesTo = ($to === null) ? true : ($isLast ? $weight <= $to : $weight < $to);

            if ($matchesFrom && $matchesTo) {
                return $tier;
            }
        }

        return null;
    }

    public function priceFor(float|string|null $tonnage): ?float
    {
        if ($tonnage === null) {
            return null;
        }

        // 1. Phương thức tính theo khoảng trọng tải hàng
        if ($this->pricing_method === FreightPricingMethod::ByWeightRange && ! empty($this->range_prices)) {
            $tier = $this->matchedTier($tonnage);

            return $tier ? (float) ($tier['price'] ?? 0) : null;
        }

        // 2. Mặc định: Tra theo bậc tải trọng xe cố định
        $price = $this->prices[self::tonnageKey($tonnage)] ?? null;

        return $price === null ? null : (float) $price;
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function originZone(): BelongsTo
    {
        return $this->belongsTo(BillingZone::class, 'origin_zone_id');
    }

    public function destinationZone(): BelongsTo
    {
        return $this->belongsTo(BillingZone::class, 'destination_zone_id');
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
     * Mã giá dạng Excel: KH + khu vực đi + khu vực đến + loại hàng + loại hình + loại xe + trọng tải.
     */
    public function priceCode(float|string|null $tonnage): string
    {
        $suffix = self::tonnageKey($tonnage);

        if ($this->pricing_method === FreightPricingMethod::ByWeightRange && ! empty($this->range_prices) && $tonnage !== null) {
            $tier = $this->matchedTier($tonnage);
            if ($tier) {
                $suffix = $tier['label'] ?? "{$tier['from']}-{$tier['to']}";
            }
        }

        return implode('', array_filter([
            $this->customer?->code,
            $this->originZone?->code,
            $this->destinationZone?->code,
            $this->cargo_type?->value,
            $this->rate_type,
            $this->vehicle_type?->value,
        ])).'-'.$suffix;
    }
}
