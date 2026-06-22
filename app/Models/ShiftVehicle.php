<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

class ShiftVehicle extends Model
{
    protected $fillable = [
        'shift_id',
        'vehicle_id',
        'start_time',
        'end_time',
        'start_km',
        'end_km',
        'start_gps_lat',
        'start_gps_lng',
        'end_gps_lat',
        'end_gps_lng',
    ];

    protected function casts(): array
    {
        return [
            'start_time' => 'datetime',
            'end_time' => 'datetime',
            'start_km' => 'decimal:1',
            'end_km' => 'decimal:1',
            'start_gps_lat' => 'decimal:7',
            'start_gps_lng' => 'decimal:7',
            'end_gps_lat' => 'decimal:7',
            'end_gps_lng' => 'decimal:7',
        ];
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(DriverShift::class, 'shift_id');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /**
     * Get the orders associated with this vehicle segment (trip).
     *
     * @return Collection<Order>
     */
    public function getOrdersAttribute()
    {
        $shiftId = $this->shift_id;
        $vehicleId = $this->vehicle_id;
        $startTimeDate = $this->start_time?->toDateString();
        $endTimeDate = $this->end_time?->toDateString();

        return Order::query()
            ->where('vehicle_id', $vehicleId)
            ->where(function ($q) use ($shiftId, $startTimeDate, $endTimeDate) {
                $q->where('shift_id', $shiftId);

                if ($startTimeDate) {
                    $q->orWhere(function ($q2) use ($startTimeDate, $endTimeDate) {
                        $q2->whereNull('shift_id');

                        if ($endTimeDate) {
                            $q2->whereBetween('planned_loading_at', [
                                $startTimeDate.' 00:00:00',
                                $endTimeDate.' 23:59:59',
                            ]);
                        } else {
                            $q2->where(function ($q3) use ($startTimeDate) {
                                $q3->whereDate('planned_loading_at', $startTimeDate)
                                    ->orWhereDate('planned_loading_at', today())
                                    ->orWhereIn('status', ['assigned', 'sent', 'started', 'arrived_pickup', 'delivering', 'arrived_delivery']);
                            });
                        }
                    });
                }
            })
            ->with(['pickupLocation', 'deliveryPoints.location', 'driverSwaps.fromDriver', 'driverSwaps.toDriver', 'customer', 'area', 'tripCheckpoints.deliveryPoint.location'])
            ->get();
    }

    /**
     * Tọa độ hiển thị trên bản đồ.
     *
     * @return array{lat: float, lng: float}
     */
    public function getMapCoordsAttribute(): array
    {
        $orders = $this->getOrdersAttribute();

        if ($orders && $orders->isNotEmpty()) {
            foreach ($orders as $order) {
                $latestCheckpoint = $order->tripCheckpoints
                    ->sortByDesc('occurred_at')
                    ->first(fn ($c) => $c->gps_lat !== null && $c->gps_lng !== null);

                if ($latestCheckpoint !== null) {
                    return [
                        'lat' => (float) $latestCheckpoint->gps_lat,
                        'lng' => (float) $latestCheckpoint->gps_lng,
                    ];
                }
            }

            // Fallback to first order's pickup location
            $firstOrder = $orders->first();
            if ($firstOrder && $firstOrder->pickupLocation) {
                return [
                    'lat' => (float) ($firstOrder->pickupLocation->lat ?? 10.8231),
                    'lng' => (float) ($firstOrder->pickupLocation->lng ?? 106.6297),
                ];
            }
        }

        // Fallback to vehicle GPS
        if ($this->vehicle && $this->vehicle->gps_lat !== null && $this->vehicle->gps_lng !== null) {
            return [
                'lat' => (float) $this->vehicle->gps_lat,
                'lng' => (float) $this->vehicle->gps_lng,
            ];
        }

        return ['lat' => 10.8231, 'lng' => 106.6297];
    }
}
