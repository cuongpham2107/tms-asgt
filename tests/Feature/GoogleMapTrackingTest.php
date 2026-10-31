<?php

use App\Enums\CargoType;
use App\Enums\CheckpointType;
use App\Enums\OrderDeliveryPointStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\TripStatus;
use App\Enums\VehicleOwnerType;
use App\Enums\VehicleStatus;
use App\Enums\VehicleType;
use App\Filament\Pages\GoogleMapTracking;
use App\Filament\Widgets\VehicleTrackingMap;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Gate::before(fn () => true);

    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

function trackingVehicle(string $plate, ?float $lat = null, ?float $lng = null, VehicleStatus $status = VehicleStatus::On): Vehicle
{
    return Vehicle::create([
        'plate_number' => $plate,
        'owner' => 'ASGL',
        'type' => VehicleOwnerType::Company,
        'vehicle_type' => VehicleType::Normal,
        'status' => $status,
        'is_active' => true,
        'gps_lat' => $lat,
        'gps_lng' => $lng,
    ]);
}

test('google map tracking page mounts and the react widget gets https google tiles', function () {
    trackingVehicle('29A-12345', 21.0285, 105.8542);

    Livewire::test(GoogleMapTracking::class)->assertSuccessful();

    $config = Livewire::test(VehicleTrackingMap::class)->instance()->getRendererConfiguration();

    expect($config['tiles'])->toBeArray()->not->toBeEmpty()
        ->and($config['tiles'][0][1])->toStartWith('https://')->toContain('lyrs=m')->toContain('hl=vi');

    foreach ($config['tiles'] as $tile) {
        expect($tile[1])->toStartWith('https://');
    }
});

test('selecting and toggling vehicles keeps the widget state', function () {
    $vehicle1 = trackingVehicle('29B-11111', 21.03, 105.85, VehicleStatus::Running);
    $vehicle2 = trackingVehicle('29B-22222', 21.04, 105.86);

    $widget = Livewire::test(VehicleTrackingMap::class);

    // Bấm marker/thẻ xe: chọn đúng xe, bấm lại thì bỏ chọn
    $widget->call('selectVehicle', $vehicle1->id)->assertSet('selectedVehicleIds', [$vehicle1->id]);
    $widget->call('selectVehicle', $vehicle1->id)->assertSet('selectedVehicleIds', []);

    // Checkbox cho phép chọn nhiều xe
    $widget->call('toggleCheckbox', $vehicle1->id)
        ->call('toggleCheckbox', $vehicle2->id)
        ->assertSet('selectedVehicleIds', [$vehicle1->id, $vehicle2->id]);

    $widget->call('toggleCheckbox', $vehicle1->id)->assertSet('selectedVehicleIds', [$vehicle2->id]);

    // Chọn hết / bỏ chọn / chỉ xe đang chạy
    $widget->call('selectAll')->assertSet('selectedVehicleIds', [$vehicle1->id, $vehicle2->id]);
    $widget->call('deselectAll')->assertSet('selectedVehicleIds', []);
    $widget->call('selectRunningOnly')->assertSet('selectedVehicleIds', [$vehicle1->id]);

    $config = $widget->instance()->getRendererConfiguration();
    expect($config['selectedPlates'])->toBe('29B-11111')
        ->and(collect($config['vehicles'])->firstWhere('id', $vehicle1->id)['selected'])->toBeTrue()
        ->and(collect($config['vehicles'])->firstWhere('id', $vehicle2->id)['selected'])->toBeFalse();
});

test('only vehicles with a real gps position are drawn, colored by status', function () {
    $tracked = trackingVehicle('29D-10001', 21.2068, 105.8208, VehicleStatus::Running);
    $untracked = trackingVehicle('29D-10002');

    $config = Livewire::test(VehicleTrackingMap::class)->instance()->getRendererConfiguration();
    $vehicles = collect($config['vehicles'])->keyBy('id');

    expect($vehicles[$tracked->id]['hasGps'])->toBeTrue()
        ->and($vehicles[$tracked->id]['lat'])->toBe(21.2068)
        ->and($vehicles[$tracked->id]['color'])->toBe('#f59e0b')
        ->and($vehicles[$untracked->id]['hasGps'])->toBeFalse()
        ->and($config['withoutGpsCount'])->toBe(1);
});

test('selected vehicles receive their route, unselected vehicles do not', function () {
    $selected = trackingVehicle('29E-00001', 21.01, 105.85);
    $other = trackingVehicle('29E-00002', 21.02, 105.85);

    $widget = Livewire::test(VehicleTrackingMap::class);

    expect($widget->instance()->getRendererConfiguration()['routes'])->toBe([]);

    $routes = $widget->call('selectVehicle', $selected->id)->instance()->getRendererConfiguration()['routes'];

    expect(collect($routes)->pluck('vehicleId')->all())->toBe([$selected->id])
        ->and(collect($routes)->pluck('vehicleId')->all())->not->toContain($other->id);
});

test('route follows checkpoint time order to the current position and plans remaining stops', function () {
    Http::fake(['*' => Http::response([], 500)]);

    $vehicle = trackingVehicle('29D-20001', 21.10, 105.90, VehicleStatus::Running);
    $trip = Trip::create([
        'trip_code' => 'TRIP-MAP-01',
        'vehicle_id' => $vehicle->id,
        'driver_id' => $this->user->id,
        'status' => TripStatus::Started,
    ]);
    $order = Order::create([
        'order_code' => 'ORD-MAP-01',
        'type' => OrderType::Hhhk,
        'area_id' => Area::create(['code' => 'NBA', 'name' => 'Nội Bài', 'is_active' => true, 'sort_order' => 1])->id,
        'customer_id' => Customer::create(['code' => 'ASGL', 'name' => 'ASGL', 'is_active' => true])->id,
        'trip_id' => $trip->id,
        'cargo_type' => CargoType::Gcr,
        'status' => OrderStatus::Completed,
        'created_by' => $this->user->id,
    ]);
    $makeStop = fn (string $code, float $lat, float $lng, int $sequence, OrderDeliveryPointStatus $status) => OrderDeliveryPoint::create([
        'order_id' => $order->id,
        'location_id' => Location::create(['code' => $code, 'name' => $code, 'lat' => $lat, 'lng' => $lng, 'loc_type' => 'delivery', 'is_active' => true])->id,
        'sequence' => $sequence,
        'address' => $code,
        'status' => $status,
    ]);
    $makeStop('DONE', 21.05, 105.85, 1, OrderDeliveryPointStatus::Delivered);
    $pending = $makeStop('NEXT', 21.30, 106.00, 2, OrderDeliveryPointStatus::Pending);

    // Inserted out of order: the route must follow occurred_at, not insertion order.
    TripCheckpoint::create(['trip_id' => $trip->id, 'checkpoint_type' => CheckpointType::LeftPickup, 'occurred_at' => now()->subHour(), 'gps_lat' => 21.02, 'gps_lng' => 105.84]);
    TripCheckpoint::create(['trip_id' => $trip->id, 'checkpoint_type' => CheckpointType::Started, 'occurred_at' => now()->subHours(2), 'gps_lat' => 21.00, 'gps_lng' => 105.80]);

    $route = Livewire::test(VehicleTrackingMap::class)
        ->call('selectVehicle', $vehicle->id)
        ->instance()
        ->getRendererConfiguration()['routes'][0];

    expect($route['traveled'])->toBe([[21.0, 105.8], [21.02, 105.84], [21.1, 105.9]])
        ->and($route['planned'])->toBe([[21.1, 105.9], [21.3, 106.0]])
        ->and(collect($route['stops'])->pluck('label'))->toHaveCount(1)
        ->and($route['stops'][0]['label'])->toContain('NEXT')
        ->and($route['stops'][0])->toMatchArray(['lat' => 21.30, 'lng' => 106.00]);
});

test('route uses the real EUP gps track of the active trip when available', function () {
    Http::fake(['*' => Http::response([], 500)]);

    $vehicle = trackingVehicle('29D-20002', 21.10, 105.90, VehicleStatus::Running);
    Trip::create([
        'trip_code' => 'TRIP-MAP-02',
        'vehicle_id' => $vehicle->id,
        'driver_id' => $this->user->id,
        'status' => TripStatus::Started,
        'started_at' => now()->subHours(2),
    ]);
    foreach ([[21.00, 100], [21.04, 60], [21.08, 20]] as [$lat, $minutesAgo]) {
        VehicleGpsLog::create(['vehicle_id' => $vehicle->id, 'lat' => $lat, 'lng' => 105.90, 'recorded_at' => now()->subMinutes($minutesAgo)]);
    }

    $route = Livewire::test(VehicleTrackingMap::class)
        ->call('selectVehicle', $vehicle->id)
        ->instance()
        ->getRendererConfiguration()['routes'][0];

    expect($route['traveled'])->toBe([[21.0, 105.9], [21.04, 105.9], [21.08, 105.9], [21.1, 105.9]]);
});
