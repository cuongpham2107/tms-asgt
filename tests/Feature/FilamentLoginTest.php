<?php

use App\Filament\Pages\Auth\Login;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
});

test('login page can be rendered', function () {
    $this->get('/app/login')
        ->assertSuccessful()
        ->assertSee('Email hoặc Mã nhân viên');
});

test('user can authenticate using email', function () {
    $user = User::factory()->create([
        'email' => 'testuser@asgt.vn',
        'employee_code' => 'ASGT-99999',
        'password' => Hash::make('66668888'),
        'is_active' => true,
    ]);
    $user->assignRole('super_admin');

    Livewire::test(Login::class)
        ->fillForm([
            'email' => 'testuser@asgt.vn',
            'password' => '66668888',
        ])
        ->call('authenticate')
        ->assertHasNoFormErrors();

    $this->assertAuthenticatedAs($user);
});

test('user can authenticate using exact employee code', function () {
    $user = User::factory()->create([
        'email' => 'testuser@asgt.vn',
        'employee_code' => 'ASGT-00695',
        'password' => Hash::make('66668888'),
        'is_active' => true,
    ]);
    $user->assignRole('super_admin');

    Livewire::test(Login::class)
        ->fillForm([
            'email' => 'ASGT-00695',
            'password' => '66668888',
        ])
        ->call('authenticate')
        ->assertHasNoFormErrors();

    $this->assertAuthenticatedAs($user);
});

test('user can authenticate using lowercase employee code', function () {
    $user = User::factory()->create([
        'email' => 'testuser@asgt.vn',
        'employee_code' => 'ASGT-00695',
        'password' => Hash::make('66668888'),
        'is_active' => true,
    ]);
    $user->assignRole('super_admin');

    Livewire::test(Login::class)
        ->fillForm([
            'email' => 'asgt-00695',
            'password' => '66668888',
        ])
        ->call('authenticate')
        ->assertHasNoFormErrors();

    $this->assertAuthenticatedAs($user);
});

test('user can authenticate using employee code without hyphen', function () {
    $user = User::factory()->create([
        'email' => 'testuser@asgt.vn',
        'employee_code' => 'ASGT-00695',
        'password' => Hash::make('66668888'),
        'is_active' => true,
    ]);
    $user->assignRole('super_admin');

    Livewire::test(Login::class)
        ->fillForm([
            'email' => 'asgt00695',
            'password' => '66668888',
        ])
        ->call('authenticate')
        ->assertHasNoFormErrors();

    $this->assertAuthenticatedAs($user);
});

test('user cannot authenticate with incorrect password', function () {
    User::factory()->create([
        'email' => 'testuser@asgt.vn',
        'employee_code' => 'ASGT-00695',
        'password' => Hash::make('66668888'),
        'is_active' => true,
    ]);

    Livewire::test(Login::class)
        ->fillForm([
            'email' => 'ASGT-00695',
            'password' => 'wrong-password',
        ])
        ->call('authenticate')
        ->assertHasFormErrors(['email']);

    $this->assertGuest();
});
