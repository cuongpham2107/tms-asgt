<?php

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\TripStatus;
use App\Enums\VehicleOwnerType;
use App\Enums\VehicleType;
use App\Filament\Pages\DriverMonthlyKmReport;
use App\Filament\Pages\GoogleMapTracking;
use App\Filament\Pages\StaffAccounts;
use App\Filament\Pages\TripStatisticsReport;
use App\Filament\Pages\VehicleDispatchBoard;
use App\Filament\Resources\ActivityLogs\ActivityLogResource;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\DriverShifts\DriverShiftResource;
use App\Filament\Resources\FreightRates\FreightRateResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Resources\Trips\TripResource;
use App\Filament\Resources\VehicleMaintenanceJobs\VehicleMaintenanceJobResource;
use App\Filament\Widgets\BillingOverviewStatsWidget;
use App\Filament\Widgets\DispatchActionRequiredWidget;
use App\Filament\Widgets\FleetComplianceAlertWidget;
use App\Filament\Widgets\OperationsStatsWidget;
use App\Filament\Widgets\TopCustomerBillingWidget;
use App\Filament\Widgets\TripStatisticsTable;
use App\Models\Area;
use App\Models\BillingZone;
use App\Models\Customer;
use App\Models\FreightRate;
use App\Models\Order;
use App\Models\OrderCharge;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use Database\Seeders\RolePermissionMatrixSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::firstOrCreate(['name' => 'driver', 'guard_name' => 'web']);
    RolePermissionMatrixSeeder::syncRolesAndPermissions();
});

function makeStaffWithRole(string $roleName): User
{
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole($roleName);

    return $user;
}

test('seeder provisions missing staff, swaps driver role, assigns duplicate accounts, and deactivates resigned users', function () {
    $khuong = User::factory()->create([
        'name' => 'Hoàng Thọ Khương',
        'email' => 'hoangthokhuong@gmail.com',
        'employee_code' => 'ASGT-00695',
        'is_active' => true,
    ]);
    $khuong->assignRole('driver');

    $tung1 = User::factory()->create([
        'name' => 'Phạm Thanh Tùng',
        'email' => 'ptt@tms.local',
        'employee_code' => 'ASGT-00806',
        'is_active' => true,
    ]);
    $tung1->assignRole('super_admin');

    $tung2 = User::factory()->create([
        'name' => 'Phạm Thanh Tùng',
        'email' => 'tung.pt.asgt@gmail.com',
        'employee_code' => 'asgt00806',
        'is_active' => true,
    ]);
    $tung2->assignRole('super_admin');

    $admin = User::factory()->create([
        'name' => 'admin',
        'email' => 'admin@admin.com',
        'is_active' => true,
    ]);
    $admin->assignRole('super_admin');

    $linh = User::factory()->create([
        'name' => 'Nguyễn Thị Khánh Linh',
        'email' => 'asgt-00836@asgt.vn',
        'employee_code' => 'ASGT-00836',
        'is_active' => true,
    ]);
    $linh->assignRole('super_admin');

    $chan = User::factory()->create([
        'name' => 'Nguyễn Ngọc Chân',
        'email' => 'asgt-00796@asgt.vn',
        'employee_code' => 'ASGT-00796',
        'is_active' => true,
    ]);
    $chan->assignRole('super_admin');

    (new RolePermissionMatrixSeeder)->run();

    $hien = User::where('employee_code', 'ASGT-01071')->first();
    expect($hien)->not->toBeNull()
        ->and($hien->name)->toBe('Nguyễn Như Hiển')
        ->and($hien->is_active)->toBeTrue()
        ->and(Hash::check('66668888', $hien->password))->toBeTrue()
        ->and($hien->hasRole(RolePermissionMatrixSeeder::ROLE_DISPATCH_STAFF))->toBeTrue();

    expect($khuong->fresh()->hasRole('driver'))->toBeFalse()
        ->and($khuong->fresh()->hasRole(RolePermissionMatrixSeeder::ROLE_DEPUTY_DIRECTOR))->toBeTrue()
        ->and($khuong->fresh()->canAccessPanel(Filament::getPanel('app')))->toBeTrue();

    expect($tung1->fresh()->hasRole(RolePermissionMatrixSeeder::ROLE_DISPATCH_LEAD))->toBeTrue()
        ->and($tung2->fresh()->hasRole(RolePermissionMatrixSeeder::ROLE_DISPATCH_LEAD))->toBeTrue();

    expect($admin->fresh()->hasRole('super_admin'))->toBeTrue();

    expect($linh->fresh()->is_active)->toBeFalse()
        ->and($linh->fresh()->roles)->toHaveCount(0)
        ->and($linh->fresh()->canAccessPanel(Filament::getPanel('app')))->toBeFalse();

    expect($chan->fresh()->is_active)->toBeFalse()
        ->and($chan->fresh()->roles)->toHaveCount(0)
        ->and($chan->fresh()->canAccessPanel(Filament::getPanel('app')))->toBeFalse();
});

test('inactive user cannot access filament panel even if super_admin', function () {
    $user = User::factory()->create(['is_active' => false]);
    $user->assignRole('super_admin');

    expect($user->canAccessPanel(Filament::getPanel('app')))->toBeFalse();
});

test('dispatch staff role grants operations, management, activity, maintenance and blocks billing and config', function () {
    $staff = makeStaffWithRole(RolePermissionMatrixSeeder::ROLE_DISPATCH_STAFF);
    $this->actingAs($staff);

    expect(OrderResource::canViewAny())->toBeTrue()
        ->and(TripResource::canViewAny())->toBeTrue()
        ->and(VehicleDispatchBoard::canAccess())->toBeTrue()
        ->and(DriverMonthlyKmReport::canAccess())->toBeTrue()
        ->and(CustomerResource::canViewAny())->toBeTrue()
        ->and(DriverShiftResource::canViewAny())->toBeTrue()
        ->and(VehicleMaintenanceJobResource::canViewAny())->toBeTrue()
        ->and(GoogleMapTracking::canAccess())->toBeTrue()
        ->and($staff->can('Use:DataAssistant'))->toBeTrue()
        ->and(TripStatisticsReport::canAccess())->toBeFalse()
        ->and(FreightRateResource::canViewAny())->toBeFalse()
        ->and(StaffAccounts::canAccess())->toBeFalse()
        ->and(RoleResource::canViewAny())->toBeFalse()
        ->and(ActivityLogResource::canViewAny())->toBeFalse();

    $this->get('/app/trip-statistics-report')->assertForbidden();
    $this->get('/app/nhan-vien')->assertForbidden();
    $this->get('/app/roles')->assertForbidden();

    $area = Area::create(['code' => 'NBA', 'name' => 'Nội Bài', 'is_active' => true]);
    $customer = Customer::create(['code' => 'C1', 'name' => 'C1', 'is_active' => true]);
    $order = Order::create([
        'order_code' => 'ORD-PERM-1',
        'type' => OrderType::Hhhk,
        'area_id' => $area->id,
        'customer_id' => $customer->id,
        'status' => OrderStatus::Draft,
        'created_by' => $staff->id,
    ]);

    expect($staff->can('create', Order::class))->toBeTrue()
        ->and($staff->can('update', $order))->toBeTrue()
        ->and($staff->can('cancel', $order))->toBeTrue()
        ->and($staff->can('delete', $order))->toBeFalse();
});

test('billing staff role grants operations, billing, management (Y) and blocks activity, config, maintenance, and delete', function () {
    $staff = makeStaffWithRole(RolePermissionMatrixSeeder::ROLE_BILLING_STAFF);
    $this->actingAs($staff);

    expect(OrderResource::canViewAny())->toBeTrue()
        ->and(TripStatisticsReport::canAccess())->toBeTrue()
        ->and(FreightRateResource::canViewAny())->toBeTrue()
        ->and(CustomerResource::canViewAny())->toBeTrue()
        ->and($staff->can('Update:TripCharge'))->toBeTrue()
        ->and(DriverShiftResource::canViewAny())->toBeFalse()
        ->and(StaffAccounts::canAccess())->toBeFalse()
        ->and(RoleResource::canViewAny())->toBeFalse()
        ->and(VehicleMaintenanceJobResource::canViewAny())->toBeFalse()
        ->and(ActivityLogResource::canViewAny())->toBeFalse();

    $z1 = BillingZone::create(['code' => 'Z1', 'name' => 'Zone 1']);
    $z2 = BillingZone::create(['code' => 'Z2', 'name' => 'Zone 2']);
    $customer = Customer::create(['code' => 'C2', 'name' => 'C2', 'is_active' => true]);
    $rate = FreightRate::create([
        'customer_id' => $customer->id,
        'origin_zone_id' => $z1->id,
        'destination_zone_id' => $z2->id,
        'prices' => ['5' => 1_000_000],
    ]);

    expect($staff->can('create', FreightRate::class))->toBeTrue()
        ->and($staff->can('update', $rate))->toBeTrue()
        ->and($staff->can('delete', $rate))->toBeFalse();
});

test('dispatch lead role has view-only access in billing and cannot mutate charges or edit super_admin role', function () {
    $lead = makeStaffWithRole(RolePermissionMatrixSeeder::ROLE_DISPATCH_LEAD);
    $this->actingAs($lead);

    expect(TripStatisticsReport::canAccess())->toBeTrue()
        ->and(FreightRateResource::canViewAny())->toBeTrue()
        ->and(StaffAccounts::canAccess())->toBeTrue()
        ->and(RoleResource::canViewAny())->toBeTrue()
        ->and($lead->can('Update:TripCharge'))->toBeFalse()
        ->and($lead->can('create', FreightRate::class))->toBeFalse();

    $superAdminRole = Role::where('name', 'super_admin')->firstOrFail();
    $dispatchStaffRole = Role::where('name', RolePermissionMatrixSeeder::ROLE_DISPATCH_STAFF)->firstOrFail();
    expect($lead->can('update', $superAdminRole))->toBeFalse()
        ->and($lead->can('delete', $superAdminRole))->toBeFalse()
        ->and($lead->can('update', $dispatchStaffRole))->toBeTrue()
        ->and($lead->can('delete', $dispatchStaffRole))->toBeFalse();

    $area = Area::create(['code' => 'NBA', 'name' => 'Nội Bài', 'is_active' => true]);
    $customer = Customer::create(['code' => 'C3', 'name' => 'C3', 'is_active' => true]);
    $vehicle = Vehicle::create(['plate_number' => '29C-00001', 'owner' => 'ASGT', 'type' => VehicleOwnerType::Company, 'vehicle_type' => VehicleType::Normal, 'is_active' => true]);
    $trip = Trip::create([
        'trip_code' => 'TRIP-RO-1',
        'vehicle_id' => $vehicle->id,
        'driver_id' => $lead->id,
        'status' => TripStatus::Completed,
        'started_at' => now()->subHours(2),
        'completed_at' => now(),
    ]);
    $order = Order::create([
        'order_code' => 'ORD-RO-1',
        'type' => OrderType::External,
        'area_id' => $area->id,
        'customer_id' => $customer->id,
        'trip_id' => $trip->id,
        'total_packages' => 10,
        'status' => OrderStatus::Completed,
        'created_by' => $lead->id,
    ]);
    $charge = OrderCharge::create([
        'order_id' => $order->id,
        'customer_id' => $customer->id,
        'base_price' => 1_000_000,
        'total' => 1_000_000,
    ]);

    $tableConfig = Livewire::test(TripStatisticsTable::class, [
        'isReady' => true,
        'startDate' => now()->startOfMonth()->toDateString(),
        'endDate' => now()->endOfMonth()->toDateString(),
        'activeTab' => 'external',
    ])->instance()->getRendererConfiguration();
    expect($tableConfig['canEditCharges'])->toBeFalse();

    Livewire::test(TripStatisticsReport::class, [
        'startDate' => now()->startOfMonth()->toDateString(),
        'endDate' => now()->endOfMonth()->toDateString(),
        'activeTab' => 'external',
    ])
        ->assertActionHidden('importPackages')
        ->call('updateOrderMetric', $order->id, 'total_packages', 99)
        ->call('lockCharge', $order->id);

    expect($order->fresh()->total_packages)->toBe(10)
        ->and($charge->fresh()->isLocked())->toBeFalse();
});

test('billing lead can delete billing rates but cannot delete orders, while deputy director can delete across all menus', function () {
    $billingLead = makeStaffWithRole(RolePermissionMatrixSeeder::ROLE_BILLING_LEAD);
    $deputy = makeStaffWithRole(RolePermissionMatrixSeeder::ROLE_DEPUTY_DIRECTOR);

    $area = Area::create(['code' => 'NBA', 'name' => 'Nội Bài', 'is_active' => true]);
    $customer = Customer::create(['code' => 'C4', 'name' => 'C4', 'is_active' => true]);
    $z1 = BillingZone::create(['code' => 'Z1', 'name' => 'Zone 1']);
    $z2 = BillingZone::create(['code' => 'Z2', 'name' => 'Zone 2']);
    $rate = FreightRate::create([
        'customer_id' => $customer->id,
        'origin_zone_id' => $z1->id,
        'destination_zone_id' => $z2->id,
        'prices' => ['5' => 1_000_000],
    ]);
    $order = Order::create([
        'order_code' => 'ORD-DEL-1',
        'type' => OrderType::Hhhk,
        'area_id' => $area->id,
        'customer_id' => $customer->id,
        'status' => OrderStatus::Draft,
        'created_by' => $billingLead->id,
    ]);

    expect($billingLead->can('delete', $rate))->toBeTrue()
        ->and($billingLead->can('delete', $order))->toBeFalse()
        ->and($billingLead->can('delete', $customer))->toBeFalse();

    expect($deputy->can('delete', $rate))->toBeTrue()
        ->and($deputy->can('delete', $order))->toBeTrue()
        ->and($deputy->can('delete', $customer))->toBeTrue();
});

test('dashboard widgets are tailored per role via Shield widget permissions', function () {
    $deputy = makeStaffWithRole(RolePermissionMatrixSeeder::ROLE_DEPUTY_DIRECTOR);
    $billingStaff = makeStaffWithRole(RolePermissionMatrixSeeder::ROLE_BILLING_STAFF);
    $dispatchLead = makeStaffWithRole(RolePermissionMatrixSeeder::ROLE_DISPATCH_LEAD);
    $dispatchStaff = makeStaffWithRole(RolePermissionMatrixSeeder::ROLE_DISPATCH_STAFF);

    $this->actingAs($deputy);
    expect(OperationsStatsWidget::canView())->toBeTrue()
        ->and(BillingOverviewStatsWidget::canView())->toBeTrue()
        ->and(DispatchActionRequiredWidget::canView())->toBeTrue()
        ->and(FleetComplianceAlertWidget::canView())->toBeTrue()
        ->and(TopCustomerBillingWidget::canView())->toBeTrue();

    $this->actingAs($billingStaff);
    expect(OperationsStatsWidget::canView())->toBeTrue()
        ->and(BillingOverviewStatsWidget::canView())->toBeTrue()
        ->and(TopCustomerBillingWidget::canView())->toBeTrue()
        ->and(DispatchActionRequiredWidget::canView())->toBeFalse()
        ->and(FleetComplianceAlertWidget::canView())->toBeFalse();

    $this->actingAs($dispatchLead);
    expect(OperationsStatsWidget::canView())->toBeTrue()
        ->and(BillingOverviewStatsWidget::canView())->toBeTrue()
        ->and(TopCustomerBillingWidget::canView())->toBeTrue()
        ->and(DispatchActionRequiredWidget::canView())->toBeTrue()
        ->and(FleetComplianceAlertWidget::canView())->toBeTrue();

    $this->actingAs($dispatchStaff);
    expect(OperationsStatsWidget::canView())->toBeTrue()
        ->and(DispatchActionRequiredWidget::canView())->toBeTrue()
        ->and(FleetComplianceAlertWidget::canView())->toBeTrue()
        ->and(BillingOverviewStatsWidget::canView())->toBeFalse()
        ->and(TopCustomerBillingWidget::canView())->toBeFalse();
});
