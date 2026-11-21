<?php

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\TripStatus;
use App\Enums\VehicleOwnerType;
use App\Enums\VehicleStatus;
use App\Enums\VehicleType;
use App\Models\Area;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Order;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    $this->area = Area::create([
        'type' => 'HHHK',
        'code' => 'NBA',
        'name' => 'Nội Bài',
        'is_active' => true,
        'sort_order' => 1,
    ]);

    $this->customer = Customer::create([
        'code' => 'ALSB',
        'name' => 'ALS Bắc Ninh',
        'is_active' => true,
    ]);

    $this->vehicle = Vehicle::create([
        'plate_number' => '29E36355',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'RENTED',
        'is_active' => true,
        'status' => VehicleStatus::On,
        'type' => VehicleOwnerType::Rent,
    ]);

    $this->ncts = Location::create([
        'code' => 'NCTS',
        'name' => 'NCTS',
        'address' => 'Nội Bài',
        'is_active' => true,
    ]);

    $this->alsb = Location::create([
        'code' => 'ALSB',
        'name' => 'ALSB',
        'address' => 'Bắc Ninh',
        'is_active' => true,
    ]);

    $this->customStart = Location::create([
        'code' => 'CUSTOM',
        'name' => 'Custom Start',
        'address' => 'Hà Nội',
        'is_active' => true,
    ]);
});

test('updating order pickup_location_id syncs trip start_location_id when it matched the previous pickup', function () {
    $trip = Trip::create([
        'trip_code' => 'CD-2026-11-20-837',
        'vehicle_id' => $this->vehicle->id,
        'status' => TripStatus::Completed,
        'start_location_id' => $this->ncts->id,
    ]);

    $order = Order::create([
        'order_code' => 'ASG-940',
        'type' => OrderType::Hhhk,
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'pickup_location_id' => $this->ncts->id,
        'trip_id' => $trip->id,
        'status' => OrderStatus::Completed,
        'created_by' => $this->user->id,
    ]);

    $order->update(['pickup_location_id' => $this->alsb->id]);

    expect($trip->fresh()->start_location_id)->toBe($this->alsb->id);
});

test('updating order pickup_location_id does not overwrite a manually customized trip start_location_id', function () {
    $trip = Trip::create([
        'trip_code' => 'CD-2026-11-20-838',
        'vehicle_id' => $this->vehicle->id,
        'status' => TripStatus::Completed,
        'start_location_id' => $this->customStart->id,
    ]);

    $order = Order::create([
        'order_code' => 'ASG-941',
        'type' => OrderType::Hhhk,
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'pickup_location_id' => $this->ncts->id,
        'trip_id' => $trip->id,
        'status' => OrderStatus::Completed,
        'created_by' => $this->user->id,
    ]);

    $order->update(['pickup_location_id' => $this->alsb->id]);

    expect($trip->fresh()->start_location_id)->toBe($this->customStart->id);
});
