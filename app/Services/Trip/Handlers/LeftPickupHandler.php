<?php

namespace App\Services\Trip\Handlers;

use App\Enums\TripStatus;
use App\Models\Trip;

class LeftPickupHandler implements CheckpointHandlerInterface
{
    public function handle(Trip $trip): void
    {
        $trip->status = TripStatus::Delivering;
        $trip->save();
    }
}
