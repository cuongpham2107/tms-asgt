<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'code',
        'name',
        'phone',
        'email',
        'address',
        'contact_person',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    protected function address(): Attribute
    {
        return Attribute::set(function ($value) {
            if (blank($value)) {
                return $value;
            }

            if (! is_numeric($value)) {
                return $value;
            }

            return Location::query()->find($value)?->address ?? $value;
        });
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function locations(): BelongsToMany
    {
        return $this->belongsToMany(Location::class, 'customer_location')
            ->withPivot('loc_type')
            ->withTimestamps();
    }
}
