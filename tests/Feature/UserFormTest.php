<?php

use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

test('user form email field validates uniqueness on create', function () {
    Gate::before(fn () => true);

    Role::firstOrCreate(['name' => 'driver', 'guard_name' => 'web']);
    $admin = User::factory()->create();
    User::factory()->create(['email' => 'duplicate@example.com']);

    $this->actingAs($admin);

    Livewire::test(ListUsers::class)
        ->mountAction('create')
        ->setActionData([
            'name' => 'New User',
            'email' => 'duplicate@example.com',
            'password' => 'secret123',
            'is_active' => true,
        ])
        ->callMountedAction()
        ->assertHasActionErrors(['email' => 'unique']);
});

test('user form allows creating with unique email', function () {
    Gate::before(fn () => true);

    Role::firstOrCreate(['name' => 'driver', 'guard_name' => 'web']);
    $admin = User::factory()->create();

    $this->actingAs($admin);

    Livewire::test(ListUsers::class)
        ->mountAction('create')
        ->setActionData([
            'name' => 'New User',
            'email' => 'unique_user@example.com',
            'password' => 'secret123',
            'is_active' => true,
        ])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect(User::where('email', 'unique_user@example.com')->exists())->toBeTrue();
});

test('user form ignores existing record email on edit', function () {
    Gate::before(fn () => true);

    $driverRole = Role::firstOrCreate(['name' => 'driver', 'guard_name' => 'web']);
    $admin = User::factory()->create();
    $driver = User::factory()->create([
        'name' => 'Driver User',
        'email' => 'driver@example.com',
    ]);
    $driver->assignRole($driverRole);

    $this->actingAs($admin);

    Livewire::test(ListUsers::class)
        ->mountTableAction('edit', $driver)
        ->setTableActionData([
            'name' => 'Driver User Updated',
            'email' => 'driver@example.com',
        ])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    expect($driver->fresh()->name)->toBe('Driver User Updated');
});

test('user form validates email uniqueness against other users on edit', function () {
    Gate::before(fn () => true);

    $driverRole = Role::firstOrCreate(['name' => 'driver', 'guard_name' => 'web']);
    $admin = User::factory()->create();
    User::factory()->create(['email' => 'other@example.com']);

    $driver = User::factory()->create([
        'name' => 'Driver User',
        'email' => 'driver@example.com',
    ]);
    $driver->assignRole($driverRole);

    $this->actingAs($admin);

    Livewire::test(ListUsers::class)
        ->mountTableAction('edit', $driver)
        ->setTableActionData([
            'name' => 'Driver User Updated',
            'email' => 'other@example.com',
        ])
        ->callMountedTableAction()
        ->assertHasTableActionErrors(['email' => 'unique']);
});
