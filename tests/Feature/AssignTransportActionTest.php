<?php

use App\Enums\OrderStatus;
use App\Enums\ShiftType;
use App\Enums\VehicleOwnerType;
use App\Enums\VehicleStatus;
use App\Enums\VehicleType;
use App\Filament\Resources\OrderPlans\Pages\ListOrderPlans;
use App\Models\Area;
use App\Models\Customer;
use App\Models\DriverShift;
use App\Models\Order;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Gate::before(fn () => true);

    $this->driverRole = Role::create([
        'name' => 'driver',
        'guard_name' => 'web',
    ]);

    $this->area = Area::create([
        'type' => 'HHHK',
        'code' => 'NORTH',
        'name' => 'North',
    ]);

    $this->customer = Customer::create([
        'code' => 'CUST-001',
        'name' => 'Customer 001',
        'is_active' => true,
    ]);
});

test('can assign vehicle and driver to order and update vehicle current driver', function () {
    $driver = User::factory()->create();
    $driver->assignRole($this->driverRole);

    $vehicle = Vehicle::create([
        'plate_number' => '51C-123.45',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'ASGT',
        'is_active' => true,
        'status' => VehicleStatus::On,
        'type' => VehicleOwnerType::Company,
    ]);

    $order = Order::create([
        'order_code' => 'ORD-001',
        'type' => 'HHHK',
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'status' => OrderStatus::Draft,
        'created_by' => User::factory()->create()->id,
    ]);

    $admin = User::factory()->create();
    $this->actingAs($admin);

    Livewire::test(ListOrderPlans::class)
        ->mountTableAction('assign_transport', $order)
        ->setTableActionData([
            'vehicle_id' => $vehicle->id,
            'driver_id' => $driver->id,
            'override_shift_check' => true,
        ])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    $order->refresh();
    expect($order->vehicle_id)->toBe($vehicle->id);
    expect($order->driver_id)->toBe($driver->id);
    expect($order->status)->toBe(OrderStatus::Assigned);

    $vehicle->refresh();
    expect($vehicle->current_driver_id)->toBe($driver->id);
    expect($vehicle->status)->toBe(VehicleStatus::Running);
});

test('warns when vehicle or driver has no active shift and override is false', function () {
    $driver = User::factory()->create();
    $driver->assignRole($this->driverRole);

    $vehicle = Vehicle::create([
        'plate_number' => '51C-123.45',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'ASGT',
        'is_active' => true,
        'status' => VehicleStatus::On,
        'type' => VehicleOwnerType::Company,
    ]);

    $order = Order::create([
        'order_code' => 'ORD-002',
        'type' => 'HHHK',
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'status' => OrderStatus::Draft,
        'created_by' => User::factory()->create()->id,
    ]);

    $admin = User::factory()->create();
    $this->actingAs($admin);

    Livewire::test(ListOrderPlans::class)
        ->mountTableAction('assign_transport', $order)
        ->setTableActionData([
            'vehicle_id' => $vehicle->id,
            'driver_id' => $driver->id,
            'override_shift_check' => false,
        ])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    $order->refresh();
    expect($order->vehicle_id)->toBeNull();
    expect($order->driver_id)->toBeNull();
    expect($order->status)->toBe(OrderStatus::Draft);
});

test('assigns successfully without override when driver has active shift', function () {
    $driver = User::factory()->create();
    $driver->assignRole($this->driverRole);

    DriverShift::create([
        'driver_id' => $driver->id,
        'shift_type' => ShiftType::Full,
        'start_time' => now(),
    ]);

    $vehicle = Vehicle::create([
        'plate_number' => '51C-123.45',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'ASGT',
        'is_active' => true,
        'status' => VehicleStatus::On,
        'type' => VehicleOwnerType::Company,
    ]);

    $order = Order::create([
        'order_code' => 'ORD-003',
        'type' => 'HHHK',
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'status' => OrderStatus::Draft,
        'created_by' => User::factory()->create()->id,
    ]);

    $admin = User::factory()->create();
    $this->actingAs($admin);

    Livewire::test(ListOrderPlans::class)
        ->mountTableAction('assign_transport', $order)
        ->setTableActionData([
            'vehicle_id' => $vehicle->id,
            'driver_id' => $driver->id,
            'override_shift_check' => false,
        ])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    $order->refresh();
    expect($order->vehicle_id)->toBe($vehicle->id);
    expect($order->driver_id)->toBe($driver->id);
    expect($order->status)->toBe(OrderStatus::Assigned);
});

test('selecting vehicle automatically sets driver_id in form state', function () {
    $driver = User::factory()->create();
    $driver->assignRole($this->driverRole);

    $vehicle = Vehicle::create([
        'plate_number' => '51C-123.45',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'ASGT',
        'is_active' => true,
        'status' => VehicleStatus::On,
        'type' => VehicleOwnerType::Company,
        'current_driver_id' => $driver->id,
    ]);

    $order = Order::create([
        'order_code' => 'ORD-AUTO-001',
        'type' => 'HHHK',
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'status' => OrderStatus::Draft,
        'created_by' => User::factory()->create()->id,
    ]);

    $admin = User::factory()->create();
    $this->actingAs($admin);

    $lw = Livewire::test(ListOrderPlans::class)
        ->mountTableAction('assign_transport', $order)
        ->setTableActionData([
            'vehicle_id' => $vehicle->id,
        ]);

    expect($lw->instance()->getMountedTableActionForm()->getState()['driver_id'])->toBe($driver->id);
});
