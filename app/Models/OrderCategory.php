<?php

namespace App\Models;

use App\Enums\OrderType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderCategory extends Model
{
    protected $fillable = [
        'type',
        'code',
        'name',
        'color',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'type' => OrderType::class,
        ];
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
