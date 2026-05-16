<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderCategory extends Model
{
    protected $fillable = [
        'order_type_id',
        'code',
        'name',
        'description',
    ];

    public function orderType(): BelongsTo
    {
        return $this->belongsTo(OrderType::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
