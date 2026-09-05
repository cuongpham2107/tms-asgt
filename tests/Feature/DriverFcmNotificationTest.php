<?php

use App\Enums\DriverSwapReason;
use App\Enums\OrderStatus;
use App\Enums\ShiftType;
use App\Enums\TripStatus;
use App\Enums\VehicleOwnerType;
use App\Enums\VehicleStatus;
use App\Enums\VehicleType;
use App\Filament\Resources\Orders\Actions\CancelOrderAction;
use App\Filament\Resources\Orders\Actions\Concerns\CreatesOrderTransportCards;
use App\Filament\Resources\Orders\Actions\UnsendOrderAction;
use App\Filament\Resources\Trips\Actions\CancelTripAction;
use App\Filament\Resources\Trips\Actions\CreateEmptyRunAction;
use App\Filament\Resources\Trips\Actions\DriverSwapAction;
use App\Filament\Resources\Trips\Actions\ReassignDriverAction;
use App\Filament\Resources\Trips\Actions\ReassignTransportAction;
use App\Filament\Resources\Trips\Actions\SendTripAction;
use App\Models\Area;
use App\Models\Customer;
use App\Models\DriverShift;
use App\Models\Location;
use App\Models\Order;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Notification\DriverNotificationService;
use Filament\Schemas\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\CloudMessage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->driverRole = Role::create([
        'name' => 'driver',
        'guard_name' => 'web',
    ]);
});

test('driver can update their fcm token via api', function () {
    $driver = User::factory()->create(['fcm_token' => null]);
    $driver->assignRole($this->driverRole);
    Sanctum::actingAs($driver);

    $response = $this->postJson('/api/driver/fcm-token', [
        'fcm_token' => 'sample_fcm_token_123456789',
    ]);

    $response->assertSuccessful()
        ->assertJson([
            'success' => true,
            'message' => 'Cập nhật FCM token thành công.',
        ]);

    $driver->refresh();
    expect($driver->fcm_token)->toBe('sample_fcm_token_123456789')
        ->and($driver->fcm_token_updated_at)->not->toBeNull();
});

test('fcm token update validates input', function () {
    $driver = User::factory()->create();
    $driver->assignRole($this->driverRole);
    Sanctum::actingAs($driver);

    $response = $this->postJson('/api/driver/fcm-token', [
        'fcm_token' => '',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['fcm_token']);
});

test('driver notification service sends trip dispatched notification via firebase messaging', function () {
    $driver = User::factory()->create(['fcm_token' => 'driver_valid_token_xyz']);
    $vehicle = Vehicle::create([
        'plate_number' => '29C-111.22',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'ASGT',
        'is_active' => true,
        'status' => VehicleStatus::On,
        'type' => VehicleOwnerType::Company,
    ]);

    $trip = Trip::create([
        'trip_code' => 'TRIP-TEST-001',
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'status' => TripStatus::Pending,
    ]);

    $mockMessaging = Mockery::mock(Messaging::class);
    $mockMessaging->shouldReceive('send')
        ->once()
        ->with(Mockery::on(function (CloudMessage $message) {
            $array = $message->jsonSerialize();
            expect($array['token'])->toBe('driver_valid_token_xyz')
                ->and($array['notification']['title'])->toContain('Lệnh vận chuyển mới')
                ->and($array['data']['type'])->toBe('trip_dispatched')
                ->and($array['data']['trip_code'])->toBe('TRIP-TEST-001');

            return true;
        }));

    $service = new DriverNotificationService($mockMessaging);
    $result = $service->sendTripDispatched($trip, 2);

    expect($result)->toBeTrue();
});

test('driver notification service sends order assigned notification via firebase messaging', function () {
    $driver = User::factory()->create(['fcm_token' => 'driver_order_token_abc']);
    $vehicle = Vehicle::create([
        'plate_number' => '29C-333.44',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'ASGT',
        'is_active' => true,
        'status' => VehicleStatus::On,
        'type' => VehicleOwnerType::Company,
    ]);

    $trip = Trip::create([
        'trip_code' => 'TRIP-ORDER-002',
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'status' => TripStatus::Pending,
    ]);

    $area = Area::create(['code' => 'AREA-001', 'name' => 'Khu vực 1']);
    $customer = Customer::create(['name' => 'Khách Hàng 1', 'code' => 'CUST-001', 'is_active' => true]);

    $order = Order::create([
        'order_code' => 'ASG-9999',
        'status' => OrderStatus::Sent,
        'area_id' => $area->id,
        'customer_id' => $customer->id,
        'created_by' => $driver->id,
        'trip_id' => $trip->id,
    ]);

    $mockMessaging = Mockery::mock(Messaging::class);
    $mockMessaging->shouldReceive('send')
        ->once()
        ->with(Mockery::on(function (CloudMessage $message) {
            $array = $message->jsonSerialize();
            expect($array['token'])->toBe('driver_order_token_abc')
                ->and($array['notification']['title'])->toContain('Đơn hàng mới: ASG-9999')
                ->and($array['data']['type'])->toBe('order_sent')
                ->and($array['data']['order_code'])->toBe('ASG-9999');

            return true;
        }));

    $service = new DriverNotificationService($mockMessaging);
    $result = $service->sendOrderAssigned($order, $trip);

    expect($result)->toBeTrue();
});

test('driver notification service returns false gracefully when driver has no token', function () {
    $driver = User::factory()->create(['fcm_token' => null]);
    $vehicle = Vehicle::create([
        'plate_number' => '29C-555.66',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'ASGT',
        'is_active' => true,
        'status' => VehicleStatus::On,
        'type' => VehicleOwnerType::Company,
    ]);

    $trip = Trip::create([
        'trip_code' => 'TRIP-NOTOKEN',
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'status' => TripStatus::Pending,
    ]);

    $mockMessaging = Mockery::mock(Messaging::class);
    $mockMessaging->shouldNotReceive('send');

    $service = new DriverNotificationService($mockMessaging);
    $result = $service->sendTripDispatched($trip, 1);

    expect($result)->toBeFalse();
});

test('driver notification service resolves driver from active shift if trip driver_id is null', function () {
    $driver = User::factory()->create(['fcm_token' => 'driver_shift_token']);
    $shift = DriverShift::create([
        'driver_id' => $driver->id,
        'shift_type' => ShiftType::Full,
        'start_time' => now(),
    ]);

    $vehicle = Vehicle::create([
        'plate_number' => '29C-777.88',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'ASGT',
        'is_active' => true,
        'status' => VehicleStatus::On,
        'type' => VehicleOwnerType::Company,
    ]);

    $trip = Trip::create([
        'trip_code' => 'TRIP-SHIFT-003',
        'vehicle_id' => $vehicle->id,
        'shift_id' => $shift->id,
        'driver_id' => null,
        'status' => TripStatus::Pending,
    ]);

    $mockMessaging = Mockery::mock(Messaging::class);
    $mockMessaging->shouldReceive('send')->once()->andReturn([]);

    $service = new DriverNotificationService($mockMessaging);
    $result = $service->sendTripDispatched($trip, 1);

    expect($result)->toBeTrue();
});

test('send trip action updates order status and sends fcm notification to driver', function () {
    $area = Area::create(['code' => 'AREA-002', 'name' => 'Khu vực 2']);
    $customer = Customer::create(['name' => 'Khách Hàng 2', 'code' => 'CUST-002', 'is_active' => true]);
    $driver = User::factory()->create(['fcm_token' => 'driver_action_token']);
    $vehicle = Vehicle::create([
        'plate_number' => '29C-999.00',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'ASGT',
        'is_active' => true,
        'status' => VehicleStatus::On,
        'type' => VehicleOwnerType::Company,
    ]);

    $trip = Trip::create([
        'trip_code' => 'TRIP-ACTION-004',
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'status' => TripStatus::Pending,
    ]);

    $order1 = Order::create([
        'order_code' => 'ASG-101',
        'status' => OrderStatus::Assigned,
        'area_id' => $area->id,
        'customer_id' => $customer->id,
        'created_by' => $driver->id,
        'trip_id' => $trip->id,
    ]);
    $order2 = Order::create([
        'order_code' => 'ASG-102',
        'status' => OrderStatus::Draft,
        'area_id' => $area->id,
        'customer_id' => $customer->id,
        'created_by' => $driver->id,
        'trip_id' => $trip->id,
    ]);

    $mockMessaging = Mockery::mock(Messaging::class);
    $mockMessaging->shouldReceive('send')->once()->andReturn([]);
    app()->instance(DriverNotificationService::class, new DriverNotificationService($mockMessaging));

    $action = SendTripAction::make();
    $action->record($trip);
    $action->call();

    expect($order1->refresh()->status)->toBe(OrderStatus::Sent)
        ->and($order2->refresh()->status)->toBe(OrderStatus::Sent)
        ->and($order1->sent_at)->not->toBeNull()
        ->and($order2->sent_at)->not->toBeNull();
});

test('create single order with send_immediately sends fcm notification', function () {
    $area = Area::create(['code' => 'HN-TEST', 'name' => 'Hà Nội']);
    $customer = Customer::create(['name' => 'Khách Hàng Test', 'code' => 'CUST-001', 'is_active' => true]);
    $location = Location::create([
        'code' => 'LOC-001',
        'name' => 'Kho Test',
        'address' => 'Địa chỉ Test',
        'area_id' => $area->id,
        'is_active' => true,
    ]);

    $driver = User::factory()->create(['fcm_token' => 'driver_create_send_token']);
    $vehicle = Vehicle::create([
        'plate_number' => '29C-888.99',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'ASGT',
        'is_active' => true,
        'status' => VehicleStatus::On,
        'type' => VehicleOwnerType::Company,
    ]);

    $mockMessaging = Mockery::mock(Messaging::class);
    $mockMessaging->shouldReceive('send')->once()->andReturn([]);
    app()->instance(DriverNotificationService::class, new DriverNotificationService($mockMessaging));

    $schema = Mockery::mock(Schema::class);
    $schema->shouldReceive('getRawState')->andReturn(['deliveryPoints' => []]);

    $orderData = [
        'customer_id' => $customer->id,
        'area_id' => $area->id,
        'pickup_location_id' => $location->id,
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'send_immediately' => true,
        'delivery_points' => [
            [
                'location_id' => $location->id,
                'address' => 'Điểm giao 1',
            ],
        ],
    ];

    $order = CreatesOrderTransportCards::createSingleOrder($orderData, $schema, 'external', true, $driver->id);

    expect($order->status)->toBe(OrderStatus::Sent)
        ->and($order->sent_at)->not->toBeNull()
        ->and($order->trip_id)->not->toBeNull();
});

test('driver notification service sends expo push notification when token is exponent push token', function () {
    Http::fake([
        'https://exp.host/--/api/v2/push/send' => Http::response(['data' => ['status' => 'ok']], 200),
    ]);

    $driver = User::factory()->create(['fcm_token' => 'ExponentPushToken[mock_simulator_token_123]']);
    $vehicle = Vehicle::create([
        'plate_number' => '29C-444.55',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'ASGT',
        'is_active' => true,
        'status' => VehicleStatus::On,
        'type' => VehicleOwnerType::Company,
    ]);

    $trip = Trip::create([
        'trip_code' => 'TRIP-SIMULATOR',
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'status' => TripStatus::Pending,
    ]);

    $service = new DriverNotificationService;
    $result = $service->sendTripDispatched($trip, 1);

    expect($result)->toBeTrue();

    Http::assertSent(function (Request $request) {
        return $request->url() === 'https://exp.host/--/api/v2/push/send'
            && $request['to'] === 'ExponentPushToken[mock_simulator_token_123]'
            && str_contains($request['title'], 'Lệnh vận chuyển mới');
    });
});

test('driver notification service sends empty run push notification to driver', function () {
    $driver = User::factory()->create(['fcm_token' => 'driver_empty_run_token']);
    $vehicle = Vehicle::create([
        'plate_number' => '29C-555.66',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'ASGT',
        'is_active' => true,
        'status' => VehicleStatus::On,
        'type' => VehicleOwnerType::Company,
    ]);

    $locStart = Location::create([
        'code' => 'ALSB',
        'name' => 'ALSB',
        'loc_type' => 'pickup',
        'is_active' => true,
    ]);
    $locEnd = Location::create([
        'code' => 'ACSV',
        'name' => 'ACSV',
        'loc_type' => 'delivery',
        'is_active' => true,
    ]);

    $trip = Trip::create([
        'trip_code' => 'TRIP-EMPTY-001',
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'status' => TripStatus::ReturnTrip,
        'is_empty_run' => true,
        'start_location_id' => $locStart->id,
        'end_location_id' => $locEnd->id,
    ]);

    $mockMessaging = Mockery::mock(Messaging::class);
    $mockMessaging->shouldReceive('send')->once()->with(Mockery::on(function (CloudMessage $message) {
        $data = $message->jsonSerialize();

        return $data['token'] === 'driver_empty_run_token'
            && str_contains($data['notification']['title'], 'Chuyến không hàng mới: #TRIP-EMPTY-001')
            && str_contains($data['notification']['body'], 'ALSB → ACSV')
            && ($data['data']['type'] ?? null) === 'empty_run_dispatched'
            && ($data['data']['is_empty_run'] ?? null) === 'true';
    }))->andReturn([]);

    $service = new DriverNotificationService($mockMessaging);
    $result = $service->sendEmptyRunDispatched($trip);

    expect($result)->toBeTrue();
});

test('create empty run action sends fcm notification to driver', function () {
    $driver = User::factory()->create(['fcm_token' => 'driver_action_empty_token']);
    $vehicle = Vehicle::create([
        'plate_number' => '29C-111.22',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'ASGT',
        'is_active' => true,
        'status' => VehicleStatus::On,
        'type' => VehicleOwnerType::Company,
        'current_mileage' => 50000,
    ]);

    $locStart = Location::create([
        'code' => 'ALSB-TEST',
        'name' => 'ALSB Test',
        'loc_type' => 'pickup',
        'is_active' => true,
    ]);
    $locEnd = Location::create([
        'code' => 'ACSV-TEST',
        'name' => 'ACSV Test',
        'loc_type' => 'delivery',
        'is_active' => true,
    ]);

    $mockMessaging = Mockery::mock(Messaging::class);
    $mockMessaging->shouldReceive('send')->once()->andReturn([]);
    app()->instance(Messaging::class, $mockMessaging);

    $action = CreateEmptyRunAction::make();
    $action->call(['data' => [
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'start_location_id' => $locStart->id,
        'end_location_id' => $locEnd->id,
        'note' => 'Điều chuyển xe về bãi',
    ]]);

    $createdTrip = Trip::where('driver_id', $driver->id)->where('is_empty_run', true)->first();
    expect($createdTrip)->not->toBeNull()
        ->and($createdTrip->status)->toBe(TripStatus::ReturnTrip)
        ->and($createdTrip->is_empty_run)->toBeTrue();
});

test('driver notification service sends trip driver swapped notification to new driver and handover notification to old driver', function () {
    $oldDriver = User::factory()->create(['name' => 'Lái Xe Cũ', 'fcm_token' => 'old_driver_token']);
    $newDriver = User::factory()->create(['name' => 'Lái Xe Mới', 'fcm_token' => 'new_driver_token']);

    $vehicle = Vehicle::create([
        'plate_number' => '29C-222.33',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'ASGT',
        'is_active' => true,
        'status' => VehicleStatus::On,
        'type' => VehicleOwnerType::Company,
    ]);

    $trip = Trip::create([
        'trip_code' => 'TRIP-SWAP-001',
        'vehicle_id' => $vehicle->id,
        'driver_id' => $newDriver->id,
        'status' => TripStatus::DriverSwap,
    ]);

    $mockMessaging = Mockery::mock(Messaging::class);
    $mockMessaging->shouldReceive('send')->twice()->with(Mockery::on(function (CloudMessage $message) {
        $data = $message->jsonSerialize();
        if ($data['token'] === 'new_driver_token') {
            return str_contains($data['notification']['title'], 'Bàn giao chuyến đi: #TRIP-SWAP-001')
                && str_contains($data['notification']['body'], 'Lái Xe Cũ')
                && ($data['data']['type'] ?? null) === 'trip_driver_swapped';
        }
        if ($data['token'] === 'old_driver_token') {
            return str_contains($data['notification']['title'], 'Đã chuyển giao chuyến: #TRIP-SWAP-001')
                && str_contains($data['notification']['body'], 'Lái Xe Mới')
                && ($data['data']['type'] ?? null) === 'trip_driver_swap_handover';
        }

        return false;
    }))->andReturn([]);

    $service = new DriverNotificationService($mockMessaging);
    $resultNew = $service->sendTripDriverSwapped($trip, $newDriver, $oldDriver, 50000.5);
    $resultOld = $service->sendTripDriverSwapHandover($trip, $oldDriver, $newDriver);

    expect($resultNew)->toBeTrue()
        ->and($resultOld)->toBeTrue();
});

test('driver swap action sends fcm notification to new driver and old driver', function () {
    $oldDriver = User::factory()->create(['name' => 'Tài Xế A', 'fcm_token' => 'driver_a_token']);
    $newDriver = User::factory()->create(['name' => 'Tài Xế B', 'fcm_token' => 'driver_b_token']);
    $this->actingAs($oldDriver);

    $vehicle = Vehicle::create([
        'plate_number' => '29C-333.44',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'ASGT',
        'is_active' => true,
        'status' => VehicleStatus::Running,
        'type' => VehicleOwnerType::Company,
        'current_mileage' => 10000,
    ]);

    $trip = Trip::create([
        'trip_code' => 'TRIP-SWAP-ACT',
        'vehicle_id' => $vehicle->id,
        'driver_id' => $oldDriver->id,
        'status' => TripStatus::Delivering,
        'start_km' => 10000,
    ]);

    $mockMessaging = Mockery::mock(Messaging::class);
    $mockMessaging->shouldReceive('send')->twice()->andReturn([]);
    app()->instance(DriverNotificationService::class, new DriverNotificationService($mockMessaging));

    $action = DriverSwapAction::make();
    $action->record($trip);
    $action->call(['data' => [
        'to_driver_id' => $newDriver->id,
        'handover_km' => 10050,
        'reason' => DriverSwapReason::ShiftHandover->value,
        'note' => 'Đổi ca tại trạm',
    ]]);

    $trip->refresh();
    expect($trip->driver_id)->toBe($newDriver->id)
        ->and($trip->status)->toBe(TripStatus::DriverSwap);
});

test('reassign driver action sends fcm notification to new driver and return trip to old driver', function () {
    $oldDriver = User::factory()->create(['name' => 'Tài Xế Cũ', 'fcm_token' => 'driver_old_reassign']);
    $newDriver = User::factory()->create(['name' => 'Tài Xế Mới', 'fcm_token' => 'driver_new_reassign']);
    $this->actingAs($oldDriver);

    $vehicle1 = Vehicle::create([
        'plate_number' => '29C-444.55',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'ASGT',
        'is_active' => true,
        'status' => VehicleStatus::Running,
        'type' => VehicleOwnerType::Company,
        'current_mileage' => 20000,
    ]);
    $vehicle2 = Vehicle::create([
        'plate_number' => '29C-555.66',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'ASGT',
        'is_active' => true,
        'status' => VehicleStatus::On,
        'type' => VehicleOwnerType::Company,
        'current_mileage' => 30000,
    ]);

    $trip = Trip::create([
        'trip_code' => 'TRIP-REASSIGN-ACT',
        'vehicle_id' => $vehicle1->id,
        'driver_id' => $oldDriver->id,
        'status' => TripStatus::DriverSwap,
        'start_km' => 20000,
    ]);

    $mockMessaging = Mockery::mock(Messaging::class);
    $mockMessaging->shouldReceive('send')->twice()->andReturn([]);
    app()->instance(DriverNotificationService::class, new DriverNotificationService($mockMessaging));

    $action = ReassignDriverAction::make();
    $action->record($trip);
    $action->call(['data' => [
        'new_driver_id' => $newDriver->id,
        'handover_km' => 20050,
        'reason' => DriverSwapReason::CargoNotUnloaded->value,
        'create_return_trip' => true,
        'return_vehicle_id' => $vehicle2->id,
    ]]);

    $trip->refresh();
    expect($trip->driver_id)->toBe($newDriver->id);

    $returnTrip = Trip::where('driver_id', $oldDriver->id)->where('status', TripStatus::ReturnTrip)->first();
    expect($returnTrip)->not->toBeNull();
});

test('reassign transport action sends fcm notification to new driver and unassign to old driver', function () {
    $oldDriver = User::factory()->create(['name' => 'Tài Xế Gốc', 'fcm_token' => 'driver_orig_token']);
    $newDriver = User::factory()->create(['name' => 'Tài Xế Thay', 'fcm_token' => 'driver_repl_token']);

    $vehicle = Vehicle::create([
        'plate_number' => '29C-666.77',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'ASGT',
        'is_active' => true,
        'status' => VehicleStatus::On,
        'type' => VehicleOwnerType::Company,
    ]);

    $trip = Trip::create([
        'trip_code' => 'TRIP-REASSIGN-TRP',
        'vehicle_id' => $vehicle->id,
        'driver_id' => $oldDriver->id,
        'status' => TripStatus::Pending,
    ]);

    $mockMessaging = Mockery::mock(Messaging::class);
    $mockMessaging->shouldReceive('send')->twice()->andReturn([]);
    app()->instance(DriverNotificationService::class, new DriverNotificationService($mockMessaging));

    $action = ReassignTransportAction::make();
    $action->record($trip);
    $action->call(['data' => [
        'vehicle_id' => $vehicle->id,
        'driver_id' => $newDriver->id,
    ]]);

    $trip->refresh();
    expect($trip->driver_id)->toBe($newDriver->id);
});

test('cancel trip action sends fcm notification to assigned driver', function () {
    $driver = User::factory()->create(['name' => 'Tài Xế Chuyến', 'fcm_token' => 'driver_cancel_trip_token']);
    $vehicle = Vehicle::create([
        'plate_number' => '29C-777.88',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'ASGT',
        'is_active' => true,
        'status' => VehicleStatus::Running,
        'type' => VehicleOwnerType::Company,
        'current_mileage' => 15000,
    ]);

    $trip = Trip::create([
        'trip_code' => 'TRIP-CANCEL-ACT',
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'status' => TripStatus::Started,
        'start_km' => 15000,
    ]);

    $mockMessaging = Mockery::mock(Messaging::class);
    $mockMessaging->shouldReceive('send')->once()->with(Mockery::on(function (CloudMessage $message) {
        $data = $message->jsonSerialize();

        return $data['token'] === 'driver_cancel_trip_token'
            && str_contains($data['notification']['title'], 'Huỷ chuyến đi: #TRIP-CANCEL-ACT')
            && str_contains($data['notification']['body'], 'Hàng hóa bị hủy bởi khách')
            && ($data['data']['type'] ?? null) === 'trip_cancelled';
    }))->andReturn([]);
    app()->instance(DriverNotificationService::class, new DriverNotificationService($mockMessaging));

    $action = CancelTripAction::make();
    $action->record($trip);
    $action->call(['data' => [
        'km_reading' => 15020,
        'cancel_reason' => 'Hàng hóa bị hủy bởi khách',
    ]]);

    $trip->refresh();
    expect($trip->status)->toBe(TripStatus::Cancelled);
});

test('cancel order action sends fcm notification to driver of order', function () {
    $driver = User::factory()->create(['name' => 'Tài Xế Đơn', 'fcm_token' => 'driver_cancel_order_token']);
    $vehicle = Vehicle::create([
        'plate_number' => '29C-888.11',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'ASGT',
        'is_active' => true,
        'status' => VehicleStatus::Running,
        'type' => VehicleOwnerType::Company,
    ]);
    $trip = Trip::create([
        'trip_code' => 'TRIP-ORD-CAN',
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'status' => TripStatus::Pending,
    ]);

    $area = Area::create(['code' => 'AREA-CAN', 'name' => 'Khu vực Huỷ']);
    $customer = Customer::create(['name' => 'Khách Hàng Huỷ', 'code' => 'CUST-CAN', 'is_active' => true]);

    $order = Order::create([
        'order_code' => 'ASG-CANCEL-01',
        'status' => OrderStatus::Draft,
        'area_id' => $area->id,
        'customer_id' => $customer->id,
        'created_by' => $driver->id,
        'trip_id' => $trip->id,
    ]);

    $mockMessaging = Mockery::mock(Messaging::class);
    $mockMessaging->shouldReceive('send')->once()->with(Mockery::on(function (CloudMessage $message) {
        $data = $message->jsonSerialize();

        return $data['token'] === 'driver_cancel_order_token'
            && str_contains($data['notification']['title'], 'Huỷ đơn hàng: ASG-CANCEL-01')
            && str_contains($data['notification']['body'], 'Khách đổi ý')
            && ($data['data']['type'] ?? null) === 'order_cancelled';
    }))->andReturn([]);
    app()->instance(DriverNotificationService::class, new DriverNotificationService($mockMessaging));

    $action = CancelOrderAction::make();
    $action->record($order);
    $action->call(['data' => [
        'cancel_reason' => 'Khách đổi ý',
    ]]);

    $order->refresh();
    expect($order->status)->toBe(OrderStatus::Cancelled);
});

test('unsend order action sends fcm notification to driver of order', function () {
    $driver = User::factory()->create(['name' => 'Tài Xế Thu Hồi', 'fcm_token' => 'driver_unsend_token']);
    $vehicle = Vehicle::create([
        'plate_number' => '29C-999.22',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'ASGT',
        'is_active' => true,
        'status' => VehicleStatus::Running,
        'type' => VehicleOwnerType::Company,
    ]);
    $trip = Trip::create([
        'trip_code' => 'TRIP-ORD-UNS',
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'status' => TripStatus::Pending,
    ]);

    $area = Area::create(['code' => 'AREA-UNS', 'name' => 'Khu vực Thu Hồi']);
    $customer = Customer::create(['name' => 'Khách Hàng Thu Hồi', 'code' => 'CUST-UNS', 'is_active' => true]);

    $order = Order::create([
        'order_code' => 'ASG-UNSEND-01',
        'status' => OrderStatus::Sent,
        'area_id' => $area->id,
        'customer_id' => $customer->id,
        'created_by' => $driver->id,
        'trip_id' => $trip->id,
        'sent_at' => now(),
    ]);

    $mockMessaging = Mockery::mock(Messaging::class);
    $mockMessaging->shouldReceive('send')->once()->with(Mockery::on(function (CloudMessage $message) {
        $data = $message->jsonSerialize();

        return $data['token'] === 'driver_unsend_token'
            && str_contains($data['notification']['title'], 'Thu hồi lệnh đơn hàng: ASG-UNSEND-01')
            && ($data['data']['type'] ?? null) === 'order_recalled';
    }))->andReturn([]);
    app()->instance(DriverNotificationService::class, new DriverNotificationService($mockMessaging));

    $action = UnsendOrderAction::make();
    $action->record($order);
    $action->call();

    $order->refresh();
    expect($order->status)->toBe(OrderStatus::Assigned);
});
