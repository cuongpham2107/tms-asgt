<?php

use App\Enums\CheckpointType;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\ShiftType;
use App\Enums\TripStatus;
use App\Enums\VehicleOwnerType;
use App\Enums\VehicleStatus;
use App\Enums\VehicleType;
use App\Models\Area;
use App\Models\Customer;
use App\Models\DriverShift;
use App\Models\Order;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Trip\TripCancellationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $driver = User::factory()->create();
    $this->vehicle = Vehicle::create(['plate_number' => '29C-CANCEL', 'vehicle_type' => VehicleType::Normal, 'owner' => 'CLC', 'is_active' => true, 'status' => VehicleStatus::Running, 'type' => VehicleOwnerType::Company, 'current_mileage' => 1000]);
    $shift = DriverShift::create(['driver_id' => $driver->id, 'shift_type' => ShiftType::Full, 'start_time' => now()->subHours(3)]);
    $this->trip = Trip::create(['trip_code' => 'TRIP-CANCEL', 'vehicle_id' => $this->vehicle->id, 'driver_id' => $driver->id, 'shift_id' => $shift->id, 'status' => TripStatus::Delivering, 'started_at' => now()->subHours(2), 'start_km' => 1000]);
    $this->order = Order::create(['order_code' => 'ASG-CANCEL', 'type' => OrderType::Hhhk, 'area_id' => Area::create(['code' => 'NBA', 'name' => 'NBA'])->id, 'customer_id' => Customer::create(['code' => 'C', 'name' => 'C'])->id, 'trip_id' => $this->trip->id, 'status' => OrderStatus::InTransit, 'created_by' => $driver->id]);
});

test('cancelling an order cancels it and its trip, leaving km and vehicle as before', function () {
    app(TripCancellationService::class)->cancelOrder($this->order, 'Khách huỷ');

    $order = $this->order->fresh();
    $trip = $this->trip->fresh();
    expect($order->status)->toBe(OrderStatus::Cancelled)
        ->and($order->cancel_reason)->toBe('Khách huỷ')
        ->and($trip->status)->toBe(TripStatus::Cancelled)
        ->and($trip->end_km)->toBeNull()
        ->and($this->vehicle->fresh()->status)->toBe(VehicleStatus::Running);
});

test('cancelling a trip with a km closes the km and records a cancel checkpoint', function () {
    app(TripCancellationService::class)->cancelTrip($this->trip, 1030.0, 'Hỏng xe');

    $trip = $this->trip->fresh();
    expect($trip->status)->toBe(TripStatus::Cancelled)
        ->and((float) $trip->end_km)->toBe(1030.0)
        ->and($this->order->fresh()->status)->toBe(OrderStatus::Cancelled)
        ->and((float) $this->vehicle->fresh()->current_mileage)->toBe(1030.0)
        ->and($this->vehicle->fresh()->status)->toBe(VehicleStatus::On)
        ->and($trip->checkpoints()->where('checkpoint_type', CheckpointType::Cancelled->value)->value('km_reading'))->toEqual(1030);
});
