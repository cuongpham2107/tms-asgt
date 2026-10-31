<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WaitingFeeRate extends Model
{
    public const MODE_NORMAL = 'normal';

    public const MODE_ENGINE_ON = 'engine_on';

    public const MODE_ENGINE_OFF = 'engine_off';

    protected $fillable = [
        'customer_id',
        'surcharge_type',
        'waiting_mode',
        'tiers',
        'fee_block_minutes',
        'grace_minutes',
        'effective_from',
        'effective_to',
    ];

    protected function casts(): array
    {
        return [
            'fee_block_minutes' => 'integer',
            'grace_minutes' => 'integer',
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    /**
     * Phí giờ chờ theo trọng tải: [{tonnage, fee, free_minutes}], sắp theo tải tăng dần.
     */
    protected function tiers(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): array => json_decode($value ?? '[]', true) ?: [],
            set: fn (?array $value): string => json_encode(
                collect($value ?? [])
                    ->filter(fn (array $tier): bool => FreightRate::tonnageKey($tier['tonnage'] ?? '') !== '' && is_numeric($tier['fee'] ?? null))
                    ->map(fn (array $tier): array => [
                        'tonnage' => (float) FreightRate::tonnageKey($tier['tonnage']),
                        'fee' => (float) $tier['fee'],
                        'free_minutes' => (int) ($tier['free_minutes'] ?? 0),
                    ])
                    ->sortBy('tonnage')
                    ->values()
                    ->all(),
            ),
        );
    }

    /**
     * @return array{tonnage: float, fee: float, free_minutes: int}|null
     */
    public function tierFor(float|string|null $tonnage): ?array
    {
        $key = FreightRate::tonnageKey($tonnage);

        return collect($this->tiers)->first(fn (array $tier): bool => FreightRate::tonnageKey($tier['tonnage']) === $key);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
