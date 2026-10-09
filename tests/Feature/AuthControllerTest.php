<?php

use App\Enums\ShiftType;
use App\Models\DriverShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
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

test('login returns active shift and prevents new shift when driver has an unended shift from yesterday', function () {
    $driver = User::factory()->create([
        'email' => 'driver4@example.com',
        'password' => bcrypt('password123'),
    ]);
    $driver->assignRole($this->driverRole);

    // Shift started yesterday and never ended
    $yesterdayShift = DriverShift::create([
        'driver_id' => $driver->id,
        'shift_type' => ShiftType::Full,
        'start_time' => now()->subDay()->setTime(8, 0, 0),
        'end_time' => null,
    ]);

    $response = $this->postJson('/api/driver/login', [
        'email' => 'driver4@example.com',
        'password' => 'password123',
    ]);

    $response->assertSuccessful()
        ->assertJsonPath('shift.id', $yesterdayShift->id);

    // Driver cannot start a new shift today while yesterday's shift is unended
    $token = $response->json('token');
    $startResponse = $this->withHeader('Authorization', 'Bearer '.$token)
        ->postJson('/api/driver/shifts/start', [
            'shift_type' => ShiftType::Full->value,
            'start_time' => now()->toIso8601String(),
        ]);

    $startResponse->assertStatus(409)
        ->assertJsonPath('message', 'Bạn đã có một ca làm việc đang hoạt động');

    // Current shift endpoint also returns the yesterday shift
    $currentResponse = $this->withHeader('Authorization', 'Bearer '.$token)
        ->getJson('/api/driver/shifts/current');
    $currentResponse->assertSuccessful()
        ->assertJsonPath('shift.id', $yesterdayShift->id);

    // Driver can end yesterday's shift today
    $endResponse = $this->withHeader('Authorization', 'Bearer '.$token)
        ->postJson('/api/driver/shifts/end', [
            'end_time' => now()->toIso8601String(),
            'end_km' => 100,
        ]);
    $endResponse->assertSuccessful();

    $yesterdayShift->refresh();
    expect($yesterdayShift->end_time)->not->toBeNull();
});

test('driver can request account deletion', function () {
    $driver = User::factory()->create([
        'email' => 'driver_delete@example.com',
        'password' => bcrypt('password123'),
        'fcm_token' => 'some_token_value',
    ]);
    $driver->assignRole($this->driverRole);

    $token = $driver->createToken('test')->plainTextToken;

    $response = $this->withHeader('Authorization', 'Bearer '.$token)
        ->postJson('/api/driver/account/delete-request');

    $response->assertSuccessful()
        ->assertJsonStructure(['message']);

    $driver->refresh();
    expect($driver->fcm_token)->toBeNull();
    expect($driver->tokens()->count())->toBe(0);
});

test('public can view privacy policy page', function () {
    $response = $this->get('/privacy-policy');
    $response->assertSuccessful()
        ->assertSee('Chính sách quyền riêng tư');
});

test('driver can login using phone number', function () {
    $driver = User::factory()->create([
        'email' => 'driver_phone@example.com',
        'phone' => '0965455995',
        'password' => bcrypt('password123'),
    ]);
    $driver->assignRole($this->driverRole);

    $response = $this->postJson('/api/driver/login', [
        'email' => '0965455995',
        'password' => 'password123',
    ]);

    $response->assertSuccessful()
        ->assertJsonPath('user.id', $driver->id)
        ->assertJsonStructure(['user', 'token']);
});

test('driver can change password', function () {
    $driver = User::factory()->create([
        'email' => 'driver_cp@example.com',
        'password' => bcrypt('oldPassword123'),
    ]);
    $driver->assignRole($this->driverRole);

    $token = $driver->createToken('test')->plainTextToken;

    // Fail: wrong current password
    $resFail = $this->withHeader('Authorization', 'Bearer '.$token)
        ->postJson('/api/driver/change-password', [
            'current_password' => 'wrongPassword',
            'new_password' => 'newPassword456',
            'new_password_confirmation' => 'newPassword456',
        ]);
    $resFail->assertStatus(422)
        ->assertJsonPath('message', 'Mật khẩu hiện tại không chính xác');

    // Fail: mismatch confirmation
    $resMismatch = $this->withHeader('Authorization', 'Bearer '.$token)
        ->postJson('/api/driver/change-password', [
            'current_password' => 'oldPassword123',
            'new_password' => 'newPassword456',
            'new_password_confirmation' => 'differentPassword',
        ]);
    $resMismatch->assertStatus(422);

    // Success
    $resSuccess = $this->withHeader('Authorization', 'Bearer '.$token)
        ->postJson('/api/driver/change-password', [
            'current_password' => 'oldPassword123',
            'new_password' => 'newPassword456',
            'new_password_confirmation' => 'newPassword456',
        ]);
    $resSuccess->assertSuccessful()
        ->assertJsonPath('message', 'Đổi mật khẩu thành công');

    // Verify login with new password succeeds
    $driver->refresh();
    expect(Hash::check('newPassword456', $driver->password))->toBeTrue();
});

test('login prioritizes driver account when multiple users share the same phone number', function () {
    $nonDriver = User::factory()->create([
        'email' => 'regular_user@example.com',
        'phone' => '0964181383',
        'password' => bcrypt('password_nondriver'),
    ]);

    $driver = User::factory()->create([
        'email' => 'driver_user@example.com',
        'phone' => '0964181383',
        'password' => bcrypt('password_driver'),
    ]);
    $driver->assignRole($this->driverRole);

    $response = $this->postJson('/api/driver/login', [
        'login' => '0964181383',
        'password' => 'password_driver',
    ]);

    $response->assertSuccessful()
        ->assertJsonPath('user.id', $driver->id);
});

test('login rejects user without driver role', function () {
    $nonDriver = User::factory()->create([
        'email' => 'nondriver@example.com',
        'phone' => '0988888888',
        'password' => bcrypt('password123'),
    ]);

    $response = $this->postJson('/api/driver/login', [
        'login' => '0988888888',
        'password' => 'password123',
    ]);

    $response->assertStatus(403)
        ->assertJsonPath('message', 'Tài khoản này không có quyền tài xế');
});
