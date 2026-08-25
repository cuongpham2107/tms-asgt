<?php

use App\Enums\OrderStatus;
use App\Enums\ShiftType;
use App\Enums\TripStatus;
use App\Enums\VehicleOwnerType;
use App\Enums\VehicleStatus;
use App\Enums\VehicleType;
use App\Filament\Resources\Orders\Actions\Concerns\CreatesOrderTransportCards;
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
