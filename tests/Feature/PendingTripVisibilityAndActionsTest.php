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
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Gate::before(fn () => true);

    $this->driverRole = Role::create([
        'name' => 'driver',
        'guard_name' => 'web',
    ]);

    $this->driverA = User::factory()->create(['name' => 'Driver A']);
    $this->driverA->assignRole($this->driverRole);

    $this->driverB = User::factory()->create(['name' => 'Driver B']);
    $this->driverB->assignRole($this->driverRole);

    $this->vehicleA = Vehicle::create([
        'plate_number' => '29C-111.11',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'ASGT',
        'is_active' => true,
        'status' => VehicleStatus::On,
        'type' => VehicleOwnerType::Company,
        'current_mileage' => 100000,
    ]);

    $this->vehicleB = Vehicle::create([
        'plate_number' => '29C-222.22',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'ASGT',
        'is_active' => true,
        'status' => VehicleStatus::On,
        'type' => VehicleOwnerType::Company,
        'current_mileage' => 200000,
    ]);

    $this->area = Area::create([
        'type' => 'HHHK',
        'code' => 'NBA',
        'name' => 'Noi Bai Area',
    ]);

    $this->customer = Customer::create([
        'code' => 'CUST-TEST-01',
        'name' => 'Test Customer',
        'is_active' => true,
    ]);

    $this->admin = User::factory()->create();
    $this->actingAs($this->admin);
});

test('trips with assigned orders are visible in ListTrips and can be reassigned and sent', function () {
    $trip = Trip::create([
        'trip_code' => 'TRIP-PENDING-01',
        'vehicle_id' => $this->vehicleA->id,
        'driver_id' => $this->driverA->id,
        'status' => TripStatus::Pending,
        'started_at' => now(),
    ]);

    $order = Order::create([
        'order_code' => 'ORD-PENDING-01',
        'type' => 'HHHK',
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'trip_id' => $trip->id,
        'status' => OrderStatus::Assigned,
        'created_by' => $this->admin->id,
    ]);

    // 1. Chuyến đi hiển thị trên ListTrips và có nhãn Chưa gửi lệnh
    $trip->load('orders');
    expect($trip->getStatusLabel())->toBe('Chưa gửi lệnh');
    expect($trip->getStatusColor())->toBe('warning');

    Livewire::test(ListTrips::class)
        ->assertCanSeeTableRecords([$trip])
        ->call('filterStatus', 'unsent')
        ->assertCanSeeTableRecords([$trip])
        ->call('filterStatus', 'pending')
        ->assertCanNotSeeTableRecords([$trip]);

    // 2. Mobile API của tài xế không thấy chuyến này khi đơn chưa Sent
    Sanctum::actingAs($this->driverA);
    $mobileRes = $this->getJson('/api/driver/trips/active')->assertSuccessful();
    expect($mobileRes->json('data'))->toBeNull();

    // 3. Điều hành có thể gán lại phương tiện & tài xế trên ListTrips
    $this->actingAs($this->admin);
    Livewire::test(ListTrips::class)
        ->mountTableAction('reassign_transport', $trip)
        ->setTableActionData([
            'vehicle_id' => $this->vehicleB->id,
            'driver_id' => $this->driverB->id,
        ])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    $trip->refresh();
    expect($trip->vehicle_id)->toBe($this->vehicleB->id);
    expect($trip->driver_id)->toBe($this->driverB->id);

    // 4. Điều hành có thể bấm Gửi lệnh (SendTripAction) trên ListTrips
    Livewire::test(ListTrips::class)
        ->callTableAction('send_trip', $trip)
        ->assertHasNoTableActionErrors();

    $order->refresh();
    expect($order->status)->toBe(OrderStatus::Sent);

    $trip->refresh();
    $trip->load('orders');
    expect($trip->getStatusLabel())->toBe('Chờ chạy');
    expect($trip->getStatusColor())->toBe('gray');

    Livewire::test(ListTrips::class)
        ->call('filterStatus', 'pending')
        ->assertCanSeeTableRecords([$trip])
        ->call('filterStatus', 'unsent')
        ->assertCanNotSeeTableRecords([$trip]);

    // 5. Sau khi gửi lệnh, tài xế B nhận được chuyến trên Mobile API
    Sanctum::actingAs($this->driverB);
    $mobileSentRes = $this->getJson('/api/driver/trips/active')->assertSuccessful();
    expect($mobileSentRes->json('data'))->not->toBeNull();
});
