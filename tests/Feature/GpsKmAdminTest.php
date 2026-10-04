<?php

use App\Enums\CheckpointType;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\TripStatus;
use App\Filament\Resources\Trips\Pages\ListTrips;
use App\Models\Area;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Trip;
use App\Models\TripCheckpoint;
use App\Models\User;
use App\Models\VehicleGpsPoint;
use App\Services\Gps\TripTrack;
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
