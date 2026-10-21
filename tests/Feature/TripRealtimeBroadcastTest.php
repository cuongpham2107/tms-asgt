<?php

use App\Enums\CheckpointType;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\ShiftType;
use App\Enums\TripKmReportStatus;
use App\Enums\TripStatus;
use App\Enums\VehicleOwnerType;
use App\Enums\VehicleStatus;
use App\Enums\VehicleType;
use App\Events\TripUpdated;
use App\Filament\Resources\Trips\Pages\ListTrips;
use App\Models\Area;
use App\Models\Customer;
use App\Models\DriverShift;
use App\Models\Location;
use App\Models\Order;
use App\Models\Trip;
use App\Models\TripKmReport;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->driverRole = Role::create(['name' => 'driver', 'guard_name' => 'web']);

    $this->area = Area::create([
        'type' => OrderType::Hhhk,
        'code' => 'TEST_AREA',
        'name' => 'Test Area',
    ]);
    $this->customer = Customer::create([
        'code' => 'CUST_RT',
        'name' => 'Realtime Customer',
        'is_active' => true,
    ]);
    $this->vehicle = Vehicle::create([
        'plate_number' => '29A-99999',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'ASGT',
        'is_active' => true,
        'status' => VehicleStatus::On,
        'type' => VehicleOwnerType::Company,
        'current_mileage' => 10000,
    ]);
    $this->driver = User::factory()->create(['name' => 'Tài Xế Realtime']);
    $this->driver->assignRole($this->driverRole);
    $this->vehicle->update(['current_driver_id' => $this->driver->id]);

    $this->shift = DriverShift::create([
        'driver_id' => $this->driver->id,
        'vehicle_id' => $this->vehicle->id,
        'shift_type' => ShiftType::Full,
        'start_time' => now(),
    ]);

    $this->startLocation = Location::create([
        'code' => 'LOC-START',
        'name' => 'Điểm xuất phát',
        'address' => 'Hà Nội',
        'latitude' => 21.0,
        'longitude' => 105.8,
    ]);
});

test('trip observer dispatches TripUpdated event on create, update, and delete', function () {
    Event::fake([TripUpdated::class]);

    $trip = Trip::create([
        'driver_id' => $this->driver->id,
        'vehicle_id' => $this->vehicle->id,
        'start_location_id' => $this->startLocation->id,
        'status' => TripStatus::Pending,
        'trip_code' => 'TRIP-RT-01',
    ]);

    Event::assertDispatched(TripUpdated::class, function ($event) use ($trip) {
        return $event->tripId === $trip->id && $event->action === 'created';
    });

    $trip->update(['status' => TripStatus::Started]);

    Event::assertDispatched(TripUpdated::class, function ($event) use ($trip) {
        return $event->tripId === $trip->id && $event->action === 'updated';
    });

    $trip->delete();

    Event::assertDispatched(TripUpdated::class, function ($event) use ($trip) {
        return $event->tripId === $trip->id && $event->action === 'deleted';
    });
});

test('driver recording checkpoint triggers TripUpdated broadcast', function () {
    Event::fake([TripUpdated::class]);

    $trip = Trip::create([
        'driver_id' => $this->driver->id,
        'vehicle_id' => $this->vehicle->id,
        'start_location_id' => $this->startLocation->id,
        'status' => TripStatus::Pending,
        'trip_code' => 'TRIP-RT-02',
    ]);

    $order = Order::create([
        'order_code' => 'ORD-RT-01',
        'trip_id' => $trip->id,
        'customer_id' => $this->customer->id,
        'area_id' => $this->area->id,
        'pickup_location_id' => $this->startLocation->id,
        'status' => OrderStatus::Assigned,
        'type' => OrderType::Hhhk,
        'created_by' => $this->driver->id,
    ]);

    Sanctum::actingAs($this->driver);

    $response = $this->postJson("/api/driver/trips/{$trip->id}/checkpoints", [
        'checkpoint_type' => CheckpointType::Started->value,
        'occurred_at' => now()->toIso8601String(),
        'km_reading' => 10005,
    ]);

    $response->assertSuccessful();

    Event::assertDispatched(TripUpdated::class, function ($event) use ($trip) {
        return $event->tripId === $trip->id;
    });
});

test('TripUpdated event is configured for public trips channel and broadcast format', function () {
    $trip = Trip::create([
        'driver_id' => $this->driver->id,
        'vehicle_id' => $this->vehicle->id,
        'start_location_id' => $this->startLocation->id,
        'status' => TripStatus::Delivering,
        'trip_code' => 'TRIP-RT-03',
    ]);

    $event = new TripUpdated($trip, 'checkpoint');

    expect($event->broadcastOn()[0]->name)->toBe('trips')
        ->and($event->broadcastAs())->toBe('TripUpdated')
        ->and($event->broadcastWith())->toMatchArray([
            'trip_id' => $trip->id,
            'status' => TripStatus::Delivering->value,
            'driver_name' => 'Tài Xế Realtime',
            'vehicle_plate' => '29A-99999',
            'action' => 'checkpoint',
        ]);
});

test('ListTrips page reacts to echo TripUpdated event by refreshing memoized counts and table without notification', function () {
    $admin = User::factory()->create();
    $permission = Permission::create(['name' => 'ViewAny:Trip', 'guard_name' => 'web']);
    $admin->givePermissionTo($permission);
    $this->actingAs($admin);

    $trip = Trip::create([
        'driver_id' => $this->driver->id,
        'vehicle_id' => $this->vehicle->id,
        'start_location_id' => $this->startLocation->id,
        'status' => TripStatus::Pending,
        'trip_code' => 'TRIP-RT-04',
    ]);

    Livewire::test(ListTrips::class)
        ->call('onTripUpdated', ['trip_id' => $trip->id, 'action' => 'updated', 'vehicle_plate' => '29A-99999', 'status' => 'delivering'])
        ->assertSuccessful()
        ->assertHasNoErrors();
});

test('TripKmReport creation and resolution dispatches TripUpdated event', function () {
    Event::fake([TripUpdated::class]);

    $trip = Trip::create([
        'driver_id' => $this->driver->id,
        'vehicle_id' => $this->vehicle->id,
        'start_location_id' => $this->startLocation->id,
        'status' => TripStatus::Delivering,
        'trip_code' => 'TRIP-RT-KM',
    ]);

    $report = TripKmReport::create([
        'trip_id' => $trip->id,
        'driver_id' => $this->driver->id,
        'vehicle_id' => $this->vehicle->id,
        'reported_km' => 150000,
        'system_km' => 140000,
        'status' => TripKmReportStatus::Pending,
    ]);

    Event::assertDispatched(TripUpdated::class, function ($event) use ($trip) {
        return $event->tripId === $trip->id && $event->action === 'km_reported';
    });

    $report->update(['status' => TripKmReportStatus::Resolved]);

    Event::assertDispatched(TripUpdated::class, function ($event) use ($trip) {
        return $event->tripId === $trip->id && $event->action === 'km_resolved';
    });
});
