<?php

namespace App\Events;

use App\Models\Trip;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TripUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public int $tripId;

    public ?string $status;

    public ?string $driverName;

    public ?string $vehiclePlate;

    public string $action;

    public function __construct(Trip $trip, string $action = 'updated')
    {
        $this->tripId = $trip->id;
        $this->status = $trip->status?->value ?? (is_string($trip->status) ? $trip->status : null);
        $this->driverName = $trip->driver?->name;
        $this->vehiclePlate = $trip->vehicle?->plate_number;
        $this->action = $action;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('trips'),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'TripUpdated';
    }

    /**
     * Get the data to broadcast.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'trip_id' => $this->tripId,
            'status' => $this->status,
            'driver_name' => $this->driverName,
            'vehicle_plate' => $this->vehiclePlate,
            'action' => $this->action,
        ];
    }
}
