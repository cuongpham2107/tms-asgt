<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ShieldAndUserSeeder extends Seeder
{
    public function run(): void
    {
        $filePath = __DIR__.'/data/shield_and_users.json';
        if (! file_exists($filePath)) {
            $this->command->warn('Không tìm thấy file '.$filePath);

            return;
        }

        $data = json_decode(file_get_contents($filePath), true);

        // 1. Roles
        if (! empty($data['roles'])) {
            foreach ($data['roles'] as $row) {
                DB::table('roles')->updateOrInsert(['id' => $row['id']], $row);
            }
            $this->command->info('Đã seed '.count($data['roles']).' vai trò (roles).');
        }

        // 2. Permissions
        if (! empty($data['permissions'])) {
            foreach (array_chunk($data['permissions'], 100) as $chunk) {
                foreach ($chunk as $row) {
                    DB::table('permissions')->updateOrInsert(['id' => $row['id']], $row);
                }
            }
            $this->command->info('Đã seed '.count($data['permissions']).' quyền hạn (permissions).');
        }

        // 3. Role Has Permissions
        if (! empty($data['role_has_permissions'])) {
            foreach (array_chunk($data['role_has_permissions'], 100) as $chunk) {
                foreach ($chunk as $row) {
                    DB::table('role_has_permissions')->updateOrInsert(
                        ['permission_id' => $row['permission_id'], 'role_id' => $row['role_id']],
                        $row
                    );
                }
            }
        }

        // 4. Users
        if (! empty($data['users'])) {
            foreach (array_chunk($data['users'], 50) as $chunk) {
                foreach ($chunk as $row) {
                    DB::table('users')->updateOrInsert(['id' => $row['id']], $row);
                }
            }
            $this->command->info('Đã seed '.count($data['users']).' người dùng / tài xế (users).');
        }

        // 5. Model Has Roles
        if (! empty($data['model_has_roles'])) {
            foreach (array_chunk($data['model_has_roles'], 50) as $chunk) {
                foreach ($chunk as $row) {
                    DB::table('model_has_roles')->updateOrInsert(
                        [
                            'role_id' => $row['role_id'],
                            'model_type' => $row['model_type'],
                            'model_id' => $row['model_id'],
                        ],
                        $row
                    );
                }
            }
        }
    }
}
