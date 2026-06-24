<?php

namespace App\Services\Trip\Handlers;

use App\Enums\OrderDeliveryPointStatus;
use App\Enums\TripStatus;
use App\Models\Trip;
use App\Models\TripCheckpoint;
use App\Services\Trip\DeliveryPointStatusUpdater;
use Illuminate\Support\Collection;

class ArrivedDeliveryHandler implements CheckpointHandlerInterface
{
    public function __construct(
        private readonly DeliveryPointStatusUpdater $statusUpdater,
    ) {}

    /**
     * @param  Collection<int, TripCheckpoint>  $checkpoints
     * @param  array<string, mixed>  $payload
     */
    public function handle(Trip $trip, array $payload, Collection $checkpoints): void
    {
        $trip->status = TripStatus::ArrivedDelivery;
        $trip->save();

        $occurredAt = $payload['occurred_at'] ?? now();

        foreach ($checkpoints as $checkpoint) {
            $this->statusUpdater->update(
                $checkpoint->delivery_point_id,
                OrderDeliveryPointStatus::Arrived,
                $occurredAt,
            );
        }
    }
}
