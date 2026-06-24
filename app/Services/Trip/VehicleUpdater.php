<?php

namespace App\Services\Trip;

use App\Models\Trip;

class VehicleUpdater
{
    /**
     * Cập nhật km, GPS của vehicle từ payload checkpoint.
     *
     * @param  array<string, mixed>  $payload
     */
    public function updateFromPayload(Trip $trip, array $payload): void
    {
        $vehicle = $trip->vehicle;
        if ($vehicle === null) {
            return;
        }

        $dirty = false;

        if (isset($payload['km_reading'])) {
            $vehicle->current_mileage = $payload['km_reading'];
            $dirty = true;
        }

        if (isset($payload['gps_lat'])) {
            $vehicle->gps_lat = $payload['gps_lat'];
            $dirty = true;
        }

        if (isset($payload['gps_lng'])) {
            $vehicle->gps_lng = $payload['gps_lng'];
            $dirty = true;
        }

        if ($dirty) {
            $vehicle->save();
        }
    }
}
