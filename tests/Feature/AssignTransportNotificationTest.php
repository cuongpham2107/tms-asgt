<?php

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\VehicleOwnerType;
use App\Enums\VehicleStatus;
use App\Enums\VehicleType;
use App\Filament\Resources\Orders\Actions\AssignTransportAction;
use App\Models\Area;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Order;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Notification\DriverNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Kreait\Firebase\Contract\Messaging;

uses(RefreshDatabase::class);

beforeEach(function () {
    Gate::before(fn () => true);

    $this->driver = User::factory()->create([
        'name' => 'Tài Xế Test',
        'fcm_token' => 'driver_fcm_token_123',
    ]);

    $this->area = Area::create([
        'type' => 'HHHK',
        'code' => 'NBA',
        'name' => 'Khu vực Nội Bài',
    ]);

    $this->customer = Customer::create([
        'code' => 'CUST-001',
        'name' => 'Customer 1',
        'is_active' => true,
    ]);

    $this->location = Location::create([
        'code' => 'LOC-001',
        'name' => 'Kho Nội Bài',
        'address' => 'Sân bay Nội Bài',
        'area_id' => $this->area->id,
        'is_active' => true,
    ]);

    $this->rentVehicle = Vehicle::create([
        'plate_number' => '29C-999.88',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'Đối tác thuê ngoài',
        'is_active' => true,
        'status' => VehicleStatus::On,
        'type' => VehicleOwnerType::Rent,
    ]);

    $this->companyVehicle = Vehicle::create([
        'plate_number' => '29C-111.22',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'ASGT',
        'is_active' => true,
        'status' => VehicleStatus::On,
        'type' => VehicleOwnerType::Company,
    ]);
});

test('assigning rent vehicle sends notification and sets status to Sent', function () {
    $order = Order::create([
        'order_code' => 'ORD-TEST-001',
        'type' => OrderType::Hhhk,
        'status' => OrderStatus::Draft,
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'pickup_location_id' => $this->location->id,
        'created_by' => $this->driver->id,
    ]);

    $mockMessaging = Mockery::mock(Messaging::class);
    // Notification MUST be sent once
    $mockMessaging->shouldReceive('send')->once()->andReturn([]);
    app()->instance(DriverNotificationService::class, new DriverNotificationService($mockMessaging));

    $action = AssignTransportAction::make();
    $action->record($order);
    $action->call([
        'data' => [
            'vehicle_id' => $this->rentVehicle->id,
            'driver_id' => $this->driver->id,
        ],
    ]);

    $order->refresh();
    expect($order->status)->toBe(OrderStatus::Sent)
        ->and($order->sent_at)->not->toBeNull()
        ->and($order->trip_id)->not->toBeNull();
});

test('assigning company vehicle sends notification and sets status to Sent', function () {
    $order = Order::create([
        'order_code' => 'ORD-TEST-002',
        'type' => OrderType::Hhhk,
        'status' => OrderStatus::Draft,
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'pickup_location_id' => $this->location->id,
        'created_by' => $this->driver->id,
    ]);

    $mockMessaging = Mockery::mock(Messaging::class);
    // Notification MUST be sent once
    $mockMessaging->shouldReceive('send')->once()->andReturn([]);
    app()->instance(DriverNotificationService::class, new DriverNotificationService($mockMessaging));

    $action = AssignTransportAction::make();
    $action->record($order);
    $action->call([
        'data' => [
            'vehicle_id' => $this->companyVehicle->id,
            'driver_id' => $this->driver->id,
        ],
    ]);

    $order->refresh();
    expect($order->status)->toBe(OrderStatus::Sent)
        ->and($order->sent_at)->not->toBeNull()
        ->and($order->trip_id)->not->toBeNull();
});
