<?php

use App\Livewire\DataAssistantChat;
use BezhanSalleh\FilamentShield\Support\Utils;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Tạo quyền tùy chỉnh "Use:DataAssistant" (khai báo ở config filament-shield.custom_permissions)
 * và gán sẵn cho super_admin, vì Shield ở dự án này không cho super_admin vượt quyền qua Gate.
 */
return new class extends Migration
{
    public function up(): void
    {
        $permission = Permission::firstOrCreate(['name' => DataAssistantChat::PERMISSION, 'guard_name' => 'web']);

        Role::query()
            ->where('name', Utils::getSuperAdminName())
            ->where('guard_name', 'web')
            ->first()
            ?->givePermissionTo($permission);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->where('name', DataAssistantChat::PERMISSION)->where('guard_name', 'web')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
