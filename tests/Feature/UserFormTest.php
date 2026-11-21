<?php

use App\Filament\Pages\StaffAccounts;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
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

/**
 * Tạo tài khoản nhân viên (không phải super_admin) chỉ có các quyền được truyền vào.
 *
 * @param  list<string>  $permissions
 */
function userFormStaffActor(array $permissions): User
{
    foreach ($permissions as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }

    $role = Role::firstOrCreate(['name' => 'Nhân viên điều phối', 'guard_name' => 'web']);
    $role->syncPermissions($permissions);

    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole($role);

    return $user;
}

test('staff cannot create a super_admin account from the driver form', function () {
    Role::firstOrCreate(['name' => 'driver', 'guard_name' => 'web']);
    $superAdminRole = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);

    $this->actingAs(userFormStaffActor(['ViewAny:User', 'View:User', 'Create:User', 'Update:User']));

    Livewire::test(ListUsers::class)
        ->mountAction('create')
        ->setActionData([
            'name' => 'Tài khoản lạ',
            'email' => 'escalate@example.com',
            'password' => 'secret123',
            'is_active' => true,
            'roles' => [$superAdminRole->id],
        ])
        ->callMountedAction()
        ->assertHasActionErrors(['roles.0']);

    expect(User::role('super_admin')->exists())->toBeFalse();
});

test('staff cannot promote an existing driver to super_admin from the driver form', function () {
    $driverRole = Role::firstOrCreate(['name' => 'driver', 'guard_name' => 'web']);
    $superAdminRole = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    $driver = User::factory()->create(['email' => 'driver-promote@example.com']);
    $driver->assignRole($driverRole);

    $this->actingAs(userFormStaffActor(['ViewAny:User', 'View:User', 'Create:User', 'Update:User']));

    Livewire::test(ListUsers::class)
        ->mountTableAction('edit', $driver)
        ->setTableActionData([
            'roles' => [$driverRole->id, $superAdminRole->id],
        ])
        ->callMountedTableAction()
        ->assertHasTableActionErrors(['roles.1']);

    expect($driver->fresh()->hasRole('super_admin'))->toBeFalse();
});

test('staff creating a driver still gets the driver role by default', function () {
    Role::firstOrCreate(['name' => 'driver', 'guard_name' => 'web']);

    $this->actingAs(userFormStaffActor(['ViewAny:User', 'View:User', 'Create:User', 'Update:User']));

    Livewire::test(ListUsers::class)
        ->mountAction('create')
        ->setActionData([
            'name' => 'Lái xe mới',
            'email' => 'new-driver@example.com',
            'password' => 'secret123',
            'is_active' => true,
        ])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect(User::where('email', 'new-driver@example.com')->first()->getRoleNames()->all())->toBe(['driver']);
});

test('super_admin can still assign any role from the driver form', function () {
    Role::firstOrCreate(['name' => 'driver', 'guard_name' => 'web']);
    $staffRole = Role::firstOrCreate(['name' => 'Nhân viên điều phối', 'guard_name' => 'web']);
    $superAdminRole = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    foreach (['ViewAny:User', 'View:User', 'Create:User', 'Update:User'] as $permission) {
        $superAdminRole->givePermissionTo(Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']));
    }
    $superAdmin = User::factory()->create(['is_active' => true]);
    $superAdmin->assignRole($superAdminRole);

    $this->actingAs($superAdmin);

    Livewire::test(ListUsers::class)
        ->mountAction('create')
        ->setActionData([
            'name' => 'Nhân viên mới',
            'email' => 'new-staff@example.com',
            'password' => 'secret123',
            'is_active' => true,
            'roles' => [$staffRole->id],
        ])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect(User::where('email', 'new-staff@example.com')->first()->hasRole('Nhân viên điều phối'))->toBeTrue();
});

test('staff account managers cannot grant super_admin on the staff accounts page', function () {
    $superAdminRole = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);

    $this->actingAs(userFormStaffActor(['View:StaffAccounts', 'Manage:StaffAccounts']));

    Livewire::test(StaffAccounts::class)
        ->mountTableAction('create')
        ->setTableActionData([
            'name' => 'Tài khoản lạ',
            'email' => 'staff-escalate@example.com',
            'password' => 'secret123',
            'is_active' => true,
            'roles' => [$superAdminRole->id],
        ])
        ->callMountedTableAction()
        ->assertHasTableActionErrors(['roles.0']);

    expect(User::role('super_admin')->exists())->toBeFalse();
});
