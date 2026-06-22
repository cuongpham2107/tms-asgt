<?php

namespace App\Models;

use App\Enums\OrderType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Area extends Model
{
    protected $table = 'areas';

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
        return $this->hasMany(Order::class, 'area_id');
    }

    public function locations(): HasMany
    {
        return $this->hasMany(Location::class, 'area_id');
    }

    protected static function booted(): void
    {
        static::deleting(function (Area $area) {
            if ($area->orders()->exists()) {
                throw new \Exception('Không thể xóa khu vực này vì đang có đơn hàng sử dụng.');
            }
            if ($area->locations()->exists()) {
                throw new \Exception('Không thể xóa khu vực này vì đang có địa điểm sử dụng.');
            }
        });
    }
}
