<?php

use App\Enums\CheckpointType;
use App\Enums\OrderStatus;
use App\Enums\TripStatus;
use App\Enums\VehicleOwnerType;
use App\Enums\VehicleStatus;
use App\Enums\VehicleType;
use App\Filament\Resources\Trips\Pages\ListTrips;
use App\Models\Area;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Trip;
use App\Models\TripCheckpoint;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $role = Role::firstOrCreate(['name' => 'driver', 'guard_name' => 'web']);
    $this->driver = User::factory()->create();
    $this->driver->assignRole($role);

    $this->admin = User::factory()->create();
    foreach (['ViewAny:Trip', 'View:Trip', 'Update:Trip'] as $permission) {
        $this->admin->givePermissionTo(Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']));
    }
    $this->actingAs($this->admin);

    $this->vehicle = Vehicle::create([
        'plate_number' => '29C-COMPLETE-01',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'ASGT',
        'is_active' => true,
        'status' => VehicleStatus::On,
        'type' => VehicleOwnerType::Company,
        'current_mileage' => 100000,
    ]);

    $this->area = Area::create([
        'code' => 'COMPLETE-AREA',
        'name' => 'Khu vực test hoàn thành',
    ]);

    $this->customer = Customer::create([
        'code' => 'COMPLETE-CUS',
        'name' => 'Khách test',
    ]);

    $this->trip = Trip::create([
        'trip_code' => 'TRIP-COMPLETE-01',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'status' => TripStatus::Delivering,
        'start_km' => 100000,
        'started_at' => now()->subHours(2),
    ]);

    $this->order = Order::create([
        'order_code' => 'ORD-COMPLETE-01',
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'created_by' => $this->admin->id,
        'trip_id' => $this->trip->id,
        'status' => OrderStatus::InTransit,
    ]);

    $this->cancelledOrder = Order::create([
        'order_code' => 'ORD-COMPLETE-CANCELLED',
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'created_by' => $this->admin->id,
        'trip_id' => $this->trip->id,
        'status' => OrderStatus::Cancelled,
    ]);
});

function editTripTableForCompletion()
{
    return Livewire::test(ListTrips::class, [
        'orderType' => 'all',
        'activePlaceFilter' => 'all',
        'activeStatusFilter' => 'all',
    ]);
}

test('checkpoint hoàn thành tự chuyển trip và order sang hoàn thành', function () {
    TripCheckpoint::create([
        'trip_id' => $this->trip->id,
        'checkpoint_type' => CheckpointType::Completed->value,
        'order_id' => $this->order->id,
        'driver_id' => $this->driver->id,
        'km_reading' => 100120,
        'occurred_at' => now()->subMinutes(10),
    ]);

    editTripTableForCompletion()
        ->mountTableAction('edit', $this->trip)
        ->fillForm(['driver_id' => $this->driver->id])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    $trip = $this->trip->fresh();

    expect($trip->status)->toBe(TripStatus::Completed)
        ->and($trip->completed_at)->not->toBeNull()
        ->and((float) $trip->end_km)->toBe(100120.0)
        ->and($this->order->fresh()->status)->toBe(OrderStatus::Completed)
        ->and($this->cancelledOrder->fresh()->status)->toBe(OrderStatus::Cancelled);
});

test('checkpoint kết thúc xe tự chuyển trip và order sang hoàn thành', function () {
    TripCheckpoint::create([
        'trip_id' => $this->trip->id,
        'checkpoint_type' => CheckpointType::End->value,
        'order_id' => $this->order->id,
        'driver_id' => $this->driver->id,
        'km_reading' => 100150,
        'occurred_at' => now()->subMinutes(5),
    ]);

    editTripTableForCompletion()
        ->mountTableAction('edit', $this->trip)
        ->fillForm(['driver_id' => $this->driver->id])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    $trip = $this->trip->fresh();

    expect($trip->status)->toBe(TripStatus::Completed)
        ->and((float) $trip->end_km)->toBe(100150.0)
        ->and($this->order->fresh()->status)->toBe(OrderStatus::Completed);
});

test('không có checkpoint kết thúc thì không tự hoàn thành', function () {
    TripCheckpoint::create([
        'trip_id' => $this->trip->id,
        'checkpoint_type' => CheckpointType::Started->value,
        'order_id' => $this->order->id,
        'driver_id' => $this->driver->id,
        'km_reading' => 100000,
        'occurred_at' => now()->subHours(2),
    ]);

    editTripTableForCompletion()
        ->mountTableAction('edit', $this->trip)
        ->fillForm(['driver_id' => $this->driver->id])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    expect($this->trip->fresh()->status)->toBe(TripStatus::Delivering)
        ->and($this->order->fresh()->status)->toBe(OrderStatus::InTransit);
});

test('thêm checkpoint hoàn thành trong form tự hoàn thành trip và order', function () {
    $started = TripCheckpoint::create([
        'trip_id' => $this->trip->id,
        'checkpoint_type' => CheckpointType::Started->value,
        'order_id' => $this->order->id,
        'driver_id' => $this->driver->id,
        'km_reading' => 100000,
        'occurred_at' => now()->subHours(2),
    ]);

    $rows = $this->trip->checkpoints()->get()->mapWithKeys(fn (TripCheckpoint $checkpoint): array => [
        'record-'.$checkpoint->id => [
            'checkpoint_type' => $checkpoint->checkpoint_type->value,
            'order_id' => $checkpoint->order_id,
            'driver_id' => $checkpoint->driver_id,
            'km_reading' => $checkpoint->km_reading,
            'occurred_at' => $checkpoint->occurred_at->format('Y-m-d H:i'),
            'delivery_point_id' => $checkpoint->delivery_point_id,
        ],
    ])->all();

    $rows['new-completed'] = [
        'checkpoint_type' => CheckpointType::Completed->value,
        'order_id' => $this->order->id,
        'driver_id' => $this->driver->id,
        'km_reading' => 100200,
        'occurred_at' => now()->format('Y-m-d H:i'),
    ];

    editTripTableForCompletion()
        ->mountTableAction('edit', $this->trip)
        ->fillForm(['checkpoints' => $rows])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    $trip = $this->trip->fresh();

    expect($trip->status)->toBe(TripStatus::Completed)
        ->and((float) $trip->end_km)->toBe(100200.0)
        ->and($trip->checkpoints()->where('checkpoint_type', CheckpointType::Completed->value)->exists())->toBeTrue()
        ->and($this->order->fresh()->status)->toBe(OrderStatus::Completed);

    expect($started->fresh())->not->toBeNull();
});
