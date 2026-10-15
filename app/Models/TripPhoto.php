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

    public function getUrlAttribute(): string
    {
        if (! empty($this->photo_path)) {
            return asset('storage/'.ltrim($this->photo_path, '/'));
        }

        if (! empty($this->photo_url)) {
            if (preg_match('#/storage/(.+)$#', $this->photo_url, $matches)) {
                return asset('storage/'.ltrim($matches[1], '/'));
            }

            return $this->photo_url;
        }

        return '';
    }

    public function getIsValidAttribute(): bool
    {
        if (empty($this->photo_path)) {
            return false;
        }
        $fullPath = storage_path('app/public/'.ltrim($this->photo_path, '/'));

        return file_exists($fullPath) && filesize($fullPath) > 0;
    }
}
