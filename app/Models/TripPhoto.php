<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TripPhoto extends Model
{
    protected $fillable = [
        'trip_checkpoint_id',
        'photo_path',
        'photo_url',
    ];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function tripCheckpoint(): BelongsTo
    {
        return $this->belongsTo(TripCheckpoint::class);
    }
}
