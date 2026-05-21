<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderTemplate extends Model
{
    protected $fillable = [
        'name',
        'order_data',
        'quantity',
        'cron_expression',
        'daily_run_at',
        'is_active',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'order_data' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
