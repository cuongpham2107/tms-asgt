<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionMatrixSeeder extends Seeder
{
    public const ROLE_DEPUTY_DIRECTOR = 'Phó TP Vận Tải';

    public const ROLE_BILLING_LEAD = 'Trưởng nhóm thanh toán';

    public const ROLE_BILLING_STAFF = 'Nhân viên thanh toán';

    public const ROLE_DISPATCH_LEAD = 'Quản lý điều phối';

    public const ROLE_DISPATCH_STAFF = 'Nhân viên điều phối';

    /**
     * Cấu hình 5 vai trò theo ma trận 6 menu (X: chỉ xem, Y: xem/tạo/sửa/hủy, Z: Y + xóa).
     *
     * @return array<string, array{operations: ?string, billing: ?string, management: ?string, activity: ?string, config: ?string, maintenance: ?string}>
     */
    public static function roleMatrix(): array
    {
        return [
            self::ROLE_DEPUTY_DIRECTOR => [
                'operations' => 'Z',
                'billing' => 'Z',
                'management' => 'Z',
                'activity' => 'Z',
                'config' => 'Z',
                'maintenance' => 'Z',
            ],
            self::ROLE_BILLING_LEAD => [
                'operations' => 'Y',
                'billing' => 'Z',
                'management' => 'Y',
                'activity' => 'Y',
                'config' => 'Y',
                'maintenance' => 'Y',
            ],
            self::ROLE_BILLING_STAFF => [
                'operations' => 'Y',
                'billing' => 'Y',
                'management' => 'Y',
                'activity' => null,
                'config' => null,
                'maintenance' => null,
            ],
            self::ROLE_DISPATCH_LEAD => [
                'operations' => 'Y',
                'billing' => 'X',
                'management' => 'Y',
                'activity' => 'Y',
                'config' => 'Y',
                'maintenance' => 'Y',
            ],
            self::ROLE_DISPATCH_STAFF => [
                'operations' => 'Y',
                'billing' => null,
                'management' => 'Y',
                'activity' => 'Y',
                'config' => null,
                'maintenance' => 'Y',
            ],
        ];
    }

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        static::syncRolesAndPermissions();
        $this->syncStaffUsers();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Đồng bộ toàn bộ permission và 5 role theo ma trận phân quyền.
     */
    public static function syncRolesAndPermissions(): void
    {
        $allPermissions = collect(static::allKnownPermissionNames())
            ->unique()
            ->values();

        foreach ($allPermissions as $permissionName) {
            Permission::firstOrCreate([
                'name' => $permissionName,
                'guard_name' => 'web',
            ]);
        }

        $superAdmin = Role::firstOrCreate([
            'name' => 'super_admin',
            'guard_name' => 'web',
        ]);
        $superAdmin->syncPermissions(Permission::where('guard_name', 'web')->pluck('name')->all());

        foreach (static::roleMatrix() as $roleName => $menus) {
            $role = Role::firstOrCreate([
                'name' => $roleName,
                'guard_name' => 'web',
            ]);

            $permissions = static::permissionsForMenus($menus);

            if (in_array($roleName, [self::ROLE_DEPUTY_DIRECTOR, self::ROLE_DISPATCH_LEAD, self::ROLE_DISPATCH_STAFF], true)) {
                $permissions[] = 'View:DispatchActionRequiredWidget';
            }

            $role->syncPermissions(array_values(array_unique($permissions)));
        }
    }

    /**
     * @param  array{operations: ?string, billing: ?string, management: ?string, activity: ?string, config: ?string, maintenance: ?string}  $menus
     * @return list<string>
     */
    public static function permissionsForMenus(array $menus): array
    {
        $permissions = static::defaultStaffPermissions();

        if ($menus['operations'] !== null) {
            $permissions = array_merge($permissions, static::operationsPermissions($menus['operations']));
        }

        if ($menus['billing'] !== null) {
            $permissions = array_merge($permissions, static::billingPermissions($menus['billing']));
        }

        if ($menus['management'] !== null) {
            $permissions = array_merge($permissions, static::managementPermissions($menus['management']));
        }

        if ($menus['activity'] !== null) {
            $permissions = array_merge($permissions, static::activityPermissions($menus['activity']));
        }

        if ($menus['config'] !== null) {
            $permissions = array_merge($permissions, static::configPermissions($menus['config']));
        }

        if ($menus['maintenance'] !== null) {
            $permissions = array_merge($permissions, static::maintenancePermissions($menus['maintenance']));
        }

        return array_values(array_unique($permissions));
    }

    /**
     * Quyền mặc định ngoài 6 menu cho tất cả tài khoản nhân viên điều hành / văn phòng.
     *
     * @return list<string>
     */
    private static function defaultStaffPermissions(): array
    {
        return [
            'View:Dashboard',
            'View:OperationsStatsWidget',
            'View:TripTrendChartWidget',
            'View:OrderStatusChartWidget',
            'View:OrderAreaChartWidget',
            'View:OrderTypeChartWidget',
            'View:GoogleMapTracking',
            'View:GoogleMapStatsOverview',
            'View:VehicleTrackingMap',
            'Use:DataAssistant',
        ];
    }

    /**
     * @return list<string>
     */
    private static function operationsPermissions(string $level): array
    {
        $permissions = array_merge(
            static::resourcePermissions(['Order', 'Trip'], $level),
            [
                'View:VehicleDispatchBoard',
                'View:VehicleDispatchTable',
                'View:DriverMonthlyKmReport',
                'View:DriverMonthlyKmTable',
                'View:DriverDutyReport',
                'View:DriverDutySummaryWidget',
            ],
        );

        if (in_array($level, ['Y', 'Z'], true)) {
            $permissions[] = 'Cancel:Order';
            $permissions[] = 'Cancel:Trip';
        }

        return $permissions;
    }

    /**
     * @return list<string>
     */
    private static function billingPermissions(string $level): array
    {
        $permissions = array_merge(
            static::resourcePermissions(['BillingZone', 'FeeType', 'FreightRate', 'WaitingFeeRate'], $level),
            [
                'View:TripStatisticsReport',
                'View:TripStatisticsTable',
                'View:BillingOverviewStatsWidget',
                'View:TopCustomerBillingWidget',
            ],
        );

        if (in_array($level, ['Y', 'Z'], true)) {
            $permissions[] = 'Update:TripCharge';
        }

        return $permissions;
    }

    /**
     * @return list<string>
     */
    private static function managementPermissions(string $level): array
    {
        return static::resourcePermissions(['Area', 'Customer', 'Location', 'User', 'Vehicle'], $level);
    }

    /**
     * @return list<string>
     */
    private static function activityPermissions(string $level): array
    {
        return static::resourcePermissions(['DriverShift', 'OvertimeRegistration'], $level);
    }

    /**
     * @return list<string>
     */
    private static function configPermissions(string $level): array
    {
        $permissions = array_merge(
            static::resourcePermissions(['Role'], $level),
            [
                'View:StaffAccounts',
                'ViewAny:Activity',
                'View:Activity',
            ],
        );

        if (in_array($level, ['Y', 'Z'], true)) {
            $permissions[] = 'Manage:StaffAccounts';
        }

        return $permissions;
    }

    /**
     * @return list<string>
     */
    private static function maintenancePermissions(string $level): array
    {
        return array_merge(
            static::resourcePermissions([
                'VehicleDocument',
                'VehicleMaintenanceJob',
                'VehicleMaintenanceSchedule',
            ], $level),
            [
                'View:FleetComplianceAlertWidget',
            ],
        );
    }

    /**
     * @param  list<string>  $models
     * @return list<string>
     */
    private static function resourcePermissions(array $models, string $level): array
    {
        $prefixes = match ($level) {
            'X' => ['ViewAny', 'View'],
            'Y' => ['ViewAny', 'View', 'Create', 'Update', 'Replicate', 'Reorder'],
            'Z' => [
                'ViewAny',
                'View',
                'Create',
                'Update',
                'Delete',
                'DeleteAny',
                'Restore',
                'RestoreAny',
                'ForceDelete',
                'ForceDeleteAny',
                'Replicate',
                'Reorder',
            ],
            default => [],
        };

        $permissions = [];
        foreach ($models as $model) {
            foreach ($prefixes as $prefix) {
                $permissions[] = "{$prefix}:{$model}";
            }
        }

        return $permissions;
    }

    /**
     * @return list<string>
     */
    private static function allKnownPermissionNames(): array
    {
        return array_merge(
            static::defaultStaffPermissions(),
            static::operationsPermissions('Z'),
            ['View:DispatchActionRequiredWidget'],
            static::billingPermissions('Z'),
            static::managementPermissions('Z'),
            static::activityPermissions('Z'),
            static::configPermissions('Z'),
            static::maintenancePermissions('Z'),
            static::resourcePermissions(['Activity', 'DriverSwap', 'OrderDeliveryPoint', 'TripCheckpoint', 'TripPhoto'], 'Z'),
        );
    }

    private function syncStaffUsers(): void
    {
        // Tạo mới Nguyễn Như Hiển (ASGT-01071) nếu chưa tồn tại.
        $hien = $this->findUserByCodeOrEmail('ASGT-01071', 'asgt-01071@asgt.vn');
        if (! $hien) {
            $hien = User::create([
                'employee_code' => 'ASGT-01071',
                'name' => 'Nguyễn Như Hiển',
                'email' => 'asgt-01071@asgt.vn',
                'password' => Hash::make('66668888'),
                'is_active' => true,
            ]);
        }

        // Danh sách gán role theo mã nhân viên (khớp cả trường hợp viết thường / không dấu gạch).
        $assignments = [
            ['codes' => ['ASGT-00695'], 'role' => self::ROLE_DEPUTY_DIRECTOR],
            ['codes' => ['ASGT-00221'], 'role' => self::ROLE_BILLING_LEAD],
            ['codes' => ['ASGT-00013', 'ASGT-00784'], 'role' => self::ROLE_BILLING_STAFF],
            ['codes' => ['ASGT-00298', 'ASGT-00907', 'ASGT-00806', 'asgt00806'], 'role' => self::ROLE_DISPATCH_LEAD],
            [
                'codes' => [
                    'ASGT-00338',
                    'ASGT-00348',
                    'ASGT-00418',
                    'ASGT-01138',
                    'ASGT-01067',
                    'asgt-01067',
                    'ASGT-00839',
                    'ASGT-01026',
                    'ASGT-01071',
                ],
                'role' => self::ROLE_DISPATCH_STAFF,
            ],
        ];

        foreach ($assignments as $group) {
            $users = User::query()
                ->where(function ($query) use ($group) {
                    foreach ($group['codes'] as $code) {
                        $query->orWhereRaw('UPPER(employee_code) = ?', [strtoupper($code)]);
                    }
                })
                ->get();

            foreach ($users as $user) {
                $user->forceFill(['is_active' => true])->save();
                $user->syncRoles([$group['role']]);
            }
        }

        // Giữ admin@admin.com ở vai trò super_admin.
        $superAdmin = User::where('email', 'admin@admin.com')->first();
        if ($superAdmin) {
            $superAdmin->forceFill(['is_active' => true])->save();
            $superAdmin->syncRoles(['super_admin']);
        }

        // Đánh dấu nghỉ việc (ngưng hoạt động và gỡ quyền).
        $resigned = User::query()
            ->where(function ($query) {
                $query->whereRaw('UPPER(employee_code) = ?', ['ASGT-00836'])
                    ->orWhereRaw('UPPER(employee_code) = ?', ['ASGT-00796']);
            })
            ->get();

        foreach ($resigned as $user) {
            $user->forceFill(['is_active' => false])->save();
            $user->syncRoles([]);
        }
    }

    private function findUserByCodeOrEmail(string $employeeCode, string $email): ?User
    {
        return User::query()
            ->whereRaw('UPPER(employee_code) = ?', [strtoupper($employeeCode)])
            ->orWhereRaw('LOWER(email) = ?', [strtolower($email)])
            ->first();
    }
}
