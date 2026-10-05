<?php

use App\Enums\AssignmentEndReason;
use App\Enums\CheckpointType;
use App\Enums\OrderDeliveryPointStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\TripStatus;
use App\Models\Area;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Order;
use App\Models\OrderDeliveryPoint;
use App\Models\Trip;
use App\Models\TripCheckpoint;
use App\Models\TripDriverAssignment;
use App\Models\TripLeg;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Trip\TripLegService;
use App\Services\TripKmCalculatorService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->t0 = CarbonImmutable::parse('2026-10-05 08:00:00');
    $this->vehicle = Vehicle::factory()->create();
    $this->driver = User::factory()->create();
    $this->area = Area::create(['type' => OrderType::Hhhk, 'code' => 'TEST_AREA', 'name' => 'Area Test']);
    $this->customer = Customer::create(['code' => 'CUST_TEST', 'name' => 'Customer Test', 'is_active' => true]);

    $this->pickupLoc = Location::create([
        'code' => 'KHO_A',
        'name' => 'Kho Xuất A',
        'lat' => 21.1993477,
        'lng' => 105.9945700,
        'area_id' => $this->area->id,
    ]);

    $this->delivLoc = Location::create([
        'code' => 'KHO_B',
        'name' => 'Điểm Giao B',
        'lat' => 21.2188925,
        'lng' => 105.8044596,
        'area_id' => $this->area->id,
    ]);
});

test('calculateLegs returns correct legs with fallback road distance', function () {
    $trip = Trip::factory()->create([
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'status' => TripStatus::Completed,
        'started_at' => $this->t0,
        'completed_at' => $this->t0->addMinutes(30),
    ]);

    $order = Order::create([
        'order_code' => 'ORD-LEG-01',
        'type' => OrderType::Hhhk,
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'trip_id' => $trip->id,
        'pickup_location_id' => $this->pickupLoc->id,
        'pickup_address' => 'Kho Xuất A',
        'status' => OrderStatus::Completed,
        'created_by' => $this->driver->id,
    ]);

    $dp = OrderDeliveryPoint::create([
        'order_id' => $order->id,
        'location_id' => $this->delivLoc->id,
        'address' => 'Điểm Giao B',
        'sequence' => 1,
        'status' => 'delivered',
    ]);

    TripCheckpoint::create([
        'trip_id' => $trip->id,
        'checkpoint_type' => CheckpointType::Started->value,
        'occurred_at' => $this->t0,
        'gps_lat' => 21.1993477,
        'gps_lng' => 105.9945700,
        'driver_id' => $this->driver->id,
    ]);

    TripCheckpoint::create([
        'trip_id' => $trip->id,
        'order_id' => $order->id,
        'checkpoint_type' => CheckpointType::ArrivedPickup->value,
        'occurred_at' => $this->t0->addMinutes(5),
        'gps_lat' => 21.1993477,
        'gps_lng' => 105.9945700,
        'driver_id' => $this->driver->id,
    ]);

    TripCheckpoint::create([
        'trip_id' => $trip->id,
        'order_id' => $order->id,
        'delivery_point_id' => $dp->id,
        'checkpoint_type' => CheckpointType::ArrivedDelivery->value,
        'occurred_at' => $this->t0->addMinutes(25),
        'gps_lat' => 21.2188925,
        'gps_lng' => 105.8044596,
        'driver_id' => $this->driver->id,
    ]);

    TripCheckpoint::create([
        'trip_id' => $trip->id,
        'order_id' => $order->id,
        'delivery_point_id' => $dp->id,
        'checkpoint_type' => CheckpointType::Completed->value,
        'occurred_at' => $this->t0->addMinutes(30),
        'gps_lat' => 21.2188925,
        'gps_lng' => 105.8044596,
        'driver_id' => $this->driver->id,
    ]);

    /** @var TripLegService $service */
    $service = app(TripLegService::class);
    $legs = $service->calculateLegs($trip);

    expect($legs)->not->toBeEmpty();
    $loadedLeg = collect($legs)->firstWhere('is_loaded', true);
    expect($loadedLeg)->not->toBeNull()
        ->and($loadedLeg['distance_km'])->toBeGreaterThan(15.0)
        ->and($loadedLeg['source'])->toBeIn(['osrm', 'haversine', 'phone_gps']);

    $cpDistances = $service->checkpointDistances($trip);
    expect($cpDistances)->toBeArray();
});

test('legs from ArrivedPickup to Completed are marked as loaded, others as empty', function () {
    $trip = Trip::factory()->create([
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'status' => TripStatus::Completed,
        'started_at' => $this->t0,
        'completed_at' => $this->t0->addMinutes(45),
    ]);

    $order = Order::create([
        'order_code' => 'ORD-LEG-02',
        'type' => OrderType::Hhhk,
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'trip_id' => $trip->id,
        'pickup_location_id' => $this->pickupLoc->id,
        'pickup_address' => 'Kho Xuất A',
        'status' => OrderStatus::Completed,
        'created_by' => $this->driver->id,
    ]);

    $dp = OrderDeliveryPoint::create([
        'order_id' => $order->id,
        'location_id' => $this->delivLoc->id,
        'address' => 'Điểm Giao B',
        'sequence' => 1,
        'status' => 'delivered',
    ]);

    // 1. Started
    TripCheckpoint::create([
        'trip_id' => $trip->id,
        'checkpoint_type' => CheckpointType::Started->value,
        'occurred_at' => $this->t0,
        'gps_lat' => 21.0000,
        'gps_lng' => 105.8000,
        'driver_id' => $this->driver->id,
    ]);

    // 2. ArrivedPickup
    TripCheckpoint::create([
        'trip_id' => $trip->id,
        'order_id' => $order->id,
        'checkpoint_type' => CheckpointType::ArrivedPickup->value,
        'occurred_at' => $this->t0->addMinutes(10),
        'gps_lat' => 21.1993477,
        'gps_lng' => 105.9945700,
        'driver_id' => $this->driver->id,
    ]);

    // 3. LeftPickup
    TripCheckpoint::create([
        'trip_id' => $trip->id,
        'order_id' => $order->id,
        'checkpoint_type' => CheckpointType::LeftPickup->value,
        'occurred_at' => $this->t0->addMinutes(15),
        'gps_lat' => 21.1993477,
        'gps_lng' => 105.9945700,
        'driver_id' => $this->driver->id,
    ]);

    // 4. ArrivedDelivery
    TripCheckpoint::create([
        'trip_id' => $trip->id,
        'order_id' => $order->id,
        'delivery_point_id' => $dp->id,
        'checkpoint_type' => CheckpointType::ArrivedDelivery->value,
        'occurred_at' => $this->t0->addMinutes(35),
        'gps_lat' => 21.2188925,
        'gps_lng' => 105.8044596,
        'driver_id' => $this->driver->id,
    ]);

    // 5. Completed
    TripCheckpoint::create([
        'trip_id' => $trip->id,
        'order_id' => $order->id,
        'delivery_point_id' => $dp->id,
        'checkpoint_type' => CheckpointType::Completed->value,
        'occurred_at' => $this->t0->addMinutes(40),
        'gps_lat' => 21.2188925,
        'gps_lng' => 105.8044596,
        'driver_id' => $this->driver->id,
    ]);

    // 6. End
    TripCheckpoint::create([
        'trip_id' => $trip->id,
        'checkpoint_type' => CheckpointType::End->value,
        'occurred_at' => $this->t0->addMinutes(45),
        'gps_lat' => 21.0000,
        'gps_lng' => 105.8000,
        'driver_id' => $this->driver->id,
    ]);

    /** @var TripLegService $service */
    $service = app(TripLegService::class);
    $legs = $service->calculateLegs($trip);

    expect($legs)->toHaveCount(5);
    // Chặng 1: Bắt đầu -> Đến lấy hàng => Xe rỗng
    expect($legs[0]['is_loaded'])->toBeFalse();
    // Chặng 2: Đến lấy hàng -> Rời lấy hàng => Có hàng
    expect($legs[1]['is_loaded'])->toBeTrue();
    // Chặng 3: Rời lấy hàng -> Đến giao hàng => Có hàng
    expect($legs[2]['is_loaded'])->toBeTrue();
    // Chặng 4: Đến giao hàng -> Hoàn thành => Có hàng
    expect($legs[3]['is_loaded'])->toBeTrue();
    // Chặng 5: Hoàn thành -> Kết thúc => Xe rỗng
    expect($legs[4]['is_loaded'])->toBeFalse();
});

test('driver swap in OSRM fallback correctly distributes loaded and empty km to each assignment', function () {
    $driver1 = User::factory()->create();
    $driver2 = User::factory()->create();

    $trip = Trip::factory()->create([
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $driver2->id,
        'status' => TripStatus::Completed,
        'started_at' => $this->t0,
        'completed_at' => $this->t0->addMinutes(50),
    ]);

    // Driver 1: phút 0 đến 25
    $da1 = TripDriverAssignment::create([
        'trip_id' => $trip->id,
        'driver_id' => $driver1->id,
        'started_at' => $this->t0,
        'ended_at' => $this->t0->addMinutes(25),
        'end_reason' => AssignmentEndReason::Reassigned,
    ]);

    // Driver 2: phút 25 đến 50
    $da2 = TripDriverAssignment::create([
        'trip_id' => $trip->id,
        'driver_id' => $driver2->id,
        'started_at' => $this->t0->addMinutes(25),
        'ended_at' => $this->t0->addMinutes(50),
        'end_reason' => AssignmentEndReason::TripFinished,
    ]);

    $order = Order::create([
        'order_code' => 'ORD-SWAP-FALLBACK',
        'type' => OrderType::Hhhk,
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'trip_id' => $trip->id,
        'pickup_location_id' => $this->pickupLoc->id,
        'pickup_address' => 'Kho Xuất A',
        'status' => OrderStatus::Completed,
        'created_by' => $driver1->id,
    ]);

    $dp = OrderDeliveryPoint::create([
        'order_id' => $order->id,
        'location_id' => $this->delivLoc->id,
        'address' => 'Điểm Giao B',
        'sequence' => 1,
        'status' => 'delivered',
    ]);

    // 1. Started (phút 0, Driver 1)
    TripCheckpoint::create([
        'trip_id' => $trip->id,
        'checkpoint_type' => CheckpointType::Started->value,
        'occurred_at' => $this->t0,
        'gps_lat' => 21.0000,
        'gps_lng' => 105.8000,
        'driver_id' => $driver1->id,
    ]);

    // 2. ArrivedPickup (phút 10, Driver 1)
    TripCheckpoint::create([
        'trip_id' => $trip->id,
        'order_id' => $order->id,
        'checkpoint_type' => CheckpointType::ArrivedPickup->value,
        'occurred_at' => $this->t0->addMinutes(10),
        'gps_lat' => 21.1993477,
        'gps_lng' => 105.9945700,
        'driver_id' => $driver1->id,
    ]);

    // 3. DriverSwap (phút 25: Driver 1 swap to Driver 2 TRONG KHI ĐÃ CÓ HÀNG)
    TripCheckpoint::create([
        'trip_id' => $trip->id,
        'order_id' => $order->id,
        'checkpoint_type' => CheckpointType::DriverSwap->value,
        'occurred_at' => $this->t0->addMinutes(25),
        'gps_lat' => 21.2050,
        'gps_lng' => 105.9000,
        'driver_id' => $driver1->id,
    ]);

    // 4. Completed (phút 40, Driver 2)
    TripCheckpoint::create([
        'trip_id' => $trip->id,
        'order_id' => $order->id,
        'delivery_point_id' => $dp->id,
        'checkpoint_type' => CheckpointType::Completed->value,
        'occurred_at' => $this->t0->addMinutes(40),
        'gps_lat' => 21.2188925,
        'gps_lng' => 105.8044596,
        'driver_id' => $driver2->id,
    ]);

    // 5. End (phút 50, Driver 2)
    TripCheckpoint::create([
        'trip_id' => $trip->id,
        'checkpoint_type' => CheckpointType::End->value,
        'occurred_at' => $this->t0->addMinutes(50),
        'gps_lat' => 21.0000,
        'gps_lng' => 105.8000,
        'driver_id' => $driver2->id,
    ]);

    // Tính km (chưa có điểm GPS -> tự động dùng OSRM fallback)
    app(TripKmCalculatorService::class)->calculate($trip);

    $da1->refresh();
    $da2->refresh();

    // Driver 1: lái chặng 1 (Bắt đầu -> Đến lấy hàng: Xe rỗng) và chặng 2 (Đến lấy hàng -> Đảo lái: Có hàng)
    expect((float) $da1->km)->toBeGreaterThan(0.0)
        ->and((float) $da1->km_loaded)->toBeGreaterThan(0.0)
        ->and((float) $da1->km_empty)->toBeGreaterThan(0.0);

    // Driver 2: lái chặng 3 (Đảo lái -> Hoàn thành: Có hàng) và chặng 4 (Hoàn thành -> Kết thúc: Xe rỗng)
    expect((float) $da2->km)->toBeGreaterThan(0.0)
        ->and((float) $da2->km_loaded)->toBeGreaterThan(0.0)
        ->and((float) $da2->km_empty)->toBeGreaterThan(0.0);
});

test('syncLegs persists legs to trip_legs table and preserves adjustments', function () {
    $trip = Trip::factory()->create([
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'status' => TripStatus::Completed,
        'started_at' => $this->t0,
        'completed_at' => $this->t0->addMinutes(40),
    ]);

    $order = Order::create([
        'order_code' => 'ORD-PERSIST-01',
        'type' => OrderType::Hhhk,
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'trip_id' => $trip->id,
        'pickup_location_id' => $this->pickupLoc->id,
        'pickup_address' => 'Kho Xuất A',
        'status' => OrderStatus::Completed,
        'created_by' => $this->driver->id,
    ]);

    $dp = OrderDeliveryPoint::create([
        'order_id' => $order->id,
        'location_id' => $this->delivLoc->id,
        'address' => 'Điểm Giao B',
        'sequence' => 1,
        'status' => OrderDeliveryPointStatus::Delivered,
    ]);

    TripCheckpoint::create([
        'trip_id' => $trip->id,
        'checkpoint_type' => CheckpointType::Started->value,
        'occurred_at' => $this->t0,
        'gps_lat' => 21.0000,
        'gps_lng' => 105.8000,
    ]);

    TripCheckpoint::create([
        'trip_id' => $trip->id,
        'order_id' => $order->id,
        'checkpoint_type' => CheckpointType::ArrivedPickup->value,
        'occurred_at' => $this->t0->addMinutes(15),
        'gps_lat' => 21.1993477,
        'gps_lng' => 105.9945700,
    ]);

    TripCheckpoint::create([
        'trip_id' => $trip->id,
        'order_id' => $order->id,
        'delivery_point_id' => $dp->id,
        'checkpoint_type' => CheckpointType::Completed->value,
        'occurred_at' => $this->t0->addMinutes(40),
        'gps_lat' => 21.2188925,
        'gps_lng' => 105.8044596,
    ]);

    $service = app(TripLegService::class);
    $legs = $service->calculateLegs($trip);

    expect(count($legs))->toBe(2)
        ->and(TripLeg::where('trip_id', $trip->id)->count())->toBe(2);

    $dbLeg1 = TripLeg::where('trip_id', $trip->id)->where('leg_index', 1)->first();
    $dbLeg2 = TripLeg::where('trip_id', $trip->id)->where('leg_index', 2)->first();

    expect($dbLeg1)->not->toBeNull()
        ->and($dbLeg1->is_loaded)->toBeFalse()
        ->and($dbLeg2->is_loaded)->toBeTrue()
        ->and((float) $dbLeg1->distance_km)->toBeGreaterThan(0.0)
        ->and((float) $dbLeg2->distance_km)->toBeGreaterThan(0.0);

    // Điều hành sửa km chặng 2 (ví dụ tăng thêm 5km do đường vòng)
    $dispatcher = User::factory()->create();
    $service->adjustLegs(
        $trip,
        [
            ['leg_index' => 2, 'distance_adjusted_km' => (float) $dbLeg2->distance_km + 5.0, 'adjust_reason' => 'Đi đường vòng tránh kẹt xe'],
        ],
        'Điều chỉnh km chặng',
        $dispatcher->id
    );

    $dbLeg2->refresh();
    expect((float) $dbLeg2->distance_adjusted_km)->toBe(round((float) $dbLeg2->distance_km + 5.0, 1))
        ->and($dbLeg2->adjust_reason)->toBe('Đi đường vòng tránh kẹt xe')
        ->and($dbLeg2->adjusted_by)->toBe($dispatcher->id);

    // Kiểm tra Trip km_adjusted được cập nhật tự động
    $trip->refresh();
    $expectedTotal = round((float) $dbLeg1->distance_km + (float) $dbLeg2->distance_adjusted_km, 1);
    $expectedLoaded = round((float) $dbLeg2->distance_adjusted_km, 1);

    expect((float) $trip->km_adjusted)->toBe($expectedTotal)
        ->and((float) $trip->km_adjusted_loaded)->toBe($expectedLoaded)
        ->and($trip->km_adjust_reason)->toBe('Điều chỉnh km chặng')
        ->and($trip->km_adjusted_by)->toBe($dispatcher->id);

    // Chạy lại syncLegs: bảo đảm KHÔNG ghi đè số km đã điều chỉnh
    $service->syncLegs($trip);
    $dbLeg2->refresh();
    expect((float) $dbLeg2->distance_adjusted_km)->toBe(round((float) $dbLeg2->distance_km + 5.0, 1));

    // calculateLegs trả về distance_km là số đã điều chỉnh
    $freshLegs = $service->calculateLegs($trip);
    expect($freshLegs[1]['distance_km'])->toBe((float) $dbLeg2->distance_adjusted_km)
        ->and($freshLegs[1]['original_distance_km'])->toBe((float) $dbLeg2->distance_km)
        ->and($freshLegs[1]['is_adjusted'])->toBeTrue();
});
