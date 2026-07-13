<?php

namespace App\Models;

use App\Enums\CheckpointType;
use App\Enums\OrderStatus;
use App\Enums\TripStatus;
use App\Services\TripKmCalculatorService;
use Database\Factories\TripFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/** @use HasFactory<TripFactory> */
class Trip extends Model
{
    use HasFactory;

    // protected static function booted(): void
    // {
    //     static::creating(function (Trip $trip) {
    //         if ($trip->shift_id === null && $trip->driver_id !== null) {
    //             $activeShift = DriverShift::where('driver_id', $trip->driver_id)
    //                 ->whereNull('end_time')
    //                 ->first();

    //             if ($activeShift !== null) {
    //                 $trip->shift_id = $activeShift->id;
    //             }
    //         }
    //     });
    // }

    protected $fillable = [
        'trip_code',
        'vehicle_id',
        'driver_id',
        'shift_id',
        'status',
        'started_at',
        'completed_at',
        'cancelled_at',
        'start_km',
        'end_km',
        'total_km',
        'total_km_loaded',
        'total_km_empty',
        'start_location_id',
        'end_location_id',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'start_km' => 'decimal:1',
            'end_km' => 'decimal:1',
            'total_km' => 'decimal:1',
            'total_km_loaded' => 'decimal:1',
            'total_km_empty' => 'decimal:1',
            'status' => TripStatus::class,
        ];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(DriverShift::class, 'shift_id');
    }

    public function startLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'start_location_id');
    }

    public function endLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'end_location_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function checkpoints(): HasMany
    {
        return $this->hasMany(TripCheckpoint::class)->orderBy('occurred_at');
    }

    public function driverSwaps(): HasMany
    {
        return $this->hasMany(DriverSwap::class);
    }

    public function driverSwapCheckpoints(): HasMany
    {
        return $this->hasMany(TripCheckpoint::class)->where('checkpoint_type', 'driver_swap');
    }

    public function getStatusLabel(): string
    {
        return $this->status?->getLabel() ?? 'Không xác định';
    }

    public function getStatusColor(): string
    {
        return $this->status?->getColor() ?? 'gray';
    }

    public function isPending(): bool
    {
        return $this->status === TripStatus::Pending;
    }

    public function isCompleted(): bool
    {
        return $this->status === TripStatus::Completed;
    }

    public static function generateTripCode(): string
    {
        $nextId = (self::max('id') ?? 0) + 1;

        return 'CD-'.now()->format('Y-m-d').'-'.$nextId;
    }

    public function complete(?float $endKm = null, ?string $completedAt = null): void
    {
        DB::transaction(function () use ($endKm, $completedAt) {
            $this->status = TripStatus::Completed;
            $this->completed_at = $completedAt ?? now();
            $this->end_km = $endKm ?? $this->end_km;

            $startKm = (float) ($this->start_km ?? 0);
            $endKmValue = (float) ($this->end_km ?? 0);
            $this->total_km = max(0, $endKmValue - $startKm);

            $this->save();

            // Cập nhật km xe theo km kết thúc chuyến
            if ($endKmValue > 0 && $this->vehicle) {
                $this->vehicle->current_mileage = $endKmValue;
                $this->vehicle->save();
            }

            app(TripKmCalculatorService::class)->calculate($this);

            $this->createMissingEndCheckpoints($endKmValue, $this->completed_at);
        });
    }

    /**
     * Tự động tạo end checkpoint cho các order đã completed nhưng chưa có end.
     * Chạy bên trong DB transaction của complete().
     */
    private function createMissingEndCheckpoints(float $endKm, string $occurredAt): void
    {
        $completedOrderIds = $this->orders()
            ->where('status', OrderStatus::Completed->value)
            ->pluck('id');

        if ($completedOrderIds->isEmpty()) {
            return;
        }

        $existingEndOrderIds = TripCheckpoint::whereIn('order_id', $completedOrderIds)
            ->where('checkpoint_type', CheckpointType::End->value)
            ->pluck('order_id');

        $missingOrderIds = $completedOrderIds->diff($existingEndOrderIds);

        foreach ($missingOrderIds as $orderId) {
            TripCheckpoint::create([
                'checkpoint_type' => CheckpointType::End->value,
                'trip_id' => $this->id,
                'order_id' => $orderId,
                'km_reading' => $endKm,
                'occurred_at' => $occurredAt,
                'driver_id' => $this->driver_id,
                'shift_id' => $this->shift_id,
            ]);
        }
    }
}
