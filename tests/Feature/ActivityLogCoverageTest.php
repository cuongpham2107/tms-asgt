<?php

use App\Enums\CheckpointType;
use App\Enums\OrderDeliveryPointStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\ShiftType;
use App\Enums\TripStatus;
use App\Enums\VehicleOwnerType;
use App\Enums\VehicleStatus;
use App\Enums\VehicleType;
use App\Http\Middleware\LogDriverApiErrors;
use App\Models\Area;
use App\Models\Customer;
use App\Models\DriverShift;
use App\Models\Order;
use App\Models\OrderDeliveryPoint;
use App\Models\Trip;
use App\Models\TripCheckpoint;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->driver = User::factory()->create(['name' => 'Lái Xe Log']);
    $this->driver->assignRole(Role::firstOrCreate(['name' => 'driver', 'guard_name' => 'web']));
    $this->vehicle = Vehicle::create([
        'plate_number' => '29C-LOG-01',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'CLC',
        'is_active' => true,
        'status' => VehicleStatus::On,
        'type' => VehicleOwnerType::Company,
        'current_mileage' => 10000,
    ]);
    $this->shift = DriverShift::create(['driver_id' => $this->driver->id, 'shift_type' => ShiftType::Full, 'start_time' => now()->subHours(2), 'start_km' => 10000]);
    $this->trip = Trip::create(['trip_code' => 'TRIP-LOG-01', 'vehicle_id' => $this->vehicle->id, 'driver_id' => $this->driver->id, 'shift_id' => $this->shift->id, 'status' => TripStatus::Delivering, 'started_at' => now()->subHour(), 'start_km' => 10000]);
    $this->order = Order::create([
        'order_code' => 'ASG-LOG-01',
        'type' => OrderType::Hhhk,
        'area_id' => Area::create(['code' => 'LOG', 'name' => 'Log'])->id,
        'customer_id' => Customer::create(['code' => 'LOG', 'name' => 'Log'])->id,
        'trip_id' => $this->trip->id,
        'status' => OrderStatus::InTransit,
        'created_by' => $this->driver->id,
    ]);
});

function lastActivityFor(object $subject): ?Activity
{
    return Activity::query()->where('subject_type', $subject::class)->where('subject_id', $subject->getKey())->latest('id')->first();
}

test('checkpoints, delivery points and shifts record who changed what', function () {
    $this->actingAs($this->driver);

    $checkpoint = TripCheckpoint::create(['trip_id' => $this->trip->id, 'order_id' => $this->order->id, 'driver_id' => $this->driver->id, 'checkpoint_type' => CheckpointType::ArrivedPickup->value, 'km_reading' => 10010, 'occurred_at' => now(), 'eup_lat' => 21.1]);
    $checkpoint->update(['km_reading' => 10012]);

    $point = OrderDeliveryPoint::create(['order_id' => $this->order->id, 'sequence' => 1, 'status' => OrderDeliveryPointStatus::Pending]);
    $point->update(['status' => OrderDeliveryPointStatus::Arrived]);

    $this->shift->update(['end_km' => 10050]);

    expect(lastActivityFor($checkpoint)->attribute_changes['attributes'])->toBe(['km_reading' => '10012.0'])
        ->and(lastActivityFor($checkpoint)->causer_id)->toBe($this->driver->id)
        ->and(Activity::where('subject_type', TripCheckpoint::class)->where('event', 'created')->first()->attribute_changes['attributes'])->not->toHaveKey('eup_lat')
        ->and(lastActivityFor($point)->attribute_changes['attributes']['status'])->toBe('arrived')
        ->and(lastActivityFor($this->shift)->attribute_changes['attributes']['end_km'])->toBe('10050.0');
});

test('vehicles log km and status but not gps, users never log passwords', function () {
    $before = Activity::query()->where('subject_type', Vehicle::class)->count();

    $this->vehicle->update(['gps_lat' => 21.2, 'gps_lng' => 105.8, 'gps_speed' => 40]);
    expect(Activity::query()->where('subject_type', Vehicle::class)->count())->toBe($before);

    $this->vehicle->update(['current_mileage' => 10100, 'monthly_start_km' => 9000]);
    expect(lastActivityFor($this->vehicle)->attribute_changes['attributes'])->toHaveKeys(['current_mileage', 'monthly_start_km']);

    $this->driver->update(['password' => 'new-secret-123', 'phone' => '0900000001']);
    expect(lastActivityFor($this->driver)->attribute_changes['attributes'])->toBe(['phone' => '0900000001']);
});

test('driver handover through the api logs each order status change', function () {
    Sanctum::actingAs($this->driver);

    $this->postJson("/api/driver/trips/{$this->trip->id}/complete", ['end_km' => 10040])->assertSuccessful();

    expect($this->order->fresh()->status)->toBe(OrderStatus::DriverSwap)
        ->and(lastActivityFor($this->order)->attribute_changes['attributes']['status'])->toBe('driver_swap')
        ->and(lastActivityFor($this->order)->causer_id)->toBe($this->driver->id);
});

test('failed driver api calls are recorded with the driver and the error message', function () {
    Sanctum::actingAs($this->driver);

    $this->postJson("/api/driver/shifts/{$this->shift->id}/end-vehicle", ['km_reading' => 10060])->assertUnprocessable();

    $log = Activity::query()->where('log_name', LogDriverApiErrors::LOG_NAME)->latest('id')->first();

    expect($log)->not->toBeNull()
        ->and($log->causer_id)->toBe($this->driver->id)
        ->and($log->properties['status'])->toBe(422)
        ->and($log->properties['path'])->toBe("api/driver/shifts/{$this->shift->id}/end-vehicle")
        ->and($log->properties['message'])->toContain('chưa hoàn thiện chuyến');

    $this->getJson('/api/driver/orders')->assertSuccessful();
    expect(Activity::query()->where('log_name', LogDriverApiErrors::LOG_NAME)->count())->toBe(1);
});

test('shift api calls are logged even when successful, with the trip statuses the app received', function () {
    Sanctum::actingAs($this->driver);

    $this->getJson('/api/driver/shifts/current')->assertSuccessful();

    $log = Activity::query()->where('log_name', LogDriverApiErrors::LOG_NAME)->latest('id')->first();

    expect($log->event)->toBe('api_call')
        ->and($log->properties['status'])->toBe(200)
        ->and($log->properties['shift_trips'])->toBe(['TRIP-LOG-01: delivering']);
});

test('diagnose command explains why a driver cannot end the shift', function () {
    Sanctum::actingAs($this->driver);
    $this->postJson("/api/driver/shifts/{$this->shift->id}/end-vehicle", ['km_reading' => 10060])->assertUnprocessable();

    $this->artisan('drivers:diagnose-shift', ['driver' => (string) $this->driver->id])
        ->expectsOutputToContain('Tài xế #'.$this->driver->id)
        ->expectsOutputToContain('ĐANG MỞ')
        ->expectsOutputToContain('TRIP-LOG-01 (delivering, đơn ASG-LOG-01) → server chặn')
        ->expectsOutputToContain('chưa hoàn thiện chuyến')
        ->assertSuccessful();
});
