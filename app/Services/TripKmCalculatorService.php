<?php

namespace App\Services;

use App\Models\Trip;

class TripKmCalculatorService
{
    /**
     * @todo P6: Rewrite using GpsDistanceService
     */
    public function calculate(Trip $trip, ?float $endKm = null): void
    {
        // No-op until Phase 6
    }
}
