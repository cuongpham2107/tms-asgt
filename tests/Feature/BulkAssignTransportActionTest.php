<?php

use App\Enums\OrderStatus;
use App\Enums\VehicleOwnerType;
use App\Enums\VehicleStatus;
use App\Enums\VehicleType;
use App\Filament\Resources\OrderPlans\Pages\ListOrderPlans;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderCategory;
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

    $this->orderCategory = OrderCategory::create([
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

test('can bulk assign vehicle and driver to draft orders', function () {
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

    $order1 = Order::create([
        'order_code' => 'ORD-001',
        'type' => 'HHHK',
        'order_category_id' => $this->orderCategory->id,
        'customer_id' => $this->customer->id,
        'status' => OrderStatus::Draft,
        'created_by' => User::factory()->create()->id,
    ]);

    $order2 = Order::create([
        'order_code' => 'ORD-002',
        'type' => 'HHHK',
        'order_category_id' => $this->orderCategory->id,
        'customer_id' => $this->customer->id,
        'status' => OrderStatus::Draft,
        'created_by' => User::factory()->create()->id,
    ]);

    $order3 = Order::create([
        'order_code' => 'ORD-003',
        'type' => 'HHHK',
        'order_category_id' => $this->orderCategory->id,
        'customer_id' => $this->customer->id,
        'status' => OrderStatus::Assigned,
        'created_by' => User::factory()->create()->id,
    ]);

    $admin = User::factory()->create();
    $this->actingAs($admin);

    Livewire::test(ListOrderPlans::class)
        ->callTableBulkAction('bulk_assign_transport', [$order1, $order2, $order3], [
            'vehicle_id' => $vehicle->id,
            'driver_id' => $driver->id,
            'override_shift_check' => true,
        ])
        ->assertHasNoTableBulkActionErrors();

    $order1->refresh();
    $order2->refresh();
    $order3->refresh();

    expect($order1->vehicle_id)->toBe($vehicle->id);
    expect($order1->driver_id)->toBe($driver->id);
    expect($order1->status)->toBe(OrderStatus::Assigned);

    expect($order2->vehicle_id)->toBe($vehicle->id);
    expect($order2->driver_id)->toBe($driver->id);
    expect($order2->status)->toBe(OrderStatus::Assigned);

    // Order3 is already Assigned, so it should not be affected by the bulk assign (only draft orders)
    expect($order3->vehicle_id)->toBeNull();
    expect($order3->driver_id)->toBeNull();

    $vehicle->refresh();
    expect($vehicle->current_driver_id)->toBe($driver->id);
    expect($vehicle->status)->toBe(VehicleStatus::Running);
});
