<?php

use App\Enums\TripStatus;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use Carbon\Carbon;
use Database\Seeders\RolePermissionMatrixSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Lệnh kiểm tra km cuối ngày: gửi thông báo có nút "Sửa chuyến" cho Quản lý điều phối và Phó TP Vận Tải.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-11-19 23:30:00');

    foreach (['driver', RolePermissionMatrixSeeder::ROLE_DISPATCH_LEAD, RolePermissionMatrixSeeder::ROLE_DEPUTY_DIRECTOR, RolePermissionMatrixSeeder::ROLE_DISPATCH_STAFF] as $role) {
        $permissions = collect(RolePermissionMatrixSeeder::notificationPermissions($role))
            ->map(fn (string $name): Permission => Permission::findOrCreate($name, 'web'));
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web'])->givePermissionTo($permissions);
    }

    $this->lead = User::factory()->create(['is_active' => true]);
    $this->lead->assignRole(RolePermissionMatrixSeeder::ROLE_DISPATCH_LEAD);
    $this->deputy = User::factory()->create(['is_active' => true]);
    $this->deputy->assignRole(RolePermissionMatrixSeeder::ROLE_DEPUTY_DIRECTOR);
    $this->dispatcher = User::factory()->create(['name' => 'Điều phối A', 'is_active' => true]);
    $this->dispatcher->assignRole(RolePermissionMatrixSeeder::ROLE_DISPATCH_STAFF);

    $this->driver = User::factory()->create(['is_active' => true]);
    $this->driver->assignRole('driver');
    $this->vehicle = Vehicle::factory()->create(['plate_number' => '20C18325']);

    $this->makeTrip = fn (string $code, string $start, float $startKm, float $endKm): Trip => Trip::factory()->create([
        'trip_code' => $code,
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'status' => TripStatus::Completed,
        'started_at' => Carbon::parse($start),
        'completed_at' => Carbon::parse($start)->addHours(2),
        'start_km' => $startKm,
        'end_km' => $endKm,
        'total_km' => $endKm - $startKm,
    ]);
});

test('notifies dispatch leads and the deputy director with a button that opens the trip edit form', function () {
    $trip = ($this->makeTrip)('CD-TYPO-1', '2026-11-19 19:20', 322084, 322168);
    $this->actingAs($this->dispatcher);
    $trip->update(['start_km' => 32084, 'total_km' => 290084]);
    auth()->logout();

    $this->artisan('trips:audit-km')->assertSuccessful();

    foreach ([$this->lead, $this->deputy] as $recipient) {
        $notification = $recipient->notifications()->sole();

        expect($notification->data['title'])->toContain('CD-TYPO-1')
            ->and($notification->data['body'])->toContain('Điều phối A')
            ->and(json_encode($notification->data['actions']))->toContain('tableAction=edit')
            ->and(json_encode($notification->data['actions']))->toContain('tableActionRecord='.$trip->id);
    }

    expect($this->dispatcher->notifications()->count())->toBe(0)
        ->and($this->driver->notifications()->count())->toBe(0);
});

test('does not send the same issue twice when the check runs again', function () {
    $trip = ($this->makeTrip)('CD-TYPO-2', '2026-11-19 19:20', 322084, 322168);
    $trip->update(['start_km' => 32084, 'total_km' => 290084]);

    $this->artisan('trips:audit-km')->assertSuccessful();
    $this->artisan('trips:audit-km')->assertSuccessful();

    expect($this->lead->notifications()->count())->toBe(1);
});

test('sends nothing when km data is clean', function () {
    ($this->makeTrip)('CD-CLEAN-1', '2026-11-19 08:00', 1000, 1040);

    $this->artisan('trips:audit-km')
        ->expectsOutputToContain('Không phát hiện')
        ->assertSuccessful();

    expect($this->lead->notifications()->count())->toBe(0);
});

test('is scheduled every evening', function () {
    $this->artisan('schedule:list')
        ->expectsOutputToContain('trips:audit-km')
        ->assertSuccessful();
});
