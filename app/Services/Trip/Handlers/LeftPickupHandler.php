<?php

namespace App\Services\Trip\Handlers;

use App\Enums\OrderStatus;
use App\Enums\TripStatus;
use App\Models\Trip;

class LeftPickupHandler implements CheckpointHandlerInterface
{
    public function handle(Trip $trip): void
    {
        $trip->status = TripStatus::Delivering;
        $trip->save();

        $trip->orders()
            ->where('status', OrderStatus::Sent->value)
            ->update(['status' => OrderStatus::InTransit->value]);
    }
}
