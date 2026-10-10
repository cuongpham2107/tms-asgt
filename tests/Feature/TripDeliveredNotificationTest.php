<?php

use App\Enums\CheckpointType;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\ShiftType;
use App\Enums\TripStatus;
use App\Enums\VehicleOwnerType;
use App\Enums\VehicleStatus;
use App\Enums\VehicleType;
use App\Filament\Resources\Trips\TripResource;
use App\Models\Area;
use App\Models\Customer;
use App\Models\DriverShift;
use App\Models\Location;
use App\Models\Order;
use App\Models\Trip;
use App\Models\TripKmReport;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Trip\TripCheckpointService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

beforeEach(function () {
    Gate::before(fn () => true);

    $this->driver = User::factory()->create([
        'name' => 'Tài xế Nguyễn Văn A',
    ]);

    $this->manager = User::factory()->create([
        'name' => 'Quản lý Điều hành',
    ]);

    $this->vehicle = Vehicle::create([
        'plate_number' => '29C-123.45',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'Công ty',
        'is_active' => true,
        'status' => VehicleStatus::On,
        'type' => VehicleOwnerType::Company,
    ]);
});

it('sends database notification to all users when trip status transitions to delivered', function () {
    $trip = Trip::create([
        'trip_code' => 'CD-2026-09-28-1',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'status' => TripStatus::Started,
    ]);

    expect(DB::table('notifications')->count())->toBe(0);

    $trip->status = TripStatus::Delivered;
    $trip->save();

    // 2 users created in beforeEach: driver & manager
    $notifications = DB::table('notifications')->get();
    expect($notifications)->toHaveCount(2);

    $userNotification = DB::table('notifications')
        ->where('notifiable_id', $this->manager->id)
        ->first();

    expect($userNotification)->not->toBeNull();

    $data = json_decode($userNotification->data, true);
    expect($data['title'])->toBe('Chuyến xe 29C-123.45 đã giao hàng')
        ->and($data['title'])->not->toContain('CD-2026-09-28-1')
        ->and($data['body'])->toContain('Tài xế Nguyễn Văn A')
        ->and($data['status'])->toBe('success')
        ->and($data['actions'])->toHaveCount(1)
        ->and($data['actions'][0]['name'])->toBe('view')
        ->and($data['actions'][0]['url'])->toContain(TripResource::getUrl('timeline', ['record' => $trip]));
});

it('does not send notification when trip status changes to a non-delivered status', function () {
    $trip = Trip::create([
        'trip_code' => 'CD-2026-09-28-2',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'status' => TripStatus::Pending,
    ]);

    $trip->status = TripStatus::Delivering;
    $trip->save();

    expect(DB::table('notifications')->count())->toBe(0);
});

it('does not send duplicate notifications when trip is already delivered and other attributes change', function () {
    $trip = Trip::create([
        'trip_code' => 'CD-2026-09-28-3',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'status' => TripStatus::Delivered,
    ]);

    // Initial creation with Delivered sent notifications
    expect(DB::table('notifications')->count())->toBe(2);

    // Update another attribute without changing status
    $trip->note = 'Cập nhật ghi chú mới';
    $trip->save();

    // Should remain 2, no duplicates
    expect(DB::table('notifications')->count())->toBe(2);
});

it('sends database notification to all users when trip is completed via checkpoint service', function () {
    $area = Area::create([
        'type' => 'HHHK',
        'code' => 'NBA',
        'name' => 'Khu vực Nội Bài',
    ]);

    $customer = Customer::create([
        'code' => 'CUST-001',
        'name' => 'Khách hàng 1',
        'is_active' => true,
    ]);

    $location = Location::create([
        'code' => 'LOC-001',
        'name' => 'Kho Nội Bài',
        'address' => 'Sân bay Nội Bài',
        'area_id' => $area->id,
        'is_active' => true,
    ]);

    $shift = DriverShift::create([
        'driver_id' => $this->driver->id,
        'vehicle_id' => $this->vehicle->id,
        'shift_type' => ShiftType::Full,
        'start_time' => now()->subHours(2),
        'status' => 'active',
    ]);

    $trip = Trip::create([
        'trip_code' => 'CD-2026-09-28-4',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'shift_id' => $shift->id,
        'status' => TripStatus::Delivering,
        'start_km' => 100,
    ]);

    $order = Order::create([
        'order_code' => 'ORD-001',
        'customer_id' => $customer->id,
        'pickup_location_id' => $location->id,
        'area_id' => $area->id,
        'created_by' => $this->manager->id,
        'trip_id' => $trip->id,
        'driver_id' => $this->driver->id,
        'vehicle_id' => $this->vehicle->id,
        'status' => OrderStatus::InTransit,
        'order_type' => OrderType::Hhhk,
    ]);

    expect(DB::table('notifications')->count())->toBe(0);

    $service = app(TripCheckpointService::class);
    $service->recordCheckpoint($trip, [
        'checkpoint_type' => CheckpointType::Completed->value,
        'km_reading' => 150,
        'occurred_at' => now()->toIso8601String(),
        'order_id' => $order->id,
    ]);

    $trip->refresh();
    expect($trip->status)->toBe(TripStatus::Delivered);
    expect(DB::table('notifications')->count())->toBe(2);
});

it('formats notification title using vehicle plate number', function () {
    $vehicle2 = Vehicle::create([
        'plate_number' => '30F-888.88',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'Đối tác',
        'is_active' => true,
        'status' => VehicleStatus::On,
        'type' => VehicleOwnerType::Rent,
    ]);

    $trip = Trip::create([
        'trip_code' => 'CD-CUSTOM-PLATE',
        'vehicle_id' => $vehicle2->id,
        'driver_id' => $this->driver->id,
        'status' => TripStatus::Delivering,
    ]);

    $trip->status = TripStatus::Delivered;
    $trip->save();

    $userNotification = DB::table('notifications')
        ->where('notifiable_id', $this->manager->id)
        ->latest('id')
        ->first();

    $data = json_decode($userNotification->data, true);
    expect($data['title'])->toBe('Chuyến xe 30F-888.88 đã giao hàng')
        ->and($data['title'])->not->toContain('CD-CUSTOM-PLATE');
});

it('sends database notification to all users when trip status transitions to driver swap', function () {
    $trip = Trip::create([
        'trip_code' => 'CD-SWAP-01',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'status' => TripStatus::Delivering,
    ]);

    expect(DB::table('notifications')->count())->toBe(0);

    $trip->status = TripStatus::DriverSwap;
    $trip->save();

    $notifications = DB::table('notifications')->get();
    expect($notifications)->toHaveCount(2);

    $userNotification = DB::table('notifications')
        ->where('notifiable_id', $this->manager->id)
        ->first();

    expect($userNotification)->not->toBeNull();

    $data = json_decode($userNotification->data, true);
    expect($data['title'])->toBe('Chuyến xe 29C-123.45 báo đảo lái')
        ->and($data['body'])->toContain('Tài xế Nguyễn Văn A đã báo đảo lái.')
        ->and($data['status'])->toBe('info')
        ->and($data['actions'])->toHaveCount(1)
        ->and($data['actions'][0]['name'])->toBe('view')
        ->and($data['actions'][0]['url'])->toContain(TripResource::getUrl('timeline', ['record' => $trip]));
});

it('sends database notification to all users when trip is created with driver swap status', function () {
    expect(DB::table('notifications')->count())->toBe(0);

    Trip::create([
        'trip_code' => 'CD-SWAP-02',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'status' => TripStatus::DriverSwap,
    ]);

    expect(DB::table('notifications')->count())->toBe(2);
});

it('sends database notification to all users when driver reports km discrepancy', function () {
    $trip = Trip::create([
        'trip_code' => 'CD-KM-01',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'status' => TripStatus::Delivering,
    ]);

    expect(DB::table('notifications')->count())->toBe(0);

    TripKmReport::create([
        'trip_id' => $trip->id,
        'driver_id' => $this->driver->id,
        'vehicle_id' => $this->vehicle->id,
        'reported_km' => 105500.5,
        'system_km' => 105000.0,
        'note' => 'Đồng hồ xe chạy nhanh hơn',
        'status' => 'pending',
    ]);

    $notifications = DB::table('notifications')->get();
    expect($notifications)->toHaveCount(2);

    $userNotification = DB::table('notifications')
        ->where('notifiable_id', $this->manager->id)
        ->first();

    expect($userNotification)->not->toBeNull();

    $data = json_decode($userNotification->data, true);
    expect($data['title'])->toBe('Chuyến xe 29C-123.45 báo sai Km')
        ->and($data['body'])->toContain('Tài xế Nguyễn Văn A báo sai lệch số Km: 105.500,5 km.')
        ->and($data['status'])->toBe('warning')
        ->and($data['actions'])->toHaveCount(1)
        ->and($data['actions'][0]['name'])->toBe('view')
        ->and($data['actions'][0]['url'])->toContain(TripResource::getUrl('timeline', ['record' => $trip]));
});
