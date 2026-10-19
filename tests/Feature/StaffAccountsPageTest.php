<?php

use App\Filament\Pages\StaffAccounts;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->superAdminRole = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    $this->driverRole = Role::firstOrCreate(['name' => 'driver', 'guard_name' => 'web']);

    $this->admin = User::factory()->create([
        'email' => 'admin@test.local',
        'employee_code' => 'ADMIN-001',
        'is_active' => true,
    ]);
    $this->admin->assignRole($this->superAdminRole);
});

test('super admin can access staff accounts page', function () {
    $this->actingAs($this->admin)
        ->get('/app/nhan-vien')
        ->assertSuccessful()
        ->assertSee('Tài khoản nhân viên');
});

test('driver cannot access staff accounts page', function () {
    $driver = User::factory()->create([
        'email' => 'driver@test.local',
        'employee_code' => 'DRV-001',
        'is_active' => true,
    ]);
    $driver->assignRole($this->driverRole);

    $this->actingAs($driver)
        ->get('/app/nhan-vien')
        ->assertForbidden();
});

test('page displays only non-driver accounts in the table', function () {
    $staff = User::factory()->create([
        'name' => 'Staff Member',
        'email' => 'staff@test.local',
        'employee_code' => 'ASGT-00999',
        'is_active' => true,
    ]);
    $staff->assignRole($this->superAdminRole);

    $driver = User::factory()->create([
        'name' => 'Driver User',
        'email' => 'driver@test.local',
        'employee_code' => 'DRV-88888',
        'is_active' => true,
    ]);
    $driver->assignRole($this->driverRole);

    Livewire::actingAs($this->admin)
        ->test(StaffAccounts::class)
        ->assertCanSeeTableRecords([$staff, $this->admin])
        ->assertCanNotSeeTableRecords([$driver]);
});

test('super admin can create a new staff account from the page', function () {
    Livewire::actingAs($this->admin)
        ->test(StaffAccounts::class)
        ->callTableAction('create', data: [
            'employee_code' => 'ASGT-00555',
            'name' => 'New Staff Person',
            'email' => 'newstaff@asgt.vn',
            'password' => '66668888',
            'phone' => '0912345678',
            'roles' => [$this->superAdminRole->id],
            'is_active' => true,
        ])
        ->assertHasNoTableActionErrors();

    $newUser = User::where('employee_code', 'ASGT-00555')->first();
    expect($newUser)->not->toBeNull()
        ->and($newUser->name)->toBe('New Staff Person')
        ->and($newUser->hasRole('super_admin'))->toBeTrue()
        ->and(Hash::check('66668888', $newUser->password))->toBeTrue();
});
