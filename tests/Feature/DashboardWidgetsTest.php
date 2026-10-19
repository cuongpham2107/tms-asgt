<?php

use App\Enums\VehicleOwnerType;
use App\Enums\VehicleStatus;
use App\Filament\Widgets\OperationsStatsWidget;
use App\Filament\Widgets\OrderAreaChartWidget;
use App\Filament\Widgets\OrderStatusChartWidget;
use App\Filament\Widgets\OrderTypeChartWidget;
use App\Filament\Widgets\TripTrendChartWidget;
use App\Models\DriverShift;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'driver', 'guard_name' => 'web']);
    $admin = User::factory()->create();
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
