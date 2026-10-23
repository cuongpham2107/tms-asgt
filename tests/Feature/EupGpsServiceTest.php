<?php

use App\Filament\Pages\VehicleDispatchBoard;
use App\Models\Vehicle;
use App\Models\VehicleGpsLog;
use App\Services\EupGpsService;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.eup.base_url' => 'http://eup.test']);
    $this->vehicle = Vehicle::factory()->create(['plate_number' => '29C-00001']);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function eupItem(array $overrides = []): array
{
    return [
        'VehicleNo' => '29C-00001',
        'Latitude' => 21.2116,
        'Longitude' => 105.8091,
        'Speed' => 35,
        'Direction' => 90,
        'CarStatus' => 0,
        'Address' => 'Nội Bài, Hà Nội',
        'OperateDate' => '2026-10-23 10:00:00',
        ...$overrides,
    ];
}

test('sync stores one gps log per vehicle with the device time', function () {
    Http::fake(['*' => Http::response(['result' => [eupItem()]])]);

    app(EupGpsService::class)->sync();

    $log = VehicleGpsLog::sole();
    expect($log->vehicle_id)->toBe($this->vehicle->id)
        ->and((float) $log->lat)->toBe(21.2116)
        ->and((float) $log->lng)->toBe(105.8091)
        ->and((float) $log->speed)->toBe(35.0)
        ->and($log->direction)->toBe(90)
        ->and($log->car_status)->toBe(0)
        ->and($log->recorded_at->format('Y-m-d H:i:s'))->toBe('2026-10-23 10:00:00')
        ->and((float) $this->vehicle->fresh()->gps_lat)->toBe(21.2116);
});

test('sync does not store the same device point twice but stores newer points', function () {
    Http::fake(['*' => Http::sequence()
        ->push(['result' => [eupItem()]])
        ->push(['result' => [eupItem()]])
        ->push(['result' => [eupItem(['OperateDate' => '2026-10-23 10:00:30', 'Latitude' => 21.2120])]])]);

    app(EupGpsService::class)->sync();
    app(EupGpsService::class)->sync();
    app(EupGpsService::class)->sync();

    expect(VehicleGpsLog::orderBy('recorded_at')->pluck('lat')->map(fn ($lat) => (float) $lat)->all())
        ->toBe([21.2116, 21.212]);
});

test('sync skips unknown vehicles and points without coordinates', function () {
    Http::fake(['*' => Http::response(['result' => [
        eupItem(['VehicleNo' => '99Z-99999']),
        eupItem(['Latitude' => null, 'Longitude' => null]),
    ]])]);

    $result = app(EupGpsService::class)->sync();

    expect(VehicleGpsLog::count())->toBe(0)
        ->and($result['errors'])->toHaveCount(1);
});

test('gps logs older than 3 days are pruned', function () {
    $this->travelTo(Carbon::parse('2026-10-23 10:00:00'));
    $old = VehicleGpsLog::create(['vehicle_id' => $this->vehicle->id, 'lat' => 21, 'lng' => 105, 'recorded_at' => now()->subDays(3)->subMinute()]);
    $recent = VehicleGpsLog::create(['vehicle_id' => $this->vehicle->id, 'lat' => 21, 'lng' => 105, 'recorded_at' => now()->subDays(2)]);

    $this->artisan('model:prune', ['--model' => [VehicleGpsLog::class]])->assertSuccessful();

    expect(VehicleGpsLog::pluck('id')->all())->toBe([$recent->id])
        ->and(VehicleGpsLog::find($old->id))->toBeNull();
});

test('track between returns the vehicle points in time order within the window', function () {
    foreach (['10:05', '10:00', '10:10', '11:00'] as $i => $time) {
        VehicleGpsLog::create([
            'vehicle_id' => $this->vehicle->id,
            'lat' => 21 + $i / 100,
            'lng' => 105,
            'recorded_at' => Carbon::parse("2026-10-23 {$time}:00"),
        ]);
    }

    $times = $this->vehicle->trackBetween(Carbon::parse('2026-10-23 10:00:00'), Carbon::parse('2026-10-23 10:30:00'))
        ->map(fn (VehicleGpsLog $log) => $log->recorded_at->format('H:i'))
        ->all();

    expect($times)->toBe(['10:00', '10:05', '10:10']);
});

test('model prune is scheduled daily', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains($event->command ?? '', 'model:prune'));

    expect($event?->expression)->toBe('0 0 * * *');
});

test('eup sync runs only from the scheduler every 30 seconds without overlapping', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => $event->description === 'sync-eup-gps');

    expect($event)->not->toBeNull()
        ->and($event->repeatSeconds)->toBe(30)
        ->and($event->withoutOverlapping)->toBeTrue();
});

test('no web route or page triggers an eup sync', function () {
    expect(Route::has('gps.sync'))->toBeFalse()
        ->and(method_exists(VehicleDispatchBoard::class, 'syncEupGps'))->toBeFalse();
});

test('a stationary vehicle with the same status is not logged again', function () {
    Http::fake(['*' => Http::sequence()
        ->push(['result' => [eupItem(['CarStatus' => 2, 'Speed' => 0])]])
        ->push(['result' => [eupItem(['CarStatus' => 2, 'Speed' => 0, 'OperateDate' => '2026-10-23 10:05:00', 'Latitude' => 21.21165])]])]);

    app(EupGpsService::class)->sync();
    app(EupGpsService::class)->sync();

    expect(VehicleGpsLog::count())->toBe(1)
        ->and((float) $this->vehicle->fresh()->gps_lat)->toBe(21.21165);
});

test('a vehicle moving at least 30 metres is logged', function () {
    Http::fake(['*' => Http::sequence()
        ->push(['result' => [eupItem()]])
        ->push(['result' => [eupItem(['OperateDate' => '2026-10-23 10:00:30', 'Latitude' => 21.2119])]])]);

    app(EupGpsService::class)->sync();
    app(EupGpsService::class)->sync();

    expect(VehicleGpsLog::count())->toBe(2);
});

test('a status change in place is logged', function () {
    Http::fake(['*' => Http::sequence()
        ->push(['result' => [eupItem(['CarStatus' => 0, 'Speed' => 0])]])
        ->push(['result' => [eupItem(['CarStatus' => 10, 'Speed' => 0, 'OperateDate' => '2026-10-23 10:01:00'])]])]);

    app(EupGpsService::class)->sync();
    app(EupGpsService::class)->sync();

    expect(VehicleGpsLog::orderBy('id')->pluck('car_status')->all())->toBe([0, 10]);
});

test('invalid coordinates do not overwrite the vehicle position or the track', function () {
    Http::fake(['*' => Http::sequence()
        ->push(['result' => [eupItem()]])
        ->push(['result' => [eupItem(['Latitude' => 0, 'Longitude' => 0, 'CarStatus' => 5, 'OperateDate' => '2026-10-23 10:01:00'])]])]);

    app(EupGpsService::class)->sync();
    app(EupGpsService::class)->sync();

    $vehicle = $this->vehicle->fresh();
    expect((float) $vehicle->gps_lat)->toBe(21.2116)
        ->and((float) $vehicle->gps_lng)->toBe(105.8091)
        ->and($vehicle->car_status)->toBe(5)
        ->and(VehicleGpsLog::count())->toBe(1);
});

test('idle engine start is stored on the vehicle only when the status changes', function () {
    Http::fake(['*' => Http::sequence()
        ->push(['result' => [eupItem(['CarStatus' => 10, 'Speed' => 0, 'OperateDate' => '2026-10-23 10:00:00'])]])
        ->push(['result' => [eupItem(['CarStatus' => 10, 'Speed' => 0, 'OperateDate' => '2026-10-23 10:05:00'])]])
        ->push(['result' => [eupItem(['CarStatus' => 0, 'Speed' => 20, 'OperateDate' => '2026-10-23 10:06:00'])]])]);

    app(EupGpsService::class)->sync();
    app(EupGpsService::class)->sync();
    $whileIdle = $this->vehicle->fresh()->idle_engine_since?->format('H:i');
    app(EupGpsService::class)->sync();

    expect($whileIdle)->toBe('10:00')
        ->and($this->vehicle->fresh()->idle_engine_since)->toBeNull();
});
