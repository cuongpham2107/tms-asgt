<?php

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

    $this->driver = User::factory()->create();
    $this->driver->assignRole($this->driverRole);

    $this->vehicle = Vehicle::create([
        'plate_number' => '51C-123.45',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'ASGT',
        'is_active' => true,
        'status' => VehicleStatus::Running,
        'type' => VehicleOwnerType::Company,
    ]);

    $this->trip = Trip::create([
        'trip_code' => 'TRIP-SEND-1',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'status' => TripStatus::Pending,
    ]);

    $this->area = Area::create([
        'type' => 'HHHK',
        'code' => 'NORTH',
        'name' => 'North Area',
    ]);

    $this->customer = Customer::create([
        'code' => 'CUST-001',
        'name' => 'Customer 1',
        'is_active' => true,
    ]);

    $this->order1 = Order::create([
        'order_code' => 'ORD-SEND-001',
        'type' => 'HHHK',
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'trip_id' => $this->trip->id,
        'status' => OrderStatus::Sent,
        'created_by' => User::factory()->create()->id,
    ]);

    $this->order2 = Order::create([
        'order_code' => 'ORD-SEND-002',
        'type' => 'HHHK',
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'trip_id' => $this->trip->id,
        'status' => OrderStatus::Assigned,
        'created_by' => User::factory()->create()->id,
    ]);

    $this->admin = User::factory()->create();
    $this->actingAs($this->admin);
});

test('can send trip and update all assigned orders to sent', function () {
    Livewire::test(ListTrips::class)
        ->set('orderType', 'all')
        ->mountTableAction('send_trip', $this->trip)
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    $this->order2->refresh();

    expect($this->order2->status)->toBe(OrderStatus::Sent);
    expect($this->order2->sent_at)->not->toBeNull();
});

test('send_trip action is hidden when trip has no un-sent orders', function () {
    $this->order1->update(['status' => OrderStatus::Sent]);
    $this->order2->update(['status' => OrderStatus::Sent]);

    $this->trip->refresh();

    Livewire::test(ListTrips::class)
        ->set('orderType', 'all')
        ->assertTableActionHidden('send_trip', $this->trip);
});
