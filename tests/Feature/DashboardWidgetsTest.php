<?php

use App\Filament\Widgets\OperationsStatsWidget;
use App\Filament\Widgets\OrderAreaChartWidget;
use App\Filament\Widgets\OrderStatusChartWidget;
use App\Filament\Widgets\OrderTypeChartWidget;
use App\Filament\Widgets\VehicleDestinationChartWidget;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $admin = User::factory()->create();
    $this->actingAs($admin);
});

use App\Enums\VehicleOwnerType;
use App\Enums\VehicleStatus;
use App\Models\DriverShift;
use App\Models\Vehicle;

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
        ->assertHasNoErrors()
        ->set('filter', 'year')
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

test('vehicle destination chart widget renders successfully and handles filters', function () {
    Livewire::test(VehicleDestinationChartWidget::class)
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
