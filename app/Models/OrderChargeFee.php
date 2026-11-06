<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderChargeFee extends Model
{
    protected $fillable = [
        'order_charge_id',
        'fee_type_id',
        'amount',
        'quantity',
        'note',
        'is_auto',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'quantity' => 'decimal:2',
            'is_auto' => 'boolean',
        ];
    }

    public function orderCharge(): BelongsTo
    {
        return $this->belongsTo(OrderCharge::class);
    }

    public function feeType(): BelongsTo
    {
        return $this->belongsTo(FeeType::class);
    }
}
