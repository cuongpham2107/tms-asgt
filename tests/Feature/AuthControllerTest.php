<?php

use App\Enums\ShiftType;
use App\Models\DriverShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->driverRole = Role::create([
        'name' => 'driver',
        'guard_name' => 'web',
    ]);
});

test('login returns shift null when driver only has future overtime shift', function () {
    $driver = User::factory()->create([
        'email' => 'driver1@example.com',
        'password' => bcrypt('password123'),
    ]);
    $driver->assignRole($this->driverRole);

    // Overtime shift created for a future date (e.g. 2 days later)
    $futureDate = now()->addDays(2);
    DriverShift::create([
        'driver_id' => $driver->id,
        'shift_type' => ShiftType::Full,
        'is_overtime' => true,
        'start_time' => $futureDate->copy()->startOfDay(),
    ]);

    $response = $this->postJson('/api/driver/login', [
        'email' => 'driver1@example.com',
        'password' => 'password123',
    ]);

    $response->assertSuccessful()
        ->assertJsonPath('shift', null)
        ->assertJsonStructure(['user', 'token', 'shift']);

    // Driver can create a new shift for today without 409 conflict
    $startResponse = $this->withHeader('Authorization', 'Bearer '.$response->json('token'))
        ->postJson('/api/driver/shifts/start', [
            'shift_type' => ShiftType::Full->value,
            'start_time' => now()->toIso8601String(),
        ]);

    $startResponse->assertSuccessful();
});

test('login returns active shift when driver has an open shift today', function () {
    $driver = User::factory()->create([
        'email' => 'driver2@example.com',
        'password' => bcrypt('password123'),
    ]);
    $driver->assignRole($this->driverRole);

    $todayShift = DriverShift::create([
        'driver_id' => $driver->id,
        'shift_type' => ShiftType::MorningHalf,
        'start_time' => now()->subHour(),
    ]);

    $response = $this->postJson('/api/driver/login', [
        'email' => 'driver2@example.com',
        'password' => 'password123',
    ]);

    $response->assertSuccessful()
        ->assertJsonPath('shift.id', $todayShift->id)
        ->assertJsonPath('shift.shift_type', 'morning_half');
});

test('login returns shift null when driver has no shifts', function () {
    $driver = User::factory()->create([
        'email' => 'driver3@example.com',
        'password' => bcrypt('password123'),
    ]);
    $driver->assignRole($this->driverRole);

    $response = $this->postJson('/api/driver/login', [
        'email' => 'driver3@example.com',
        'password' => 'password123',
    ]);

    $response->assertSuccessful()
        ->assertJsonPath('shift', null);
});
