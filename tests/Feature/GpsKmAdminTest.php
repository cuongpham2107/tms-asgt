<?php

use App\Enums\CheckpointType;
use App\Enums\OrderDeliveryPointStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\TripStatus;
use App\Filament\Resources\Trips\Pages\ListTrips;
use App\Models\Area;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Order;
use App\Models\OrderDeliveryPoint;
use App\Models\Trip;
use App\Models\TripCheckpoint;
use App\Models\TripLeg;
use App\Models\User;
use App\Models\VehicleGpsPoint;
use App\Services\Gps\TripTrack;
use App\Services\Trip\TripLegService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Gate::before(fn () => true);
    $this->admin = User::factory()->create();
    $this->actingAs($this->admin);
    $this->t0 = CarbonImmutable::parse('2026-10-05 08:00:00');
});

function settledTrip(CarbonImmutable $t0): Trip
{
    return Trip::factory()->create([
        'status' => TripStatus::Completed,
        'started_at' => $t0,
        'completed_at' => $t0->addMinutes(40),
        'total_km' => 40,
        'total_km_loaded' => 20,
        'total_km_empty' => 20,
        'gps_coverage' => 70,
        'km_source' => 'phone_gps',
        'km_needs_review' => true,
        'km_calculated_at' => now(),
    ]);
}

function adjustKm(Trip $trip, array $data): Testable
{
    return Livewire::test(ListTrips::class)
        ->set('orderType', 'all')
        ->set('activePlaceFilter', 'all')
        ->set('activeStatusFilter', 'completed')
        ->mountTableAction('adjust_km', $trip)
        ->setTableActionData($data)
        ->callMountedTableAction();
}

test('adjusting km requires a reason', function () {
    $trip = settledTrip($this->t0);

    adjustKm($trip, ['km_adjusted' => 42, 'km_adjusted_loaded' => 21, 'km_adjust_reason' => ''])
        ->assertHasTableActionErrors(['km_adjust_reason' => 'required']);

    expect($trip->fresh()->km_adjusted)->toBeNull();
});

test('adjusting km keeps the GPS figures and reports the adjusted ones', function () {
    $trip = settledTrip($this->t0);

    adjustKm($trip, ['km_adjusted' => 42, 'km_adjusted_loaded' => 21, 'km_adjust_reason' => 'GPS mất sóng trong hầm'])
        ->assertHasNoTableActionErrors();

    $trip->refresh();
    expect((float) $trip->total_km)->toBe(40.0)
        ->and($trip->reportedTotalKm())->toBe(42.0)
        ->and($trip->reportedLoadedKm())->toBe(21.0)
        ->and($trip->reportedEmptyKm())->toBe(21.0)
        ->and($trip->km_adjusted_by)->toBe($this->admin->id)
        ->and($trip->km_needs_review)->toBeFalse();
});

test('dispatchers can adjust leg-by-leg km via table action', function () {
    $trip = settledTrip($this->t0);
    $area = Area::create(['type' => OrderType::Hhhk, 'code' => 'LEG', 'name' => 'Leg Area']);
    $customer = Customer::create(['code' => 'CUST-LEG', 'name' => 'Cust Leg', 'is_active' => true]);
    $pickupLoc = Location::create(['code' => 'PK', 'name' => 'Pick', 'lat' => 21.199, 'lng' => 105.994, 'area_id' => $area->id]);
    $delivLoc = Location::create(['code' => 'DL', 'name' => 'Deliv', 'lat' => 21.218, 'lng' => 105.804, 'area_id' => $area->id]);

    $order = Order::create([
        'order_code' => 'ORD-LEG-ADM', 'type' => OrderType::Hhhk, 'area_id' => $area->id,
        'customer_id' => $customer->id, 'trip_id' => $trip->id, 'pickup_location_id' => $pickupLoc->id,
        'pickup_address' => 'Kho PK', 'status' => OrderStatus::Completed, 'created_by' => $this->admin->id,
    ]);
    $dp = OrderDeliveryPoint::create([
        'order_id' => $order->id, 'location_id' => $delivLoc->id, 'address' => 'Kho DL',
        'sequence' => 1, 'status' => OrderDeliveryPointStatus::Delivered,
    ]);

    TripCheckpoint::create([
        'trip_id' => $trip->id, 'checkpoint_type' => CheckpointType::Started->value,
        'occurred_at' => $this->t0, 'gps_lat' => 21.000, 'gps_lng' => 105.800,
    ]);
    TripCheckpoint::create([
        'trip_id' => $trip->id, 'order_id' => $order->id, 'checkpoint_type' => CheckpointType::ArrivedPickup->value,
        'occurred_at' => $this->t0->addMinutes(15), 'gps_lat' => 21.199, 'gps_lng' => 105.994,
    ]);
    TripCheckpoint::create([
        'trip_id' => $trip->id, 'order_id' => $order->id, 'delivery_point_id' => $dp->id, 'checkpoint_type' => CheckpointType::Completed->value,
        'occurred_at' => $this->t0->addMinutes(40), 'gps_lat' => 21.218, 'gps_lng' => 105.804,
    ]);

    app(TripLegService::class)->syncLegs($trip);

    $dbLegs = $trip->fresh()->legs;
    expect($dbLegs->count())->toBe(2);

    $legsData = [
        [
            'id' => $dbLegs[0]->id,
            'leg_index' => 1,
            'distance_adjusted_km' => 16.0,
            'adjust_reason' => 'Sửa chặng 1',
        ],
        [
            'id' => $dbLegs[1]->id,
            'leg_index' => 2,
            'distance_adjusted_km' => 30.0,
            'adjust_reason' => 'Sửa chặng 2',
        ],
    ];

    adjustKm($trip, [
        'km_adjusted' => 46,
        'km_adjusted_loaded' => 30,
        'km_adjust_reason' => 'Điều chỉnh theo chặng thực tế',
        'legs' => $legsData,
    ])->assertHasNoTableActionErrors();

    $trip->refresh();
    expect((float) $trip->km_adjusted)->toBe(46.0)
        ->and((float) $trip->km_adjusted_loaded)->toBe(30.0)
        ->and($trip->reportedTotalKm())->toBe(46.0)
        ->and($trip->reportedLoadedKm())->toBe(30.0)
        ->and($trip->reportedEmptyKm())->toBe(16.0);

    $leg1 = TripLeg::find($dbLegs[0]->id);
    $leg2 = TripLeg::find($dbLegs[1]->id);

    expect((float) $leg1->distance_adjusted_km)->toBe(16.0)
        ->and((float) $leg2->distance_adjusted_km)->toBe(30.0);
});

test('the trip map splits the track into loaded and empty segments', function () {
    $trip = settledTrip($this->t0);
    $area = Area::create(['type' => OrderType::Hhhk, 'code' => 'MAP', 'name' => 'Map']);
    $customer = Customer::create(['code' => 'CUST-MAP', 'name' => 'Map', 'is_active' => true]);
    $order = Order::create([
        'order_code' => 'ORD-MAP-1', 'type' => OrderType::Hhhk, 'area_id' => $area->id,
        'customer_id' => $customer->id, 'trip_id' => $trip->id, 'pickup_address' => 'P',
        'status' => OrderStatus::Completed, 'created_by' => $this->admin->id,
    ]);
    foreach ([[CheckpointType::ArrivedPickup, 10], [CheckpointType::Completed, 30]] as [$type, $minute]) {
        TripCheckpoint::create(['trip_id' => $trip->id, 'order_id' => $order->id, 'checkpoint_type' => $type->value, 'occurred_at' => $this->t0->addMinutes($minute)]);
    }
    for ($m = 0; $m <= 40; $m++) {
        VehicleGpsPoint::create([
            'vehicle_id' => $trip->vehicle_id, 'recorded_at' => $this->t0->addMinutes($m),
            'lat' => 10 + $m / 100, 'lng' => 106, 'source' => VehicleGpsPoint::SOURCE_PHONE,
        ]);
    }

    $segments = app(TripTrack::class)->segments($trip);

    expect(array_column($segments, 'loaded'))->toBe([false, true, false]);
});

test('dispatchers are alerted once when a running trip stops sending GPS', function () {
    Role::create(['name' => 'driver', 'guard_name' => 'web']);
    $driver = User::factory()->create();
    $driver->assignRole('driver');
    Trip::factory()->create([
        'driver_id' => $driver->id,
        'status' => TripStatus::Delivering,
        'started_at' => now()->subMinutes(30),
    ]);

    $this->artisan('gps:check-stale')->assertSuccessful();
    $this->artisan('gps:check-stale')->assertSuccessful();

    expect($this->admin->notifications()->count())->toBe(1)
        ->and($driver->notifications()->count())->toBe(0);
});
