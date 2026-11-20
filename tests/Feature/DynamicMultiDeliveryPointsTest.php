<?php

use App\Enums\CheckpointType;
use App\Enums\OrderDeliveryPointStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\ShiftType;
use App\Enums\TripStatus;
use App\Enums\VehicleOwnerType;
use App\Enums\VehicleStatus;
use App\Enums\VehicleType;
use App\Filament\Resources\Orders\Pages\EditOrder;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Trips\Pages\ListTrips;
use App\Models\Area;
use App\Models\Customer;
use App\Models\DriverShift;
use App\Models\Location;
use App\Models\Order;
use App\Models\OrderDeliveryPoint;
use App\Models\Trip;
use App\Models\TripCheckpoint;
use App\Models\User;
use App\Models\Vehicle;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Gate::before(fn () => true);

    $driverRole = Role::firstOrCreate(['name' => 'driver', 'guard_name' => 'web']);
    $this->driver = User::factory()->create(['name' => 'Lái Xe Test']);
    $this->driver->assignRole($driverRole);

    $this->admin = User::factory()->create();
    foreach (['ViewAny:Trip', 'View:Trip', 'Update:Trip', 'ViewAny:Order', 'View:Order', 'Update:Order'] as $permission) {
        $this->admin->givePermissionTo(Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']));
    }

    $this->vehicle = Vehicle::create([
        'plate_number' => '20C-13005',
        'owner' => 'ASGT',
        'type' => VehicleOwnerType::Company,
        'vehicle_type' => VehicleType::Normal,
        'status' => VehicleStatus::On,
        'current_mileage' => 1000.0,
        'is_active' => true,
    ]);

    $this->shift = DriverShift::create([
        'driver_id' => $this->driver->id,
        'vehicle_id' => $this->vehicle->id,
        'shift_type' => ShiftType::Full,
        'start_time' => now()->subHour(),
        'start_km' => 1000.0,
    ]);

    $this->area = Area::create([
        'type' => OrderType::External->value,
        'code' => 'NBA',
        'name' => 'Nội Bài',
        'is_active' => true,
    ]);

    $this->customer = Customer::create([
        'code' => 'ALSE',
        'name' => 'ALSE',
        'is_active' => true,
    ]);

    $this->pickup = Location::create([
        'code' => 'JUSDA',
        'name' => 'JUSDA',
        'lat' => 21.100000,
        'lng' => 105.800000,
        'loc_type' => 'pickup',
        'is_active' => true,
    ]);

    $this->dpLoc1 = Location::create([
        'code' => 'ALSE',
        'name' => 'Kho ALSE',
        'lat' => 21.120000,
        'lng' => 105.820000,
        'loc_type' => 'delivery',
        'is_active' => true,
    ]);

    $this->dpLoc2 = Location::create([
        'code' => 'NBA',
        'name' => 'Kho NBA',
        'lat' => 21.150000,
        'lng' => 105.850000,
        'loc_type' => 'delivery',
        'is_active' => true,
    ]);
});

test('driver can add multiple delivery points sequentially via API when order has no predefined delivery points', function () {
    Sanctum::actingAs($this->driver);

    $trip = Trip::create([
        'trip_code' => 'TRIP-DYN-01',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'shift_id' => $this->shift->id,
        'status' => TripStatus::Pending,
    ]);

    $order = Order::create([
        'order_code' => 'ORD-DYN-01',
        'type' => OrderType::External,
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'trip_id' => $trip->id,
        'pickup_location_id' => $this->pickup->id,
        'chargeable_weight' => 5,
        'status' => OrderStatus::Sent,
        'sent_at' => now(),
        'created_by' => $this->admin->id,
    ]);

    $this->postJson("/api/driver/trips/{$trip->id}/checkpoints", [
        'checkpoint_type' => 'arrived_pickup',
        'order_id' => $order->id,
        'km_reading' => 1005.0,
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();

    $this->postJson("/api/driver/trips/{$trip->id}/checkpoints", [
        'checkpoint_type' => 'left_pickup',
        'order_id' => $order->id,
        'km_reading' => 1005.0,
        'occurred_at' => now()->addMinute()->toIso8601String(),
    ])->assertSuccessful();

    // Tài xế tự chọn điểm trả 1 (ALSE)
    $this->postJson("/api/driver/trips/{$trip->id}/checkpoints", [
        'checkpoint_type' => 'arrived_delivery',
        'order_id' => $order->id,
        'new_delivery_location_id' => $this->dpLoc1->id,
        'km_reading' => 1015.0,
        'occurred_at' => now()->addMinutes(10)->toIso8601String(),
    ])->assertSuccessful();

    $dp1 = $order->deliveryPoints()->firstOrFail();
    expect($dp1->is_driver_added)->toBeTrue()
        ->and($dp1->sequence)->toBe(1)
        ->and($dp1->status)->toBe(OrderDeliveryPointStatus::Arrived);

    // Giao xong điểm 1
    $this->postJson("/api/driver/trips/{$trip->id}/checkpoints", [
        'checkpoint_type' => 'completed',
        'order_id' => $order->id,
        'delivery_point_id' => $dp1->id,
        'km_reading' => 1015.0,
        'occurred_at' => now()->addMinutes(20)->toIso8601String(),
    ])->assertSuccessful();

    expect($order->fresh()->status)->toBe(OrderStatus::Completed)
        ->and($order->fresh()->canDriverAddDeliveryPoint())->toBeTrue();

    // API vẫn trả can_add_delivery_point = true để App cho chọn thêm điểm 2
    $this->getJson("/api/driver/orders/{$order->id}")
        ->assertSuccessful()
        ->assertJsonPath('data.can_add_delivery_point', true);

    // Tài xế chọn tiếp điểm trả 2 (NBA) theo Cách A
    $this->postJson("/api/driver/trips/{$trip->id}/checkpoints", [
        'checkpoint_type' => 'arrived_delivery',
        'order_id' => $order->id,
        'new_delivery_location_id' => $this->dpLoc2->id,
        'km_reading' => 1030.0,
        'occurred_at' => now()->addMinutes(30)->toIso8601String(),
    ])->assertSuccessful();

    $dp2 = $order->deliveryPoints()->orderBy('sequence')->get()->last();
    expect($dp2->is_driver_added)->toBeTrue()
        ->and($dp2->sequence)->toBe(2)
        ->and($order->fresh()->status)->toBe(OrderStatus::InTransit)
        ->and($trip->fresh()->status)->toBe(TripStatus::ArrivedDelivery);

    // Giao xong điểm 2 và kết thúc đơn
    $this->postJson("/api/driver/trips/{$trip->id}/checkpoints", [
        'checkpoint_type' => 'completed',
        'order_id' => $order->id,
        'delivery_point_id' => $dp2->id,
        'km_reading' => 1030.0,
        'occurred_at' => now()->addMinutes(40)->toIso8601String(),
    ])->assertSuccessful();

    $this->postJson("/api/driver/trips/{$trip->id}/checkpoints", [
        'checkpoint_type' => 'end',
        'order_id' => $order->id,
        'km_reading' => 1035.0,
        'occurred_at' => now()->addMinutes(45)->toIso8601String(),
    ])->assertSuccessful();

    expect($order->fresh()->status)->toBe(OrderStatus::Completed)
        ->and((float) $order->fresh()->loaded_km)->toBe(25.0)
        ->and($trip->fresh()->status)->toBe(TripStatus::Completed)
        ->and($order->fresh()->canDriverAddDeliveryPoint())->toBeFalse();
});

test('order with predefined delivery point keeps old behavior and blocks dynamic extra location', function () {
    Sanctum::actingAs($this->driver);

    $trip = Trip::create([
        'trip_code' => 'TRIP-FIXED-01',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'shift_id' => $this->shift->id,
        'status' => TripStatus::Delivering,
        'started_at' => now()->subHour(),
    ]);

    $order = Order::create([
        'order_code' => 'ORD-FIXED-01',
        'type' => OrderType::External,
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'trip_id' => $trip->id,
        'pickup_location_id' => $this->pickup->id,
        'chargeable_weight' => 5,
        'status' => OrderStatus::InTransit,
        'created_by' => $this->admin->id,
    ]);

    $fixedDp = OrderDeliveryPoint::create([
        'order_id' => $order->id,
        'location_id' => $this->dpLoc1->id,
        'sequence' => 1,
        'status' => OrderDeliveryPointStatus::Delivered,
        'is_driver_added' => false,
    ]);

    expect($order->canDriverAddDeliveryPoint())->toBeFalse();

    $this->postJson("/api/driver/trips/{$trip->id}/checkpoints", [
        'checkpoint_type' => 'arrived_delivery',
        'order_id' => $order->id,
        'new_delivery_location_id' => $this->dpLoc2->id,
        'km_reading' => 1015.0,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('delivery_point_id');
});

test('external order creation allows empty delivery points by default', function () {
    $this->actingAs($this->admin);

    Livewire::test(ListOrders::class)
        ->mountAction('create_order_hang_ngoai_action')
        ->assertActionDataSet(['deliveryPoints' => []])
        ->fillForm([
            'customer_id' => $this->customer->id,
            'area_id' => $this->area->id,
            'pickup_location_id' => $this->pickup->id,
            'cargo_type' => 'GCR',
            'chargeable_weight' => '5',
            'planned_loading_at' => now()->toDateTimeString(),
        ])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $order = Order::where('customer_id', $this->customer->id)->latest('id')->firstOrFail();
    expect($order->type)->toBe(OrderType::External)
        ->and($order->deliveryPoints()->count())->toBe(0);

    $order->update(['status' => OrderStatus::Sent]);
    expect($order->fresh()->canDriverAddDeliveryPoint())->toBeTrue();
});

test('deleting completed checkpoint and adding new delivery point on web reopens order and trip for driver app', function () {
    $this->actingAs($this->admin);

    $trip = Trip::create([
        'trip_code' => 'TRIP-REOPEN-01',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'shift_id' => $this->shift->id,
        'status' => TripStatus::Delivered,
        'start_km' => 1000.0,
        'started_at' => Carbon::parse('2026-11-16 08:00'),
    ]);

    $order = Order::create([
        'order_code' => 'ASG-51',
        'type' => OrderType::External,
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'trip_id' => $trip->id,
        'pickup_location_id' => $this->pickup->id,
        'cargo_type' => 'GCR',
        'chargeable_weight' => 5,
        'planned_loading_at' => Carbon::parse('2026-11-16 08:30:00'),
        'status' => OrderStatus::Completed,
        'loaded_km' => 15.0,
        'created_by' => $this->admin->id,
    ]);

    $dp1 = OrderDeliveryPoint::create([
        'order_id' => $order->id,
        'location_id' => $this->dpLoc1->id,
        'sequence' => 1,
        'status' => OrderDeliveryPointStatus::Delivered,
        'arrived_at' => Carbon::parse('2026-11-16 09:00'),
        'delivered_at' => Carbon::parse('2026-11-16 09:30'),
    ]);

    $cpStarted = TripCheckpoint::create([
        'trip_id' => $trip->id,
        'order_id' => $order->id,
        'driver_id' => $this->driver->id,
        'checkpoint_type' => CheckpointType::Started->value,
        'km_reading' => 1000.0,
        'occurred_at' => Carbon::parse('2026-11-16 08:00'),
    ]);
    $cpArrivedPickup = TripCheckpoint::create([
        'trip_id' => $trip->id,
        'order_id' => $order->id,
        'driver_id' => $this->driver->id,
        'checkpoint_type' => CheckpointType::ArrivedPickup->value,
        'km_reading' => 1005.0,
        'occurred_at' => Carbon::parse('2026-11-16 08:20'),
    ]);
    $cpLeftPickup = TripCheckpoint::create([
        'trip_id' => $trip->id,
        'order_id' => $order->id,
        'driver_id' => $this->driver->id,
        'checkpoint_type' => CheckpointType::LeftPickup->value,
        'km_reading' => 1005.0,
        'occurred_at' => Carbon::parse('2026-11-16 08:40'),
    ]);
    $cpArrivedDelivery = TripCheckpoint::create([
        'trip_id' => $trip->id,
        'order_id' => $order->id,
        'delivery_point_id' => $dp1->id,
        'driver_id' => $this->driver->id,
        'checkpoint_type' => CheckpointType::ArrivedDelivery->value,
        'km_reading' => 1020.0,
        'occurred_at' => Carbon::parse('2026-11-16 09:00'),
    ]);
    $cpCompleted = TripCheckpoint::create([
        'trip_id' => $trip->id,
        'order_id' => $order->id,
        'delivery_point_id' => $dp1->id,
        'driver_id' => $this->driver->id,
        'checkpoint_type' => CheckpointType::Completed->value,
        'km_reading' => 1020.0,
        'occurred_at' => Carbon::parse('2026-11-16 09:30'),
    ]);

    // 1. Điều hành vào EditOrder thêm điểm giao thứ 2 (NBA) vào đơn đã Completed
    Livewire::test(EditOrder::class, ['record' => $order->getRouteKey()])
        ->set('data.deliveryPoints', [
            'record-'.$dp1->id => ['location_id' => $this->dpLoc1->id],
            'new-dp-2' => ['location_id' => $this->dpLoc2->id],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($order->fresh()->status)->toBe(OrderStatus::InTransit)
        ->and($trip->fresh()->status)->toBe(TripStatus::Delivering)
        ->and($this->vehicle->fresh()->status)->toBe(VehicleStatus::Running);

    // 2. Điều hành xoá mốc Completed của điểm 1 (ALSE) trong EditTripAction (giống hệt ảnh sếp chụp)
    $remaining = $trip->fresh()->checkpoints()
        ->where('id', '!=', $cpCompleted->id)
        ->get()
        ->mapWithKeys(fn (TripCheckpoint $cp): array => [
            'record-'.$cp->id => [
                'checkpoint_type' => $cp->checkpoint_type->value,
                'order_id' => $cp->order_id,
                'delivery_point_id' => $cp->delivery_point_id,
                'driver_id' => $cp->driver_id,
                'km_reading' => $cp->km_reading,
                'occurred_at' => $cp->occurred_at->format('Y-m-d H:i'),
            ],
        ])
        ->all();

    Livewire::test(ListTrips::class, [
        'orderType' => 'all',
        'activePlaceFilter' => 'all',
        'activeStatusFilter' => 'all',
    ])
        ->loadTable()
        ->mountTableAction('edit', $trip->fresh())
        ->set('mountedActions.0.data.checkpoints', $remaining)
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    expect($dp1->fresh()->status)->toBe(OrderDeliveryPointStatus::Arrived)
        ->and($dp1->fresh()->delivered_at)->toBeNull()
        ->and($order->fresh()->status)->toBe(OrderStatus::InTransit)
        ->and($trip->fresh()->status)->toBe(TripStatus::ArrivedDelivery);
});

test('adding a delivery point in the order list edit modal reopens a delivered order and trip for the driver app', function () {
    $this->actingAs($this->admin);

    $trip = Trip::create([
        'trip_code' => 'TRIP-REOPEN-MODAL',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'shift_id' => $this->shift->id,
        'status' => TripStatus::Delivered,
        'start_km' => 1000.0,
        'started_at' => now()->subHours(2),
    ]);

    $order = Order::create([
        'order_code' => 'ASG-1011',
        'type' => OrderType::External,
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'trip_id' => $trip->id,
        'pickup_location_id' => $this->pickup->id,
        'cargo_type' => 'GCR',
        'chargeable_weight' => 5,
        'planned_loading_at' => now()->subHours(2),
        'status' => OrderStatus::Completed,
        'created_by' => $this->admin->id,
    ]);

    $delivered = OrderDeliveryPoint::create([
        'order_id' => $order->id,
        'location_id' => $this->dpLoc1->id,
        'sequence' => 1,
        'status' => OrderDeliveryPointStatus::Delivered,
        'arrived_at' => now()->subHour(),
        'delivered_at' => now()->subMinutes(30),
    ]);

    foreach ([CheckpointType::Started, CheckpointType::ArrivedPickup, CheckpointType::LeftPickup, CheckpointType::ArrivedDelivery, CheckpointType::Completed] as $index => $type) {
        TripCheckpoint::create([
            'trip_id' => $trip->id,
            'order_id' => $order->id,
            'delivery_point_id' => in_array($type, [CheckpointType::ArrivedDelivery, CheckpointType::Completed], true) ? $delivered->id : null,
            'driver_id' => $this->driver->id,
            'checkpoint_type' => $type->value,
            'km_reading' => 1000.0 + $index * 5,
            'occurred_at' => now()->subMinutes(120 - $index * 20),
        ]);
    }

    Livewire::test(ListOrders::class, ['showMineOnly' => false, 'activePlaceFilter' => 'all', 'activeOrderTypeFilter' => 'all', 'activeStatusFilter' => 'completed'])
        ->loadTable()
        ->mountTableAction('edit', $order)
        ->setTableActionData([
            'deliveryPoints' => [
                'record-'.$delivered->id => ['location_id' => $this->dpLoc1->id],
                'new-dp-2' => ['location_id' => $this->dpLoc2->id],
            ],
        ])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    expect($order->fresh()->deliveryPoints()->count())->toBe(2)
        ->and($order->fresh()->status)->toBe(OrderStatus::InTransit)
        ->and($trip->fresh()->status)->toBe(TripStatus::Delivering);
});
