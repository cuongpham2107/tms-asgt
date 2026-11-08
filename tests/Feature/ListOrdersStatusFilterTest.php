<?php

use App\Enums\OrderStatus;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Models\Area;
use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Gate::before(fn () => true);

    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    $this->area = Area::create([
        'type' => 'HHHK',
        'code' => 'NBA',
        'name' => 'Noi Bai Area',
    ]);

    $this->customer = Customer::create([
        'code' => 'CUST-FILTER',
        'name' => 'Customer Filter Test',
        'is_active' => true,
    ]);
});

test('list orders hides completed and cancelled orders by default when status is all', function () {
    $draftOrder = Order::create([
        'order_code' => 'ORD-DRAFT-01',
        'type' => 'HHHK',
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'status' => OrderStatus::Draft,
        'created_by' => $this->user->id,
    ]);

    $sentOrder = Order::create([
        'order_code' => 'ORD-SENT-01',
        'type' => 'HHHK',
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'status' => OrderStatus::Sent,
        'created_by' => $this->user->id,
    ]);

    $completedOrder = Order::create([
        'order_code' => 'ORD-COMPLETED-01',
        'type' => 'HHHK',
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'status' => OrderStatus::Completed,
        'created_by' => $this->user->id,
    ]);

    $cancelledOrder = Order::create([
        'order_code' => 'ORD-CANCELLED-01',
        'type' => 'HHHK',
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'status' => OrderStatus::Cancelled,
        'created_by' => $this->user->id,
    ]);

    Livewire::test(ListOrders::class)->loadTable()
        ->assertSet('activeStatusFilter', 'all')
        ->assertCanSeeTableRecords([$draftOrder, $sentOrder])
        ->assertCanNotSeeTableRecords([$completedOrder, $cancelledOrder]);
});

test('list orders shows completed orders only when completed status is selected', function () {
    $sentOrder = Order::create([
        'order_code' => 'ORD-SENT-02',
        'type' => 'HHHK',
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'status' => OrderStatus::Sent,
        'created_by' => $this->user->id,
    ]);

    $completedOrder = Order::create([
        'order_code' => 'ORD-COMPLETED-02',
        'type' => 'HHHK',
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'status' => OrderStatus::Completed,
        'created_by' => $this->user->id,
    ]);

    Livewire::test(ListOrders::class)->loadTable()
        ->call('filterStatus', 'completed')
        ->assertSet('activeStatusFilter', 'completed')
        ->assertCanSeeTableRecords([$completedOrder])
        ->assertCanNotSeeTableRecords([$sentOrder]);
});

test('list orders shows cancelled orders only when cancelled status is selected', function () {
    $sentOrder = Order::create([
        'order_code' => 'ORD-SENT-03',
        'type' => 'HHHK',
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'status' => OrderStatus::Sent,
        'created_by' => $this->user->id,
    ]);

    $cancelledOrder = Order::create([
        'order_code' => 'ORD-CANCELLED-03',
        'type' => 'HHHK',
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'status' => OrderStatus::Cancelled,
        'created_by' => $this->user->id,
    ]);

    Livewire::test(ListOrders::class)->loadTable()
        ->call('filterStatus', 'cancelled')
        ->assertSet('activeStatusFilter', 'cancelled')
        ->assertCanSeeTableRecords([$cancelledOrder])
        ->assertCanNotSeeTableRecords([$sentOrder]);
});

test('list orders scopes completed and cancelled counts and table to current month when date range is blank', function () {
    // Current month completed order
    $currentCompleted = Order::create([
        'order_code' => 'ORD-CURR-COMPLETED',
        'type' => 'HHHK',
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'status' => OrderStatus::Completed,
        'planned_loading_at' => now()->startOfMonth()->addDays(2),
        'created_by' => $this->user->id,
    ]);

    // Past month completed order
    $pastCompleted = Order::create([
        'order_code' => 'ORD-PAST-COMPLETED',
        'type' => 'HHHK',
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'status' => OrderStatus::Completed,
        'planned_loading_at' => now()->subMonthsNoOverflow(2)->startOfMonth(),
        'created_by' => $this->user->id,
    ]);

    // Past month cancelled order
    $pastCancelled = Order::create([
        'order_code' => 'ORD-PAST-CANCELLED',
        'type' => 'HHHK',
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'status' => OrderStatus::Cancelled,
        'planned_loading_at' => now()->subMonthsNoOverflow(2)->startOfMonth(),
        'created_by' => $this->user->id,
    ]);

    // Current month cancelled order
    $currentCancelled = Order::create([
        'order_code' => 'ORD-CURR-CANCELLED',
        'type' => 'HHHK',
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'status' => OrderStatus::Cancelled,
        'planned_loading_at' => now()->startOfMonth()->addDays(3),
        'created_by' => $this->user->id,
    ]);

    $component = Livewire::test(ListOrders::class);

    // Verify count only includes current month
    expect($component->instance()->getOrderStatusCount('completed'))->toBe(1)
        ->and($component->instance()->getOrderStatusCount('cancelled'))->toBe(1);

    // When clicking completed filter, only sees current month completed
    $component->loadTable()
        ->call('filterStatus', 'completed')
        ->assertCanSeeTableRecords([$currentCompleted])
        ->assertCanNotSeeTableRecords([$pastCompleted]);
});

test('list orders defaults to the current month when no date is in the url', function () {
    $this->travelTo(now()->setDate(2026, 10, 15));

    $thisMonth = Order::create([
        'order_code' => 'ORD-THIS-MONTH',
        'type' => 'HHHK',
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'status' => OrderStatus::Draft,
        'created_by' => $this->user->id,
        'planned_loading_at' => '2026-10-10 08:00:00',
    ]);

    $lastMonth = Order::create([
        'order_code' => 'ORD-LAST-MONTH',
        'type' => 'HHHK',
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'status' => OrderStatus::Draft,
        'created_by' => $this->user->id,
        'planned_loading_at' => '2026-09-20 08:00:00',
    ]);

    Livewire::test(ListOrders::class)->loadTable()
        ->assertSet('startDate', '2026-10-01')
        ->assertSet('endDate', '2026-10-31')
        ->assertCanSeeTableRecords([$thisMonth])
        ->assertCanNotSeeTableRecords([$lastMonth]);
});
