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

        // Chuyển Assigned → Sent khi trip bắt đầu lần đầu
        $trip->orders()
            ->where('status', OrderStatus::Assigned)
            ->update(['status' => OrderStatus::Sent->value, 'sent_at' => $occurredAt]);

        // Khi trip được restart sau DriverSwap, khôi phục orders về Sent
        $trip->orders()
            ->where('status', OrderStatus::DriverSwap)
            ->update(['status' => OrderStatus::Sent->value]);

        // Set sent_at cho orders đã Sent nhưng chưa có sent_at
        $trip->orders()
            ->where('status', OrderStatus::Sent)
            ->whereNull('sent_at')
            ->update(['sent_at' => $occurredAt]);
    }
}
