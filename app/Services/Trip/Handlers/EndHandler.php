<?php

namespace App\Services\Trip\Handlers;

use App\Enums\CheckpointType;
use App\Enums\TripStatus;
use App\Models\DriverShift;
use App\Models\Trip;
use App\Models\TripCheckpoint;
use App\Models\Vehicle;
use App\Services\Trip\CheckpointFactory;
use App\Services\Trip\TripStateMachine;
use Illuminate\Support\Facades\DB;

/**
 * Tài xế rời xe giữa ca: chuyến đã giao xong thì hoàn thành, chuyến đang chạy thì chuyển đảo lái.
 *
 * @todo P4-T3 thay bằng TripDriverService khi bỏ endpoint end-vehicle.
 */
class EndHandler implements CheckpointHandlerInterface
{
    public function __construct(
        private readonly TripStateMachine $stateMachine,
        private readonly CheckpointFactory $checkpointFactory,
    ) {}

    public function handle(DriverShift $shift, Vehicle $vehicle): TripCheckpoint
    {
        return DB::transaction(function () use ($shift, $vehicle) {
            $activeTrip = Trip::where('vehicle_id', $vehicle->id)
                ->where('shift_id', $shift->id)
                ->whereIn('status', [
                    TripStatus::Started,
                    TripStatus::ArrivedPickup,
                    TripStatus::Delivering,
                    TripStatus::ArrivedDelivery,
                    TripStatus::Delivered,
                ])
                ->first();

            if ($activeTrip?->status === TripStatus::Delivered) {
                $this->stateMachine->complete($activeTrip);
            } elseif ($activeTrip !== null) {
                $this->stateMachine->requestSwap($activeTrip);

                $checkpoint = $this->checkpointFactory
                    ->create($activeTrip, ['occurred_at' => now()], CheckpointType::DriverSwap)
                    ->first();

                if ($checkpoint !== null) {
                    return $checkpoint;
                }
            }

            $isSwap = $activeTrip !== null && $activeTrip->status === TripStatus::DriverSwap;

            return TripCheckpoint::create([
                'checkpoint_type' => $isSwap ? CheckpointType::DriverSwap->value : CheckpointType::End->value,
                'trip_id' => $isSwap ? $activeTrip->id : null,
                'shift_id' => $shift->id,
                'driver_id' => $shift->driver_id,
                'occurred_at' => now(),
            ]);
        });
    }
}
