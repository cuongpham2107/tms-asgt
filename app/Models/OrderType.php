<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderType extends Model
{
    protected $fillable = [
        'code',
        'name',
        'description',
    ];

    public function categories(): HasMany
    {
        return $this->hasMany(OrderCategory::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
