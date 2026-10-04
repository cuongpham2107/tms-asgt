<?php

use App\Models\Vehicle;
use App\Models\VehicleGpsPoint;
use App\Services\Gps\DistanceResult;
use App\Services\Gps\GpsDistanceService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

const METERS_PER_DEGREE_LAT = 111195.0;

beforeEach(function () {
    $this->vehicle = Vehicle::factory()->create();
    $this->start = CarbonImmutable::parse('2026-10-05 08:00:00');
    $this->service = app(GpsDistanceService::class);
});

/**
 * Xe chạy thẳng hướng bắc với vận tốc đều, mỗi $everySeconds giây một điểm.
 */
function driveNorth(Vehicle $vehicle, CarbonImmutable $start, int $seconds, float $kmh, int $everySeconds = 5, string $source = VehicleGpsPoint::SOURCE_PHONE, int $offsetSeconds = 0): void
{
    $metersPerSecond = $kmh / 3.6;

    for ($t = 0; $t <= $seconds; $t += $everySeconds) {
        VehicleGpsPoint::create([
            'vehicle_id' => $vehicle->id,
            'recorded_at' => $start->addSeconds($offsetSeconds + $t),
            'lat' => 10.0 + (($offsetSeconds + $t) * $metersPerSecond) / METERS_PER_DEGREE_LAT,
            'lng' => 106.0,
            'speed' => $kmh,
            'accuracy' => 8,
            'source' => $source,
        ]);
    }
}

test('a straight 10 km drive sampled every 5 seconds measures 10 km within 1%', function () {
    driveNorth($this->vehicle, $this->start, 600, 60);

    $result = $this->service->distance($this->vehicle->id, $this->start, $this->start->addMinutes(10));

    expect($result->km)->toBeGreaterThan(9.9)->toBeLessThan(10.1)
        ->and($result->coverage)->toBe(100.0)
        ->and($result->source)->toBe(DistanceResult::SOURCE_PHONE);
});

test('a parked vehicle with ±15 m GPS noise for 30 minutes adds under 0.1 km', function () {
    mt_srand(42);
    for ($t = 0; $t <= 1800; $t += 5) {
        VehicleGpsPoint::create([
            'vehicle_id' => $this->vehicle->id,
            'recorded_at' => $this->start->addSeconds($t),
            'lat' => 10.0 + (mt_rand(-15, 15) / METERS_PER_DEGREE_LAT),
            'lng' => 106.0 + (mt_rand(-15, 15) / METERS_PER_DEGREE_LAT),
            'speed' => 0,
            'accuracy' => 10,
            'source' => VehicleGpsPoint::SOURCE_PHONE,
        ]);
    }

    $result = $this->service->distance($this->vehicle->id, $this->start, $this->start->addMinutes(30));

    expect($result->km)->toBeLessThan(0.1);
});

test('a single 5 km jump is discarded', function () {
    driveNorth($this->vehicle, $this->start, 600, 60);
    VehicleGpsPoint::create([
        'vehicle_id' => $this->vehicle->id,
        'recorded_at' => $this->start->addSeconds(302),
        'lat' => 10.0 + 5000 / METERS_PER_DEGREE_LAT,
        'lng' => 106.05,
        'speed' => 60,
        'accuracy' => 8,
        'source' => VehicleGpsPoint::SOURCE_PHONE,
    ]);

    $result = $this->service->distance($this->vehicle->id, $this->start, $this->start->addMinutes(10));

    expect($result->km)->toBeGreaterThan(9.9)->toBeLessThan(10.1);
});

test('a 10 minute phone gap is filled from the black box and marked as mixed', function () {
    driveNorth($this->vehicle, $this->start, 300, 60);
    driveNorth($this->vehicle, $this->start, 600, 60, offsetSeconds: 900);
    driveNorth($this->vehicle, $this->start, 600, 60, everySeconds: 30, source: VehicleGpsPoint::SOURCE_EUP, offsetSeconds: 300);

    $result = $this->service->distance($this->vehicle->id, $this->start, $this->start->addSeconds(1500));

    expect($result->source)->toBe(DistanceResult::SOURCE_MIXED)
        ->and($result->km)->toBeGreaterThan(24.7)->toBeLessThan(25.3)
        ->and($result->coverage)->toBe(100.0);
});

test('a gap with no phone or black box points is bridged by the road distance', function () {
    Http::fake(['router.project-osrm.org/*' => Http::response([
        'code' => 'Ok',
        'routes' => [['distance' => 15000, 'duration' => 900, 'geometry' => ['coordinates' => []], 'legs' => []]],
    ])]);

    driveNorth($this->vehicle, $this->start, 60, 60);
    driveNorth($this->vehicle, $this->start, 60, 60, offsetSeconds: 960);

    $result = $this->service->distance($this->vehicle->id, $this->start, $this->start->addSeconds(1020));

    Http::assertSentCount(1);
    expect($result->osrmFilledSeconds)->toBe(900)
        ->and($result->km)->toBeGreaterThan(16.9)->toBeLessThan(17.1)
        ->and($result->coverage)->toBeLessThan(90.0);
});

test('mocked phone points are ignored and flagged', function () {
    driveNorth($this->vehicle, $this->start, 600, 60);
    VehicleGpsPoint::query()->update(['mocked' => true]);

    $result = $this->service->distance($this->vehicle->id, $this->start, $this->start->addMinutes(10));

    expect($result->km)->toBe(0.0)
        ->and($result->hasMocked)->toBeTrue();
});
