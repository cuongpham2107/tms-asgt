<?php

use App\Enums\OrderStatus;
use App\Enums\TripStatus;
use App\Filament\Resources\Trips\Actions\CopyTrackingLinkAction;
use App\Models\Area;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Order;
use App\Models\OrderDeliveryPoint;
use App\Models\Trip;
use App\Models\TripCheckpoint;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleGpsLog;
use App\Services\Trip\TripTrackingMapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * @return array{trip: Trip, driver: User, vehicle: Vehicle, pickup: Location, delivery: Location}
 */
function publicTrackingFixtures(array $tripOverrides = [], array $vehicleOverrides = []): array
{
    $area = Area::create([
        'code' => 'TST-'.Str::upper(Str::random(6)),
        'name' => 'Khu vực test',
    ]);

    $vehicle = Vehicle::factory()->create(array_merge([
        'plate_number' => '29C-12345',
        'gps_lat' => 21.0285,
        'gps_lng' => 105.8542,
        'gps_speed' => 45.5,
        'gps_address' => 'Hoàn Kiếm, Hà Nội',
        'last_gps_update' => now()->subMinutes(2),
    ], $vehicleOverrides));

    $driver = User::factory()->create([
        'name' => 'Nguyễn Văn Tài',
        'phone' => '0909123456',
        'cccd' => '001099001234',
    ]);

    $pickup = Location::create([
        'code' => 'PICK-'.Str::upper(Str::random(4)),
        'name' => 'Kho Lấy Hàng',
        'address' => 'Số 1 Lấy Hàng',
        'lat' => 21.0400,
        'lng' => 105.8500,
        'is_active' => true,
        'area_id' => $area->id,
    ]);

    $delivery = Location::create([
        'code' => 'DEL-'.Str::upper(Str::random(4)),
        'name' => 'Kho Giao Hàng',
        'address' => 'Số 10 Cầu Giấy',
        'lat' => 21.0600,
        'lng' => 105.8700,
        'is_active' => true,
        'area_id' => $area->id,
    ]);

    $trip = Trip::create(array_merge([
        'trip_code' => 'TRIP-'.Str::upper(Str::random(6)),
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'status' => TripStatus::Delivering,
        'started_at' => now()->subHours(2),
    ], $tripOverrides));

    return compact('trip', 'driver', 'vehicle', 'pickup', 'delivery');
}

function publicTrackingOrder(Trip $trip, Location $pickup, Location $delivery, array $orderOverrides = [], array $deliveryOverrides = []): Order
{
    $customer = Customer::create([
        'code' => 'CUS-'.Str::upper(Str::random(6)),
        'name' => 'Khách test',
    ]);

    $order = Order::create(array_merge([
        'order_code' => 'ORD-'.Str::upper(Str::random(5)),
        'area_id' => $pickup->area_id,
        'customer_id' => $customer->id,
        'created_by' => User::factory()->create()->id,
        'trip_id' => $trip->id,
        'pickup_location_id' => $pickup->id,
        'pickup_address' => 'Số 1 Lấy Hàng',
        'planned_loading_at' => now()->addHour(),
        'status' => OrderStatus::InTransit,
    ], $orderOverrides));

    OrderDeliveryPoint::create(array_merge([
        'order_id' => $order->id,
        'location_id' => $delivery->id,
        'sequence' => 1,
        'address' => 'Số 10 Cầu Giấy',
    ], $deliveryOverrides));

    return $order;
}

function fakeOsrmRoute(): void
{
    Http::fake([
        'router.project-osrm.org/*' => Http::response([
            'code' => 'Ok',
            'routes' => [[
                'distance' => 12000,
                'duration' => 900,
                'geometry' => [
                    'type' => 'LineString',
                    'coordinates' => [[105.85, 21.04], [105.86, 21.05], [105.87, 21.06]],
                ],
                'legs' => [],
            ]],
        ]),
    ]);
}

test('link hợp lệ hiển thị thông tin chuyến và các điểm dừng', function () {
    fakeOsrmRoute();

    ['trip' => $trip, 'pickup' => $pickup, 'delivery' => $delivery] = publicTrackingFixtures();
    publicTrackingOrder($trip, $pickup, $delivery);

    $this->get(CopyTrackingLinkAction::makeUrl($trip))
        ->assertOk()
        ->assertDontSee($trip->trip_code)
        ->assertSee('Biển số xe')
        ->assertSee('29C-12345')
        ->assertSee('Nguyễn Văn Tài')
        ->assertSee('Kho Lấy Hàng')
        ->assertSee('Kho Giao Hàng')
        ->assertSee('Đang giao')
        ->assertSee('<title>Theo dõi xe 29C-12345 — ASG Vận Tải</title>', escape: false);
});

test('trang theo dõi không lộ số điện thoại và CCCD tài xế', function () {
    fakeOsrmRoute();

    ['trip' => $trip, 'pickup' => $pickup, 'delivery' => $delivery] = publicTrackingFixtures();
    publicTrackingOrder($trip, $pickup, $delivery);

    $this->get(CopyTrackingLinkAction::makeUrl($trip))
        ->assertOk()
        ->assertDontSee('0909123456')
        ->assertDontSee('001099001234');
});

test('url không có chữ ký bị từ chối', function () {
    ['trip' => $trip] = publicTrackingFixtures();

    $this->get(route('trips.public-tracking', $trip))
        ->assertForbidden()
        ->assertSee('Link theo dõi không còn hiệu lực');
});

test('url bị sửa id chuyến bị từ chối', function () {
    ['trip' => $trip] = publicTrackingFixtures();

    $otherTrip = Trip::create([
        'trip_code' => 'OTHER-'.Str::upper(Str::random(5)),
        'vehicle_id' => $trip->vehicle_id,
        'status' => TripStatus::Started,
    ]);

    $url = CopyTrackingLinkAction::makeUrl($trip);
    $tampered = str_replace('/theo-doi/'.$trip->id, '/theo-doi/'.$otherTrip->id, $url);

    $this->get($tampered)->assertForbidden();
});

test('link hết hạn bị từ chối', function () {
    ['trip' => $trip] = publicTrackingFixtures();

    $url = CopyTrackingLinkAction::makeUrl($trip);

    $this->travel(4)->days();

    $this->get($url)->assertForbidden();
});

test('link vẫn mở được khi app chat gắn thêm tham số theo dõi', function (string $extraQuery) {
    ['trip' => $trip] = publicTrackingFixtures();

    $url = CopyTrackingLinkAction::makeUrl($trip);

    $positionUrl = URL::temporarySignedRoute('trips.public-tracking.position', now()->addDay(), ['trip' => $trip]);

    $this->get($url.'&'.$extraQuery)->assertOk();
    $this->get($positionUrl.'&'.$extraQuery)->assertOk();
})->with([
    'zalo' => ['zarsrc=31&utm_source=zalo&utm_medium=zalo&utm_campaign=zalo'],
    'facebook' => ['fbclid=IwAR0abc123'],
]);

test('sửa hạn dùng trên link vẫn bị từ chối', function () {
    ['trip' => $trip] = publicTrackingFixtures();

    $url = CopyTrackingLinkAction::makeUrl($trip);
    $tampered = preg_replace('/expires=\d+/', 'expires='.now()->addYear()->timestamp, $url);

    $this->get($tampered)->assertForbidden();
});

test('endpoint vị trí trả json đúng hợp đồng và không cache', function () {
    ['trip' => $trip] = publicTrackingFixtures();

    $url = URL::temporarySignedRoute(
        'trips.public-tracking.position',
        now()->addHour(),
        ['trip' => $trip],
    );

    $response = $this->getJson($url)
        ->assertOk()
        ->assertJsonPath('has_position', true)
        ->assertJsonPath('is_finished', false)
        ->assertJsonPath('status_label', 'Đang giao')
        ->assertJsonPath('position.latitude', 21.0285)
        ->assertJsonPath('position.longitude', 105.8542)
        ->assertJsonPath('position.speed_kmh', 45.5)
        ->assertJsonPath('position.is_stale', false);

    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

test('endpoint vị trí trả has_position false khi xe chưa có gps', function () {
    ['trip' => $trip] = publicTrackingFixtures([], [
        'gps_lat' => null,
        'gps_lng' => null,
        'gps_speed' => null,
        'gps_address' => null,
        'last_gps_update' => null,
    ]);

    $url = URL::temporarySignedRoute(
        'trips.public-tracking.position',
        now()->addHour(),
        ['trip' => $trip],
    );

    $this->getJson($url)
        ->assertOk()
        ->assertJsonPath('has_position', false)
        ->assertJsonPath('position', null);
});

test('gps cũ hơn 15 phút được đánh dấu stale', function () {
    ['trip' => $trip] = publicTrackingFixtures([], [
        'last_gps_update' => now()->subMinutes(30),
    ]);

    $url = URL::temporarySignedRoute(
        'trips.public-tracking.position',
        now()->addHour(),
        ['trip' => $trip],
    );

    $this->getJson($url)
        ->assertOk()
        ->assertJsonPath('has_position', true)
        ->assertJsonPath('position.is_stale', true);
});

test('chuyến hoàn thành được báo is_finished true', function () {
    ['trip' => $trip] = publicTrackingFixtures([
        'status' => TripStatus::Completed,
        'completed_at' => now(),
    ]);

    $url = URL::temporarySignedRoute(
        'trips.public-tracking.position',
        now()->addHour(),
        ['trip' => $trip],
    );

    $this->getJson($url)
        ->assertOk()
        ->assertJsonPath('is_finished', true);
});

test('dữ liệu bản đồ sắp đúng thứ tự điểm và gộp điểm trùng liền kề', function () {
    fakeOsrmRoute();

    ['trip' => $trip, 'pickup' => $pickup, 'delivery' => $delivery] = publicTrackingFixtures();

    publicTrackingOrder($trip, $pickup, $delivery, ['planned_loading_at' => now()->addHours(2)]);
    publicTrackingOrder($trip, $pickup, $delivery, ['planned_loading_at' => now()->addHour()]);

    $map = app(TripTrackingMapService::class)->buildMapData($trip->fresh());

    // 2 đơn dùng cùng kho lấy + cùng kho giao => mỗi toạ độ chỉ xuất hiện 1 lần liền kề.
    expect($map['points'])->toHaveCount(2)
        ->and($map['pickups'])->toHaveCount(2)
        ->and($map['deliveries'])->toHaveCount(2)
        ->and($map['route_source'])->toBe('osrm')
        ->and(collect($map['markers'])->pluck('type')->all())->toBe(['pickup', 'pickup', 'delivery', 'delivery'])
        ->and(collect($map['markers'])->pluck('label')->all())->toBe(['1', '2', '1', '2']);
});

test('osrm lỗi vẫn trả trang với tuyến dự phòng', function () {
    Http::fake(['*' => Http::response(null, 500)]);

    ['trip' => $trip, 'pickup' => $pickup, 'delivery' => $delivery] = publicTrackingFixtures();
    publicTrackingOrder($trip, $pickup, $delivery);

    $map = app(TripTrackingMapService::class)->buildMapData($trip->fresh());

    expect($map['route'])->toBeEmpty()
        ->and($map['route_source'])->toBe('direct');

    $this->get(CopyTrackingLinkAction::makeUrl($trip))->assertOk();
});

test('dữ liệu bản đồ có tuyến đường xe đã chạy từ GPS checkpoint', function () {
    fakeOsrmRoute();

    ['trip' => $trip, 'pickup' => $pickup, 'delivery' => $delivery] = publicTrackingFixtures();
    publicTrackingOrder($trip, $pickup, $delivery);

    TripCheckpoint::create([
        'trip_id' => $trip->id,
        'checkpoint_type' => 'started',
        'gps_lat' => 21.0300,
        'gps_lng' => 105.8400,
        'occurred_at' => now()->subHours(2),
    ]);

    TripCheckpoint::create([
        'trip_id' => $trip->id,
        'checkpoint_type' => 'left_pickup',
        'gps_lat' => 21.0400,
        'gps_lng' => 105.8500,
        'occurred_at' => now()->subHour(),
    ]);

    $map = app(TripTrackingMapService::class)->buildMapData($trip->fresh());

    expect($map['traveled'])->toHaveCount(2)
        ->and($map['traveled_route_source'])->toBe('osrm')
        ->and(count($map['traveled_route']))->toBeGreaterThanOrEqual(3)
        ->and($map['has_points'])->toBeTrue();

    $this->get(CopyTrackingLinkAction::makeUrl($trip))
        ->assertOk()
        ->assertSee('traveled_route', false)
        ->assertSee('Xe đã chạy');
});

test('makeUrl tạo url có chữ ký hợp lệ và hết hạn sau 3 ngày', function () {
    ['trip' => $trip] = publicTrackingFixtures();

    $url = CopyTrackingLinkAction::makeUrl($trip);

    expect(URL::hasValidSignature(Request::create($url)))->toBeTrue();

    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    expect((int) $query['expires'])
        ->toBeGreaterThanOrEqual(now()->addDays(3)->subMinute()->getTimestamp())
        ->toBeLessThanOrEqual(now()->addDays(3)->addMinute()->getTimestamp());
});

test('dữ liệu bản đồ dùng lộ trình EUP thật trong thời gian chuyến khi có', function () {
    fakeOsrmRoute();

    ['trip' => $trip, 'pickup' => $pickup, 'delivery' => $delivery] = publicTrackingFixtures(['started_at' => now()->subHours(2)]);
    publicTrackingOrder($trip, $pickup, $delivery);

    $log = fn (float $lat, int $minutesAgo) => VehicleGpsLog::create([
        'vehicle_id' => $trip->vehicle_id,
        'lat' => $lat,
        'lng' => 105.85,
        'recorded_at' => now()->subMinutes($minutesAgo),
    ]);
    $log(20.9, 180); // trước khi bắt đầu chuyến: không lấy
    $log(21.03, 90);
    $log(21.01, 110);
    $log(21.05, 30);

    $map = app(TripTrackingMapService::class)->buildMapData($trip->fresh());

    expect($map['traveled'])->toBe([[21.01, 105.85], [21.03, 105.85], [21.05, 105.85]])
        ->and($map['traveled_route'])->toBe($map['traveled'])
        ->and($map['traveled_route_source'])->toBe('gps');
});

test('lộ trình EUP dài được rút gọn nhưng giữ điểm đầu và cuối', function () {
    ['trip' => $trip] = publicTrackingFixtures(['started_at' => now()->subHours(5)]);

    $rows = collect(range(1, 1200))->map(fn (int $i) => [
        'vehicle_id' => $trip->vehicle_id,
        'lat' => 21 + $i / 10000,
        'lng' => 105.85,
        'recorded_at' => now()->subHours(5)->addSeconds($i * 10)->format('Y-m-d H:i:s'),
    ]);
    VehicleGpsLog::insert($rows->all());

    $track = $trip->fresh()->gpsTrack();

    expect(count($track))->toBeLessThanOrEqual(500)
        ->and($track[0])->toBe([21.0001, 105.85])
        ->and(end($track))->toBe([21.12, 105.85]);
});
