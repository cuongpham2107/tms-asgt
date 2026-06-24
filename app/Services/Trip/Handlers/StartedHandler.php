<?php

namespace App\Services\Trip\Handlers;

use App\Enums\OrderStatus;
use App\Enums\TripStatus;
use App\Models\Trip;

class StartedHandler implements CheckpointHandlerInterface
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(Trip $trip, array $payload): void
    {
        $this->startTrip($trip, $payload);
        $this->markOrdersAsSent($trip, $payload);
    }

    private function startTrip(Trip $trip, array $payload): void
    {
        if (! $trip->isPending()) {
            return;
        }

        $trip->status = TripStatus::Started;
        $trip->started_at = $payload['occurred_at'] ?? now();
        $trip->start_km = $trip->vehicle?->current_mileage ?? $trip->start_km;
        $trip->save();
    }

    private function markOrdersAsSent(Trip $trip, array $payload): void
    {
        $occurredAt = $payload['occurred_at'] ?? now();

        $trip->orders()
            ->where('status', OrderStatus::Sent)
            ->whereNull('sent_at')
            ->update(['sent_at' => $occurredAt]);
    }
}
