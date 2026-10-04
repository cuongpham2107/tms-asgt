<?php

namespace App\Services;

use App\Enums\AssignmentEndReason;
use App\Enums\OrderStatus;
use App\Enums\TripStatus;
use App\Models\DriverShift;
use App\Models\Trip;
use App\Models\User;
use App\Services\Trip\TripDriverService;
use App\Services\Trip\TripStateMachine;
use Illuminate\Support\Facades\DB;

/**
 * Kết thúc ca dùng chung cho app tài xế và trang quản trị: không chặn khi còn chuyến dở.
 */
class DriverShiftService
{
    public function __construct(
        private readonly TripStateMachine $stateMachine,
        private readonly TripDriverService $driverService,
    ) {}

    public function endShift(DriverShift $shift, ?float $gpsLat = null, ?float $gpsLng = null): DriverShift
    {
        return DB::transaction(function () use ($shift, $gpsLat, $gpsLng) {
            $driver = User::findOrFail($shift->driver_id);

            $trips = Trip::query()
                ->where('driver_id', $driver->id)
                ->whereIn('status', TripStatus::driverActionableStatuses())
                ->get();

            foreach ($trips as $trip) {
                $this->releaseTrip($trip, $driver);
            }

            $shift->end_time = now();
            $shift->end_gps_lat = $gpsLat;
            $shift->end_gps_lng = $gpsLng;
            $shift->save();

            $vehicle = $trips->last()?->vehicle;
            if ($vehicle !== null && $gpsLat !== null && $gpsLng !== null) {
                $vehicle->gps_lat = $gpsLat;
                $vehicle->gps_lng = $gpsLng;
                $vehicle->save();
            }

            return $shift->refresh();
        });
    }

    private function releaseTrip(Trip $trip, User $driver): void
    {
        if ($trip->status === TripStatus::Pending) {
            $this->driverService->unassign($trip);

            return;
        }

        $hasOpenOrders = $trip->orders()->whereNotIn('status', OrderStatus::closedStatuses())->exists();

        if ($trip->status === TripStatus::Delivered || ! $hasOpenOrders) {
            $this->stateMachine->complete($trip);

            return;
        }

        $this->driverService->requestSwap($trip, $driver, AssignmentEndReason::ShiftHandover);
    }
}
