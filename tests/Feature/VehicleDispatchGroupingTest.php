<?php

use App\Enums\OrderStatus;
use App\Enums\TripStatus;
use App\Enums\VehicleOwnerType;
use App\Filament\Pages\VehicleDispatchBoard;
use App\Models\Area;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Order;
use App\Models\OrderDeliveryPoint;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function dispatchVehicle(Vehicle $vehicle): Vehicle
{
    return $vehicle->load([
        'trips.orders.deliveryPoints.location.area',
        'trips.orders.pickupLocation',
        'trips.endLocation.area',
        'trips.startLocation',
        'trips.checkpoints',
    ]);
}

function dispatchOrder(Trip $trip, Location $pickup, Location $delivery, string $code, ?string $plannedAt = null, int $sequence = 1): Order
{
    $area = Area::firstOrCreate(['code' => 'TST-AREA'], ['name' => 'Khu vực test']);
    $customer = Customer::firstOrCreate(['code' => 'TST-CUS'], ['name' => 'Khách test']);

    $order = Order::create([
        'order_code' => $code,
        'area_id' => $area->id,
        'customer_id' => $customer->id,
        'created_by' => User::factory()->create()->id,
        'trip_id' => $trip->id,
        'pickup_location_id' => $pickup->id,
        'pickup_address' => $pickup->address,
        'planned_loading_at' => $plannedAt,
        'status' => OrderStatus::InTransit,
    ]);

    OrderDeliveryPoint::create([
        'order_id' => $order->id,
        'location_id' => $delivery->id,
        'sequence' => $sequence,
        'address' => $delivery->address,
    ]);

    return $order;
}

function dispatchLocation(string $code, string $address, ?string $areaCode = null): Location
{
    $areaId = null;

    if ($areaCode !== null) {
        $areaId = Area::firstOrCreate(['code' => $areaCode], ['name' => 'Khu vực '.$areaCode])->id;
    }

    return Location::create([
        'code' => $code,
        'name' => $code,
        'address' => $address,
        'is_active' => true,
        'area_id' => $areaId,
    ]);
}

function dispatchTrip(Vehicle $vehicle, TripStatus $status = TripStatus::Delivering, array $overrides = []): Trip
{
    return Trip::factory()->create(array_merge([
        'vehicle_id' => $vehicle->id,
        'status' => $status,
        'started_at' => now()->subHour(),
    ], $overrides));
}

function dispatchVehicleWithGps(string $plate, ?string $gpsAddress, ?float $lat = null, ?float $lng = null): Vehicle
{
    return Vehicle::factory()->create([
        'plate_number' => $plate,
        'type' => VehicleOwnerType::Company,
        'is_active' => true,
        'gps_address' => $gpsAddress,
        'gps_lat' => $lat,
        'gps_lng' => $lng,
    ]);
}

test('gộp theo điểm đến của đơn dù GPS ở nhóm khác', function () {
    $pickup = dispatchLocation('PICK-NB', 'Sóc Sơn, Hà Nội');
    $deliveryBN = dispatchLocation('DEL-BN', 'Yên Phong, Bắc Ninh');

    $vehicle = dispatchVehicleWithGps('29C-GROUP-01', 'Nội Bài, Hà Nội');
    $trip = dispatchTrip($vehicle);
    dispatchOrder($trip, $pickup, $deliveryBN, 'ORD-GROUP-01');

    expect(VehicleDispatchBoard::resolveVehicleLocationGroup(dispatchVehicle($vehicle)))->toBe('BN');
});

test('không có điểm đến mới dùng GPS', function () {
    $vehicle = dispatchVehicleWithGps('29C-GROUP-02', 'Nến, Bắc Ninh');
    dispatchTrip($vehicle, TripStatus::Started, ['end_location_id' => null]);

    expect(VehicleDispatchBoard::resolveVehicleLocationGroup(dispatchVehicle($vehicle)))->toBe('BN');
});

test('chuyến đang giao ưu tiên hơn chuyến pending có id lớn hơn', function () {
    $pickup = dispatchLocation('PICK-NB', 'Sóc Sơn, Hà Nội');
    $deliveryBN = dispatchLocation('DEL-BN', 'Yên Phong, Bắc Ninh');
    $deliveryNB = dispatchLocation('DEL-NB', 'Sóc Sơn, Hà Nội');

    $vehicle = dispatchVehicleWithGps('29C-GROUP-03', 'Bắc Ninh');

    // Chuyến đang giao đến Bắc Ninh
    $deliveringTrip = dispatchTrip($vehicle);
    dispatchOrder($deliveringTrip, $pickup, $deliveryBN, 'ORD-GROUP-03A');

    // Chuyến chờ có id lớn hơn, điểm đến Nội Bài -> không được dùng để gom nhóm
    $pendingTrip = dispatchTrip($vehicle, TripStatus::Pending, ['started_at' => null]);
    dispatchOrder($pendingTrip, $pickup, $deliveryNB, 'ORD-GROUP-03B');

    expect($deliveringTrip->id)->toBeLessThan($pendingTrip->id)
        ->and(VehicleDispatchBoard::resolveVehicleLocationGroup(dispatchVehicle($vehicle)))->toBe('BN');
});

test('chuyến nhiều đơn lấy điểm giao của đơn cuối cùng', function () {
    $pickup = dispatchLocation('PICK-NB', 'Sóc Sơn, Hà Nội');
    $deliveryNB = dispatchLocation('DEL-NB', 'Sóc Sơn, Hà Nội');
    $deliveryBN = dispatchLocation('DEL-BN', 'Yên Phong, Bắc Ninh');

    $vehicle = dispatchVehicleWithGps('29C-GROUP-04', 'Nội Bài, Hà Nội');
    $trip = dispatchTrip($vehicle);

    dispatchOrder($trip, $pickup, $deliveryNB, 'ORD-GROUP-04A', now()->subHours(2)->toDateTimeString());
    dispatchOrder($trip, $pickup, $deliveryBN, 'ORD-GROUP-04B', now()->subHour()->toDateTimeString());

    expect(VehicleDispatchBoard::resolveVehicleLocationGroup(dispatchVehicle($vehicle)))->toBe('BN');
});

test('không có điểm giao thì dùng điểm kết thúc chuyến', function () {
    $endBN = dispatchLocation('END-BN', 'Yên Phong, Bắc Ninh');

    $vehicle = dispatchVehicleWithGps('29C-GROUP-05', 'Nội Bài, Hà Nội');
    dispatchTrip($vehicle, TripStatus::Delivering, ['end_location_id' => $endBN->id]);

    expect(VehicleDispatchBoard::resolveVehicleLocationGroup(dispatchVehicle($vehicle)))->toBe('BN');
});

test('có điểm đến nhưng địa chỉ không nhận diện được thì gom Ngoại tỉnh, không dùng GPS', function () {
    $pickup = dispatchLocation('PICK-NB', 'Sóc Sơn, Hà Nội');
    $deliveryUnknown = dispatchLocation('DEL-UNKNOWN', 'Khu công nghiệp ABC');

    $vehicle = dispatchVehicleWithGps('29C-GROUP-06', 'Nến, Bắc Ninh');
    $trip = dispatchTrip($vehicle);
    dispatchOrder($trip, $pickup, $deliveryUnknown, 'ORD-GROUP-06');

    expect(VehicleDispatchBoard::resolveVehicleLocationGroup(dispatchVehicle($vehicle)))->toBe('Province');
});
