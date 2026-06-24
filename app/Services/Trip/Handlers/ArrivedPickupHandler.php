<?php

namespace App\Services\Trip\Handlers;

use App\Enums\TripStatus;
use App\Models\Trip;

class ArrivedPickupHandler implements CheckpointHandlerInterface
{
    public function handle(Trip $trip): void
    {
        $trip->status = TripStatus::ArrivedPickup;
        $trip->save();
    }
}
