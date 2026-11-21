<?php

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\TripStatus;
use App\Enums\VehicleOwnerType;
use App\Enums\VehicleStatus;
use App\Filament\Widgets\BillingOverviewStatsWidget;
use App\Filament\Widgets\DispatchActionRequiredWidget;
use App\Filament\Widgets\FleetComplianceAlertWidget;
use App\Filament\Widgets\OperationsStatsWidget;
use App\Filament\Widgets\OrderAreaChartWidget;
use App\Filament\Widgets\OrderStatusChartWidget;
use App\Filament\Widgets\OrderTypeChartWidget;
use App\Filament\Widgets\TopCustomerBillingWidget;
use App\Filament\Widgets\TripTrendChartWidget;
use App\Filament\Widgets\VehicleKmOverChartWidget;
use App\Filament\Widgets\VehicleStatusChartWidget;
use App\Models\Area;
use App\Models\Customer;
use App\Models\DriverShift;
use App\Models\Order;
use App\Models\OrderCharge;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use Carbon\Carbon;
use Database\Seeders\RolePermissionMatrixSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::firstOrCreate(['name' => 'driver', 'guard_name' => 'web']);
    RolePermissionMatrixSeeder::syncRolesAndPermissions();
    $admin = User::factory()->create(['is_active' => true]);
    $admin->assignRole('super_admin');
    $this->actingAs($admin);
});

test('dashboard page renders successfully without map and calendar', function () {
    $this->get('/app')
        ->assertSuccessful()
        ->assertDontSee('vehicle-map')
        ->assertDontSee('DriverShiftCalendarWidget')
        ->assertSee('Bảng điều hành Vận tải');
});

test('operations stats widget renders successfully with company and rented vehicle stats', function () {
    Vehicle::factory()->create([
        'type' => VehicleOwnerType::Company,
        'status' => VehicleStatus::On,
        'is_active' => true,
    ]);
    Vehicle::factory()->create([
        'type' => VehicleOwnerType::Company,
        'status' => VehicleStatus::Off,
        'is_active' => true,
    ]);

    $driver = User::factory()->create();
    $driver->assignRole('driver');
    DriverShift::create([
        'driver_id' => $driver->id,
        'shift_type' => 'full',
        'start_time' => now(),
    ]);

    Vehicle::factory()->create([
        'type' => VehicleOwnerType::Rent,
        'status' => VehicleStatus::On,
        'is_active' => true,
        'current_driver_id' => $driver->id,
    ]);

    Livewire::test(OperationsStatsWidget::class)
        ->assertStatus(200)
        ->assertSee('Xe công ty sẵn sàng')
        ->assertSee('1 / 2')
        ->assertSee('Xe thuê làm việc')
        ->assertHasNoErrors();
});

test('trip trend chart widget renders successfully and handles filters', function () {
    Livewire::test(TripTrendChartWidget::class)
        ->set('filter', '7days')
        ->assertStatus(200)
        ->assertHasNoErrors()
        ->set('filter', '30days')
        ->assertStatus(200)
        ->assertHasNoErrors()
        ->set('filter', 'month')
        ->assertStatus(200)
        ->assertHasNoErrors();
});

test('order type chart widget renders successfully and handles filters', function () {
    Livewire::test(OrderTypeChartWidget::class)
        ->set('filter', 'today')
        ->assertStatus(200)
        ->assertHasNoErrors()
        ->set('filter', 'week')
        ->assertStatus(200)
        ->assertHasNoErrors()
        ->set('filter', 'month')
        ->assertStatus(200)
        ->assertHasNoErrors();
});

test('order area chart widget renders successfully and handles filters', function () {
    Livewire::test(OrderAreaChartWidget::class)
        ->set('filter', 'today')
        ->assertStatus(200)
        ->assertHasNoErrors()
        ->set('filter', 'month')
        ->assertStatus(200)
        ->assertHasNoErrors();
});

test('order status chart widget renders successfully and handles filters', function () {
    Livewire::test(OrderStatusChartWidget::class)
        ->set('filter', 'today')
        ->assertStatus(200)
        ->assertHasNoErrors()
        ->set('filter', 'month')
        ->assertStatus(200)
        ->assertHasNoErrors();
});

test('new role-focused dashboard widgets render real data cleanly including empty states', function () {
    $area = Area::create(['code' => 'NBA', 'name' => 'Nội Bài', 'is_active' => true]);
    $customer = Customer::create(['code' => 'CUST-D1', 'name' => 'Khách Dashboard', 'is_active' => true]);

    Order::create([
        'order_code' => 'ORD-DRAFT-D1',
        'type' => OrderType::Hhhk,
        'area_id' => $area->id,
        'customer_id' => $customer->id,
        'status' => OrderStatus::Draft,
        'planned_loading_at' => now(),
        'created_by' => auth()->id(),
    ]);

    $extOrder = Order::create([
        'order_code' => 'ORD-EXT-D1',
        'type' => OrderType::External,
        'area_id' => $area->id,
        'customer_id' => $customer->id,
        'status' => OrderStatus::Completed,
        'planned_loading_at' => now(),
        'created_by' => auth()->id(),
    ]);

    OrderCharge::create([
        'order_id' => $extOrder->id,
        'customer_id' => $customer->id,
        'base_price' => 1_500_000,
        'total' => 1_500_000,
    ]);

    $expiringDriver = User::factory()->create([
        'is_active' => true,
        'license_expiry_date' => now()->addDays(10),
    ]);
    $expiringDriver->assignRole('driver');

    Livewire::test(DispatchActionRequiredWidget::class)
        ->assertStatus(200)
        ->assertSee('Đơn Nháp chờ gán xe')
        ->assertSee('1');

    Livewire::test(BillingOverviewStatsWidget::class)
        ->assertStatus(200)
        ->assertSee('1.500.000 đ');

    Livewire::test(FleetComplianceAlertWidget::class)
        ->assertStatus(200)
        ->assertSee('Bằng lái / Chứng chỉ tài xế');

    Livewire::test(TopCustomerBillingWidget::class)
        ->assertStatus(200)
        ->loadTable()
        ->assertCanSeeTableRecords([$customer]);
});

test('vehicle km over chart ranks tracked company vehicles and totals the over-limit cost', function () {
    $tracked = fn (string $plate, int $km, string $owner = 'CLC') => Vehicle::factory()->create([
        'plate_number' => $plate,
        'type' => VehicleOwnerType::Company,
        'owner' => $owner,
        'is_active' => true,
        'monthly_start_km' => 100000,
        'current_mileage' => 100000 + $km,
    ]);
    $tracked('29H-LOW', 1500);
    $tracked('99H-HIGH', 3200, 'TTA');
    $tracked('29C-MID', 2900, 'CLS');
    $tracked('29H-ASGT', 5000, 'ASGT');
    Vehicle::factory()->create(['plate_number' => '29H-RENT', 'type' => VehicleOwnerType::Rent, 'is_active' => true]);

    $data = (fn (): array => $this->getViewData())->call(new VehicleKmOverChartWidget);

    expect(collect($data['rows'])->pluck('plate')->all())->toBe(['99H-HIGH', '29C-MID', '29H-LOW'])
        ->and(collect($data['rows'])->pluck('level')->all())->toBe(['red', 'orange', null])
        ->and($data['totalKm'])->toBe(7600.0)
        ->and($data['overCost'])->toBe(2000000.0)
        ->and(round($data['coefficient'], 4))->toBe(round(7600 / 9000, 4));

    Livewire::test(VehicleKmOverChartWidget::class)
        ->assertSuccessful()
        ->assertSee('99H-HIGH')
        ->assertSee('2.000.000 đ')
        ->assertDontSee('29H-ASGT');
});

test('vehicle status chart counts company vehicles by live trip state', function () {
    $this->travelTo(Carbon::parse('2026-10-23 14:00:00'));
    $vehicle = fn (string $plate, VehicleStatus $status = VehicleStatus::On) => Vehicle::factory()->create([
        'plate_number' => $plate,
        'type' => VehicleOwnerType::Company,
        'status' => $status,
        'is_active' => true,
    ]);
    $trip = fn (Vehicle $v, TripStatus $status, array $attributes = []) => Trip::factory()->create([
        'vehicle_id' => $v->id,
        'status' => $status,
        'started_at' => now()->subHour(),
        ...$attributes,
    ]);

    $trip($vehicle('29H-RUN'), TripStatus::Delivering);
    $trip($vehicle('29H-DELIVERED'), TripStatus::Delivered);
    $trip($vehicle('29H-DONE'), TripStatus::Completed, ['completed_at' => now()->subMinutes(10)]);
    $trip($vehicle('29H-WAIT'), TripStatus::Pending, ['started_at' => null]);
    $vehicle('29H-IDLE');
    $vehicle('29H-OFF', VehicleStatus::Off);

    $data = (fn (): array => $this->getViewData())->call(new VehicleStatusChartWidget);

    expect($data['totals'])->toBe(['running' => 1, 'ready' => 2, 'pending' => 1, 'idle' => 1])
        ->and($data['total'])->toBe(5);

    Livewire::test(VehicleStatusChartWidget::class)
        ->assertSuccessful()
        ->assertSee('Chưa xong')
        ->assertSee('Theo khu vực');
});
