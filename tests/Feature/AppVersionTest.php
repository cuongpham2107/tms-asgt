<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->driverRole = Role::create([
        'name' => 'driver',
        'guard_name' => 'web',
    ]);
});

test('public endpoint returns app version info and store urls', function () {
    Config::set('app.mobile_latest_version', '1.0.4');
    Config::set('app.mobile_min_version', '1.0.1');
    Config::set('app.mobile_release_notes', 'Tính năng theo dõi đơn mới');
    Config::set('app.mobile_android_url', 'https://play.google.com/test');
    Config::set('app.mobile_ios_url', 'https://apps.apple.com/test');

    $response = $this->getJson('/api/driver/version');

    $response->assertSuccessful()
        ->assertJson([
            'latest_version' => '1.0.4',
            'min_version' => '1.0.1',
            'release_notes' => 'Tính năng theo dõi đơn mới',
            'store_urls' => [
                'android' => 'https://play.google.com/test',
                'ios' => 'https://apps.apple.com/test',
            ],
        ]);
});

test('driver login returns app_version in response payload', function () {
    Config::set('app.mobile_latest_version', '1.0.3');
    Config::set('app.mobile_min_version', '1.0.0');

    $driver = User::factory()->create([
        'email' => 'driver_test@example.com',
        'password' => bcrypt('secret123'),
    ]);
    $driver->assignRole($this->driverRole);

    $response = $this->postJson('/api/driver/login', [
        'email' => 'driver_test@example.com',
        'password' => 'secret123',
    ]);

    $response->assertSuccessful()
        ->assertJsonPath('app_version.latest_version', '1.0.3')
        ->assertJsonPath('app_version.min_version', '1.0.0');
});

test('shift active returns app_version in response payload', function () {
    Config::set('app.mobile_latest_version', '1.0.3');

    $driver = User::factory()->create();
    $driver->assignRole($this->driverRole);

    Sanctum::actingAs($driver);

    $response = $this->getJson('/api/driver/shifts/active');

    $response->assertSuccessful()
        ->assertJsonPath('app_version.latest_version', '1.0.3');
});
