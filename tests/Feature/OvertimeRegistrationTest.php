<?php

use App\Enums\OvertimeStatus;
use App\Enums\ShiftType;
use App\Filament\Resources\OvertimeRegistrations\Pages\ListOvertimeRegistrations;
use App\Models\DriverShift;
use App\Models\OvertimeRegistration;
use App\Models\User;
use App\Services\Notification\DriverNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\CloudMessage;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->driverRole = Role::create([
        'name' => 'driver',
        'guard_name' => 'web',
    ]);
});

test('driver can list their overtime registrations', function () {
    $driver = User::factory()->create();
    $driver->assignRole($this->driverRole);
    Sanctum::actingAs($driver);

    OvertimeRegistration::create([
        'driver_id' => $driver->id,
        'shift_type' => ShiftType::Full,
        'overtime_date' => now()->addDays(2)->format('Y-m-d'),
        'status' => OvertimeStatus::Pending,
        'notes' => 'Tăng cường cuối tuần',
    ]);

    $otherDriver = User::factory()->create();
    $otherDriver->assignRole($this->driverRole);
    OvertimeRegistration::create([
        'driver_id' => $otherDriver->id,
        'shift_type' => ShiftType::MorningHalf,
        'overtime_date' => now()->addDays(2)->format('Y-m-d'),
        'status' => OvertimeStatus::Pending,
    ]);

    $response = $this->getJson('/api/driver/overtime-registrations');

    $response->assertSuccessful()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.shift_type', 'full')
        ->assertJsonPath('data.0.status', 'pending')
        ->assertJsonPath('data.0.notes', 'Tăng cường cuối tuần');
});

test('driver can register overtime for future date', function () {
    $driver = User::factory()->create();
    $driver->assignRole($this->driverRole);
    Sanctum::actingAs($driver);

    $futureDate = now()->addDays(3)->format('Y-m-d');

    $response = $this->postJson('/api/driver/overtime-registrations', [
        'shift_type' => 'full',
        'overtime_date' => $futureDate,
        'notes' => 'Đăng ký ca 24h',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.shift_type', 'full')
        ->assertJsonPath('data.overtime_date', $futureDate)
        ->assertJsonPath('data.status', 'pending');

    expect(OvertimeRegistration::count())->toBe(1)
        ->and(OvertimeRegistration::first()->driver_id)->toBe($driver->id);
});

test('driver cannot register overtime for past date', function () {
    $driver = User::factory()->create();
    $driver->assignRole($this->driverRole);
    Sanctum::actingAs($driver);

    $pastDate = now()->subDays(2)->format('Y-m-d');

    $response = $this->postJson('/api/driver/overtime-registrations', [
        'shift_type' => 'morning_half',
        'overtime_date' => $pastDate,
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['overtime_date']);
});

test('driver cannot register duplicate overtime on the same date', function () {
    $driver = User::factory()->create();
    $driver->assignRole($this->driverRole);
    Sanctum::actingAs($driver);

    $targetDate = now()->addDays(4)->format('Y-m-d');

    OvertimeRegistration::create([
        'driver_id' => $driver->id,
        'shift_type' => ShiftType::MorningHalf,
        'overtime_date' => $targetDate,
        'status' => OvertimeStatus::Pending,
    ]);

    $response = $this->postJson('/api/driver/overtime-registrations', [
        'shift_type' => 'full',
        'overtime_date' => $targetDate,
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['overtime_date']);
});

test('driver can cancel pending overtime registration', function () {
    $driver = User::factory()->create();
    $driver->assignRole($this->driverRole);
    Sanctum::actingAs($driver);

    $registration = OvertimeRegistration::create([
        'driver_id' => $driver->id,
        'shift_type' => ShiftType::MorningHalf,
        'overtime_date' => now()->addDays(5)->format('Y-m-d'),
        'status' => OvertimeStatus::Pending,
    ]);

    $response = $this->deleteJson("/api/driver/overtime-registrations/{$registration->id}");

    $response->assertSuccessful();
    expect(OvertimeRegistration::count())->toBe(0);
});

test('driver cannot cancel confirmed overtime registration', function () {
    $driver = User::factory()->create();
    $driver->assignRole($this->driverRole);
    Sanctum::actingAs($driver);

    $registration = OvertimeRegistration::create([
        'driver_id' => $driver->id,
        'shift_type' => ShiftType::MorningHalf,
        'overtime_date' => now()->addDays(5)->format('Y-m-d'),
        'status' => OvertimeStatus::Confirmed,
    ]);

    $response = $this->deleteJson("/api/driver/overtime-registrations/{$registration->id}");

    $response->assertStatus(422);
    expect(OvertimeRegistration::count())->toBe(1);
});

test('driver cannot cancel another drivers overtime registration', function () {
    $driver1 = User::factory()->create();
    $driver1->assignRole($this->driverRole);

    $driver2 = User::factory()->create();
    $driver2->assignRole($this->driverRole);

    Sanctum::actingAs($driver1);

    $registration = OvertimeRegistration::create([
        'driver_id' => $driver2->id,
        'shift_type' => ShiftType::MorningHalf,
        'overtime_date' => now()->addDays(5)->format('Y-m-d'),
        'status' => OvertimeStatus::Pending,
    ]);

    $response = $this->deleteJson("/api/driver/overtime-registrations/{$registration->id}");

    $response->assertStatus(403);
    expect(OvertimeRegistration::count())->toBe(1);
});

test('driver notification service sends push notification when overtime confirmed', function () {
    $driver = User::factory()->create(['name' => 'Nguyễn Văn A', 'fcm_token' => 'driver_ot_token']);
    $registration = OvertimeRegistration::create([
        'driver_id' => $driver->id,
        'shift_type' => ShiftType::Full,
        'overtime_date' => '2026-03-20',
        'status' => OvertimeStatus::Confirmed,
    ]);

    $mockMessaging = Mockery::mock(Messaging::class);
    $mockMessaging->shouldReceive('send')->once()->with(Mockery::on(function (CloudMessage $message) {
        $data = $message->jsonSerialize();

        return $data['token'] === 'driver_ot_token'
            && str_contains($data['notification']['title'], 'Xác nhận tăng cường')
            && str_contains($data['notification']['body'], 'Nguyễn Văn A')
            && ($data['data']['type'] ?? null) === 'overtime_confirmed';
    }))->andReturn([]);

    $service = new DriverNotificationService($mockMessaging);
    $result = $service->sendOvertimeConfirmed($registration);

    expect($result)->toBeTrue();
});

test('driver notification service sends push notification when overtime rejected', function () {
    $driver = User::factory()->create(['name' => 'Nguyễn Văn B', 'fcm_token' => 'driver_ot_token_2']);
    $registration = OvertimeRegistration::create([
        'driver_id' => $driver->id,
        'shift_type' => ShiftType::MorningHalf,
        'overtime_date' => '2026-03-21',
        'status' => OvertimeStatus::Rejected,
    ]);

    $mockMessaging = Mockery::mock(Messaging::class);
    $mockMessaging->shouldReceive('send')->once()->with(Mockery::on(function (CloudMessage $message) {
        $data = $message->jsonSerialize();

        return $data['token'] === 'driver_ot_token_2'
            && str_contains($data['notification']['title'], 'Đăng ký tăng cường')
            && str_contains($data['notification']['body'], 'không thành công')
            && ($data['data']['type'] ?? null) === 'overtime_rejected';
    }))->andReturn([]);

    $service = new DriverNotificationService($mockMessaging);
    $result = $service->sendOvertimeRejected($registration);

    expect($result)->toBeTrue();
});

test('admin can confirm overtime registration and creates driver shift with is_overtime true', function () {
    Gate::before(fn () => true);

    $admin = User::factory()->create();
    $driver = User::factory()->create(['name' => 'Tài Xế Duyệt', 'fcm_token' => 'token_admin_test']);
    $driver->assignRole($this->driverRole);

    $registration = OvertimeRegistration::create([
        'driver_id' => $driver->id,
        'shift_type' => ShiftType::Full,
        'overtime_date' => '2026-03-25',
        'status' => OvertimeStatus::Pending,
        'notes' => 'Tăng cường',
    ]);

    $mockMessaging = Mockery::mock(Messaging::class);
    $mockMessaging->shouldReceive('send')->once()->andReturn([]);
    app()->instance(DriverNotificationService::class, new DriverNotificationService($mockMessaging));

    $this->actingAs($admin);

    Livewire::test(ListOvertimeRegistrations::class)
        ->callTableAction('confirm', $registration)
        ->assertHasNoTableActionErrors();

    $registration->refresh();
    expect($registration->status)->toBe(OvertimeStatus::Confirmed)
        ->and($registration->confirmed_by)->toBe($admin->id)
        ->and($registration->confirmed_at)->not->toBeNull();

    $shift = DriverShift::where('driver_id', $driver->id)->first();
    expect($shift)->not->toBeNull()
        ->and($shift->is_overtime)->toBeTrue()
        ->and($shift->shift_type)->toBe(ShiftType::Full);
});

test('admin can reject overtime registration', function () {
    Gate::before(fn () => true);

    $admin = User::factory()->create();
    $driver = User::factory()->create(['name' => 'Tài Xế Từ Chối', 'fcm_token' => 'token_admin_test_2']);
    $driver->assignRole($this->driverRole);

    $registration = OvertimeRegistration::create([
        'driver_id' => $driver->id,
        'shift_type' => ShiftType::NightHalf,
        'overtime_date' => '2026-03-26',
        'status' => OvertimeStatus::Pending,
    ]);

    $mockMessaging = Mockery::mock(Messaging::class);
    $mockMessaging->shouldReceive('send')->once()->andReturn([]);
    app()->instance(DriverNotificationService::class, new DriverNotificationService($mockMessaging));

    $this->actingAs($admin);

    Livewire::test(ListOvertimeRegistrations::class)
        ->callTableAction('reject', $registration)
        ->assertHasNoTableActionErrors();

    $registration->refresh();
    expect($registration->status)->toBe(OvertimeStatus::Rejected)
        ->and($registration->confirmed_by)->toBe($admin->id)
        ->and($registration->confirmed_at)->not->toBeNull();

    expect(DriverShift::where('driver_id', $driver->id)->count())->toBe(0);
});
