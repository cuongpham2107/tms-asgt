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

test('operations stats widget renders successfully', function () {
    Livewire::test(OperationsStatsWidget::class)
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
