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

    Livewire::test(ListOrders::class)
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

    Livewire::test(ListOrders::class)
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

    Livewire::test(ListOrders::class)
        ->call('filterStatus', 'cancelled')
        ->assertSet('activeStatusFilter', 'cancelled')
        ->assertCanSeeTableRecords([$cancelledOrder])
        ->assertCanNotSeeTableRecords([$sentOrder]);
});
