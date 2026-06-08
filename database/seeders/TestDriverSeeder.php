<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class TestDriverSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();
        $pwd = Hash::make('password');

        $driverRole = Role::firstOrCreate(['name' => 'driver', 'guard_name' => 'web']);

        $drivers = [
            ['name' => 'Nguyễn Văn A', 'email' => 'driver.a@example.com', 'phone' => '0901000001', 'cccd' => '001200000001', 'license_class' => 'C', 'license_number' => 'C2000001'],
            ['name' => 'Nguyễn Văn B', 'email' => 'driver.b@example.com', 'phone' => '0901000002', 'cccd' => '001200000002', 'license_class' => 'C', 'license_number' => 'C2000002'],
            ['name' => 'Nguyễn Văn C', 'email' => 'driver.c@example.com', 'phone' => '0901000003', 'cccd' => '001200000003', 'license_class' => 'C', 'license_number' => 'C2000003'],
            ['name' => 'Trần Thị D', 'email' => 'driver.d@example.com', 'phone' => '0901000004', 'cccd' => '001200000004', 'license_class' => 'B', 'license_number' => 'B2000001'],
            ['name' => 'Lê Văn E', 'email' => 'driver.e@example.com', 'phone' => '0901000005', 'cccd' => '001200000005', 'license_class' => 'B', 'license_number' => 'B2000002'],
        ];

        foreach ($drivers as $driver) {
            DB::table('users')->upsert(
                [
                    'name' => $driver['name'],
                    'email' => $driver['email'],
                    'password' => $pwd,
                    'email_verified_at' => $now,
                    'phone' => $driver['phone'],
                    'cccd' => $driver['cccd'],
                    'license_class' => $driver['license_class'],
                    'license_number' => $driver['license_number'],
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                ['email'],
                ['name', 'password', 'email_verified_at', 'phone', 'cccd', 'license_class', 'license_number', 'is_active', 'updated_at']
            );

            $userId = DB::table('users')->where('email', $driver['email'])->value('id');

            DB::table('model_has_roles')->upsert(
                [
                    'role_id' => $driverRole->id,
                    'model_type' => 'App\Models\User',
                    'model_id' => $userId,
                ],
                ['role_id', 'model_id', 'model_type'],
                ['role_id', 'model_id', 'model_type']
            );
        }

        $this->command?->info('Đã tạo 5 tài xế test: driver.a@example.com … driver.e@example.com (password: password)');
    }
}
