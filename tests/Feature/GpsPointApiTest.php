<?php

use App\Enums\ShiftType;
use App\Models\DriverShift;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleGpsPoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->driver = User::factory()->create();
    $this->driver->assignRole(Role::create(['name' => 'driver', 'guard_name' => 'web']));
    $this->vehicle = Vehicle::factory()->create();
    $this->shift = DriverShift::create([
        'driver_id' => $this->driver->id,
        'shift_type' => ShiftType::Full,
        'start_time' => now(),
    ]);
    Sanctum::actingAs($this->driver);
});

function gpsBatch(array $seqs, array $overrides = []): array
{
    return [
        'device_id' => 'device-1',
        'shift_id' => test()->shift->id,
        'vehicle_id' => test()->vehicle->id,
        'points' => array_map(fn (int $seq) => [
            'seq' => $seq,
            'recorded_at' => now()->addSeconds($seq * 5)->toIso8601String(),
            'lat' => 10.8 + $seq / 10000,
            'lng' => 106.6,
            'speed' => 40,
            'accuracy' => 8,
            'mocked' => false,
            ...$overrides,
        ], $seqs),
    ];
}

test('a batch is stored and the last sequence is returned', function () {
    $this->postJson('/api/driver/gps-points', gpsBatch([1, 2, 3]))
        ->assertSuccessful()
        ->assertJsonPath('last_seq', 3);

    expect(VehicleGpsPoint::count())->toBe(3)
        ->and(VehicleGpsPoint::first()->source)->toBe(VehicleGpsPoint::SOURCE_PHONE)
        ->and(VehicleGpsPoint::first()->driver_id)->toBe($this->driver->id)
        ->and($this->vehicle->fresh()->last_gps_update)->not->toBeNull();
});

test('re-sending the same batch does not duplicate points', function () {
    $this->postJson('/api/driver/gps-points', gpsBatch([1, 2, 3]))->assertSuccessful();
    $this->postJson('/api/driver/gps-points', gpsBatch([2, 3, 4]))
        ->assertSuccessful()
        ->assertJsonPath('last_seq', 4);

    expect(VehicleGpsPoint::count())->toBe(4);
});

test('invalid points are rejected', function () {
    $this->postJson('/api/driver/gps-points', gpsBatch([1], ['lat' => 120]))->assertStatus(422);
    $this->postJson('/api/driver/gps-points', ['device_id' => 'd', 'points' => []])->assertStatus(422);
});

test('a driver cannot post points for another driver\'s shift', function () {
    $otherShift = DriverShift::create([
        'driver_id' => User::factory()->create()->id,
        'shift_type' => ShiftType::Full,
        'start_time' => now(),
    ]);

    $this->postJson('/api/driver/gps-points', [...gpsBatch([1]), 'shift_id' => $otherShift->id])->assertForbidden();
});

test('mocked locations are stored but do not move the vehicle on the map', function () {
    $this->postJson('/api/driver/gps-points', gpsBatch([1], ['mocked' => true]))->assertSuccessful();

    expect(VehicleGpsPoint::first()->mocked)->toBeTrue()
        ->and($this->vehicle->fresh()->last_gps_update)->toBeNull();
});
