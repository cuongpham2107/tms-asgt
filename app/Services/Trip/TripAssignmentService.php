<?php

namespace App\Services\Trip;

use App\Enums\OrderStatus;
use App\Enums\TripStatus;
use App\Enums\VehicleStatus;
use App\Filament\Resources\Orders\Actions\Concerns\CreatesOrderTransportCards;
use App\Models\Order;
use App\Models\Trip;
use App\Models\Vehicle;
use App\Services\Notification\DriverNotificationService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Nơi duy nhất tạo chuyến mới cho một hoặc nhiều đơn và gán xe / lái xe.
 */
class TripAssignmentService
{
    public function __construct(private readonly DriverNotificationService $notifications) {}

    /**
     * @param  Collection<int, Order>  $orders
     */
    public function assign(Collection $orders, int $vehicleId, ?int $driverId, bool $sendNow): Trip
    {
        $sorted = $orders->sortBy('planned_loading_at')->values();

        $trip = DB::transaction(function () use ($sorted, $vehicleId, $driverId, $sendNow) {
            $trip = Trip::create([
                'trip_code' => Trip::generateTripCode(),
                'vehicle_id' => $vehicleId,
                'driver_id' => $driverId,
                'status' => TripStatus::Pending,
                'start_location_id' => $sorted->first()?->pickup_location_id,
                'end_location_id' => $sorted->last()?->deliveryPoints()->orderByDesc('sequence')->first()?->location_id,
            ]);

            foreach ($sorted as $sequence => $order) {
                $order->trip_id = $trip->id;
                $order->trip_sequence = $sequence;
                $order->status = $sendNow ? OrderStatus::Sent : OrderStatus::Assigned;
                $order->sent_at = $sendNow ? now() : null;
                $order->save();
            }

            CreatesOrderTransportCards::createCheckpointsForExternalVehicle($trip, $sorted);

            $vehicle = Vehicle::find($vehicleId);
            if ($vehicle !== null) {
                $vehicle->status = VehicleStatus::Running;
                $vehicle->save();
            }

            return $trip;
        });

        if ($sendNow) {
            $this->notifyDriver($trip, $sorted);
        }

        return $trip;
    }

    /**
     * @param  Collection<int, Order>  $orders
     */
    private function notifyDriver(Trip $trip, Collection $orders): void
    {
        try {
            $orders->count() === 1
                ? $this->notifications->sendOrderAssigned($orders->first(), $trip)
                : $this->notifications->sendTripDispatched($trip, $orders->count());
        } catch (Throwable $e) {
            Log::warning('Lỗi gửi push notification khi gán chuyến: '.$e->getMessage(), ['exception' => $e]);
        }
    }
}
