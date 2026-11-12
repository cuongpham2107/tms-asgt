<?php

use App\Filament\Resources\Orders\Actions\Concerns\CreatesOrderTransportCards;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::firstOrCreate(['name' => 'driver', 'guard_name' => 'web']);
});

test('resolveDriverCards excludes inactive drivers', function () {
    $activeDriver = User::factory()->create([
        'name' => 'Active Driver',
        'is_active' => true,
    ]);
    $activeDriver->assignRole('driver');

    $inactiveDriver = User::factory()->create([
        'name' => 'Inactive Driver',
        'is_active' => false,
    ]);
    $inactiveDriver->assignRole('driver');

    CreatesOrderTransportCards::clearTransportCardsCache();

    $helper = new class extends CreatesOrderTransportCards
    {
        public static function getCards(): array
        {
            return self::resolveDriverCards();
        }
    };

    $cards = $helper::getCards();
    $cardValues = collect($cards)->pluck('value')->all();

    expect($cardValues)->toContain($activeDriver->id)
        ->and($cardValues)->not->toContain($inactiveDriver->id);
});

test('deactivating a driver unlinks them from vehicles', function () {
    $driver = User::factory()->create(['is_active' => true]);
    $driver->assignRole('driver');

    $vehicle = Vehicle::factory()->create([
        'even_driver_id' => $driver->id,
        'current_driver_id' => $driver->id,
    ]);

    $driver->update(['is_active' => false]);

    $vehicle->refresh();
    expect($vehicle->even_driver_id)->toBeNull()
        ->and($vehicle->current_driver_id)->toBeNull();
});

test('vehicle getDriverForToday ignores inactive drivers', function () {
    $inactiveDriver = User::factory()->create(['is_active' => false]);
    $inactiveDriver->assignRole('driver');

    $vehicle = Vehicle::factory()->create([
        'current_driver_id' => $inactiveDriver->id,
    ]);

    expect($vehicle->getDriverForToday())->toBeNull();
});
