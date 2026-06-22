<?php

namespace App\Observers;

use App\Models\Order;
use App\Models\OrderEditLog;
use Illuminate\Support\Facades\Auth;

class OrderObserver
{
    public function updated(Order $order): void
    {
        if (! $order->wasChanged()) {
            return;
        }

        $user = Auth::user();
        if (! $user) {
            return;
        }

        $trackedFields = [
            'vehicle_id',
            'vehicle_plate_number',
            'vehicle_type',
            'driver_id',
            'trip_id',
            'status',
            'customer_id',
            'pickup_location_id',
            'pickup_address',
            'planned_loading_at',
            'total_packages',
            'total_weight',
            'cargo_name',
            'notes',
        ];

        foreach ($order->getChanges() as $field => $newValue) {
            if (! in_array($field, $trackedFields)) {
                continue;
            }

            $oldValue = $order->getOriginal($field);

            OrderEditLog::create([
                'order_id' => $order->id,
                'user_id' => $user->id,
                'field' => $field,
                'old_value' => $oldValue instanceof \BackedEnum ? $oldValue->value : ($oldValue !== null ? (string) $oldValue : null),
                'new_value' => $newValue instanceof \BackedEnum ? $newValue->value : ($newValue !== null ? (string) $newValue : null),
            ]);
        }
    }
}
