<?php

namespace App\Models;

use App\Enums\VehicleDocumentStatus;
use App\Enums\VehicleDocumentType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class VehicleDocument extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'vehicle_id',
        'doc_type',
        'certificate_number',
        'issued_by',
        'issued_date',
        'expiry_date',
        'renewal_cost',
        'last_renewed_date',
        'notes',
        'status',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'issued_date' => 'date',
            'expiry_date' => 'date',
            'last_renewed_date' => 'date',
            'renewal_cost' => 'integer',
            'doc_type' => VehicleDocumentType::class,
            'status' => VehicleDocumentStatus::class,
        ];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
