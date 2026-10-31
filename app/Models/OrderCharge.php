<?php

namespace App\Models;

use App\Enums\CargoType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderCharge extends Model
{
    protected $fillable = [
        'order_id',
        'trip_id',
        'customer_id',
        'freight_rate_id',
        'price_code',
        'cargo_type',
        'rate_type',
        'surcharge_type',
        'chargeable_tonnage',
        'is_return_trip',
        'return_trip_percent',
        'delivery_notes',
        'note',
        'base_price',
        'fuel_adjustment_percent',
        'adjusted_price',
        'waiting_minutes',
        'waiting_fee',
        'other_fees_total',
        'subtotal',
        'vat_percent',
        'vat_amount',
        'total',
        'exported_at',
        'exported_by',
    ];

    protected $attributes = [
        'rate_type' => '',
        'surcharge_type' => '',
        'return_trip_percent' => 50,
        'fuel_adjustment_percent' => 0,
        'vat_percent' => 0,
    ];

    protected function casts(): array
    {
        return [
            'cargo_type' => CargoType::class,
            'chargeable_tonnage' => 'decimal:2',
            'is_return_trip' => 'boolean',
            'return_trip_percent' => 'decimal:2',
            'delivery_notes' => 'array',
            'base_price' => 'decimal:2',
            'fuel_adjustment_percent' => 'decimal:2',
            'adjusted_price' => 'decimal:2',
            'waiting_minutes' => 'integer',
            'waiting_fee' => 'decimal:2',
            'other_fees_total' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'vat_percent' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'exported_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function freightRate(): BelongsTo
    {
        return $this->belongsTo(FreightRate::class);
    }

    public function exportedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'exported_by');
    }

    public function fees(): HasMany
    {
        return $this->hasMany(OrderChargeFee::class);
    }

    public function isLocked(): bool
    {
        return $this->exported_at !== null;
    }

    /**
     * Tính lại đơn giá sau điều chỉnh và các tổng tiền từ các trường đang có.
     * Đơn giá sau điều chỉnh làm tròn đến hàng nghìn (theo bảng tính Excel).
     */
    public function recalculateTotals(): static
    {
        $totals = self::computeTotals(
            basePrice: $this->base_price === null ? null : (float) $this->base_price,
            fuelPercent: (float) $this->fuel_adjustment_percent,
            waitingFee: (float) $this->waiting_fee,
            otherFees: $this->exists ? (float) $this->fees()->sum('amount') : 0,
            vatPercent: (float) $this->vat_percent,
        );

        $this->fill($totals);

        return $this;
    }

    /**
     * Công thức chung (lưu và xem trước trên form).
     *
     * @return array{adjusted_price: ?float, other_fees_total: float, subtotal: float, vat_amount: float, total: float}
     */
    public static function computeTotals(?float $basePrice, float $fuelPercent, float $waitingFee, float $otherFees, float $vatPercent): array
    {
        $adjustedPrice = $basePrice === null ? null : round($basePrice * (1 + $fuelPercent / 100), -3);
        $subtotal = (float) $adjustedPrice + $waitingFee + $otherFees;
        $vatAmount = round($subtotal * $vatPercent / 100);

        return [
            'adjusted_price' => $adjustedPrice,
            'other_fees_total' => $otherFees,
            'subtotal' => $subtotal,
            'vat_amount' => $vatAmount,
            'total' => $subtotal + $vatAmount,
        ];
    }
}
