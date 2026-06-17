<?php

use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->driverRole = Role::create([
        'name' => 'driver',
        'guard_name' => 'web',
    ]);
});

test('guest cannot access locations api', function () {
    $response = $this->getJson('/api/driver/locations');

    $response->assertStatus(401);
});

test('non driver cannot access locations api', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $response = $this->getJson('/api/driver/locations');

    $response->assertStatus(403)
        ->assertJson(['message' => 'Forbidden. Driver role required.']);
});

test('driver can list locations', function () {
    $driver = User::factory()->create();
    $driver->assignRole($this->driverRole);
    Sanctum::actingAs($driver);

    Location::create([
        'code' => 'LOC-1',
        'name' => 'Location One',
        'address' => '123 Route A',
        'is_active' => true,
    ]);

    Location::create([
        'code' => 'LOC-2',
        'name' => 'Location Two',
        'address' => '456 Route B',
        'is_active' => false, // inactive, should not show
    ]);

    $response = $this->getJson('/api/driver/locations');

    $response->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.code', 'LOC-1');
});

test('driver can search locations by name code or address', function () {
    $driver = User::factory()->create();
    $driver->assignRole($this->driverRole);
    Sanctum::actingAs($driver);

    Location::create([
        'code' => 'LOC-ABC',
        'name' => 'Hanoi Station',
        'address' => 'Le Duan St',
        'is_active' => true,
    ]);

    Location::create([
        'code' => 'LOC-XYZ',
        'name' => 'Saigon Port',
        'address' => 'Nguyen Hue St',
        'is_active' => true,
    ]);

    // Search by name
    $response = $this->getJson('/api/driver/locations?search=Saigon');
    $response->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.code', 'LOC-XYZ');

    // Search by code
    $response = $this->getJson('/api/driver/locations?search=ABC');
    $response->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Hanoi Station');

    // Search by address
    $response = $this->getJson('/api/driver/locations?search=Nguyen');
    $response->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.code', 'LOC-XYZ');
});

test('driver can limit locations per page', function () {
    $driver = User::factory()->create();
    $driver->assignRole($this->driverRole);
    Sanctum::actingAs($driver);

    for ($i = 1; $i <= 5; $i++) {
        Location::create([
            'code' => 'LOC-'.$i,
            'name' => 'Location '.$i,
            'address' => 'Address '.$i,
            'is_active' => true,
        ]);
    }

    $response = $this->getJson('/api/driver/locations?limit=2');

    $response->assertStatus(200)
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('per_page', 2)
        ->assertJsonPath('total', 5);
});
