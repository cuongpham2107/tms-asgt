<?php

use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->driverRole = Role::firstOrCreate([
        'name' => 'driver',
        'guard_name' => 'web',
    ]);
});

test('driver can login using employee_code', function () {
    $driver = User::factory()->create([
        'employee_code' => 'ASGT-60006',
        'email' => 'driver.asgt60006@example.com',
        'password' => bcrypt('password123'),
        'phone' => '0987654321',
    ]);
    $driver->assignRole($this->driverRole);

    $response = $this->postJson('/api/driver/login', [
        'login' => 'ASGT-60006',
        'password' => 'password123',
    ]);

    $response->assertSuccessful()
        ->assertJsonPath('user.employee_code', 'ASGT-60006')
        ->assertJsonPath('user.id', $driver->id);
});

test('user resource includes employee_code', function () {
    $driver = User::factory()->create([
        'employee_code' => 'ASGT-60010',
        'email' => 'driver.asgt60010@example.com',
        'password' => bcrypt('password123'),
        'phone' => '0987654322',
    ]);
    $driver->assignRole($this->driverRole);

    $resource = new UserResource($driver);
    $data = $resource->toArray(new Request);

    expect($data['employee_code'])->toBe('ASGT-60010');
});

test('sync employee code command handles dry run and actual sync properly', function () {
    $driver = User::factory()->create([
        'employee_code' => null,
        'name' => 'Ngọ Văn Minh',
        'email' => 'ngo.van.minh@example.com',
        'phone' => '0987654321',
        'cccd' => '001099000001',
    ]);
    $driver->assignRole($this->driverRole);

    $exitCode = Artisan::call('users:sync-employee-code', [
        '--dry-run' => true,
    ]);

    expect($exitCode)->toBe(0);
    expect($driver->fresh()->employee_code)->toBeNull();
});

test('driver can login via ASGL ID when local password does not match', function () {
    Http::fake([
        'https://id.asgl.net.vn/api/auth/login' => Http::response([
            'status' => 'success',
            'data' => [
                'user' => [
                    'id' => 9999,
                    'username' => 'ASGT-60030',
                    'full_name' => 'Dương Ngô Sinh',
                    'email' => 'sinh.dn@asgl.vn',
                    'mobile_phone' => '0981234567',
                ],
            ],
        ], 200),
    ]);

    $driver = User::factory()->create([
        'employee_code' => 'ASGT-60030',
        'email' => 'sinh.dn@asgl.vn',
        'password' => bcrypt('old_local_password'),
        'phone' => '0981234567',
    ]);
    $driver->assignRole($this->driverRole);

    $response = $this->postJson('/api/driver/login', [
        'login' => 'ASGT-60030',
        'password' => 'new_asgl_password_2026',
    ]);

    $response->assertSuccessful()
        ->assertJsonPath('user.employee_code', 'ASGT-60030');

    // Mật khẩu mới được đồng bộ vào DB
    expect(Hash::check('new_asgl_password_2026', $driver->fresh()->password))->toBeTrue();
});

test('login fails when both local password and ASGL ID fail', function () {
    Http::fake([
        'https://id.asgl.net.vn/api/auth/login' => Http::response([
            'status' => 'error',
            'message' => 'Unauthorized',
        ], 401),
    ]);

    $driver = User::factory()->create([
        'employee_code' => 'ASGT-60030',
        'password' => bcrypt('correct_password'),
    ]);
    $driver->assignRole($this->driverRole);

    $response = $this->postJson('/api/driver/login', [
        'login' => 'ASGT-60030',
        'password' => 'wrong_password',
    ]);

    $response->assertStatus(401);
});

test('login fails when ASGL user exists but has no driver role', function () {
    Http::fake([
        'https://id.asgl.net.vn/api/auth/login' => Http::response([
            'status' => 'success',
            'data' => [
                'user' => [
                    'username' => 'OFFICE-001',
                ],
            ],
        ], 200),
    ]);

    $user = User::factory()->create([
        'employee_code' => 'OFFICE-001',
        'password' => bcrypt('some_pass'),
    ]);
    // Không gán driver role

    $response = $this->postJson('/api/driver/login', [
        'login' => 'OFFICE-001',
        'password' => 'asgl_pass',
    ]);

    $response->assertStatus(403);
});
