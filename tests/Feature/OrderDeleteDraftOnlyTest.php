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
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    $this->area = Area::create([
        'type' => 'HHHK',
        'code' => 'NBA',
        'name' => 'Noi Bai Area',
    ]);

    $this->customer = Customer::create([
        'code' => 'CUST-DEL-TEST',
        'name' => 'Customer Del Test',
        'is_active' => true,
    ]);
});

test('OrderStatus canDelete returns true only for Draft', function () {
    expect(OrderStatus::Draft->canDelete())->toBeTrue();
    expect(OrderStatus::Assigned->canDelete())->toBeFalse();
    expect(OrderStatus::Sent->canDelete())->toBeFalse();
    expect(OrderStatus::InTransit->canDelete())->toBeFalse();
    expect(OrderStatus::DriverSwap->canDelete())->toBeFalse();
    expect(OrderStatus::Completed->canDelete())->toBeFalse();
    expect(OrderStatus::Cancelled->canDelete())->toBeFalse();
});

test('OrderPolicy allows deleting Draft order but denies other statuses', function () {
    Permission::firstOrCreate(['name' => 'Delete:Order', 'guard_name' => 'web']);
    $this->user->givePermissionTo('Delete:Order');

    $draftOrder = Order::create([
        'order_code' => 'ORD-DEL-DRAFT',
        'type' => 'HHHK',
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'status' => OrderStatus::Draft,
        'created_by' => $this->user->id,
    ]);

    $sentOrder = Order::create([
        'order_code' => 'ORD-DEL-SENT',
        'type' => 'HHHK',
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'status' => OrderStatus::Sent,
        'created_by' => $this->user->id,
    ]);

    $inTransitOrder = Order::create([
        'order_code' => 'ORD-DEL-TRANSIT',
        'type' => 'HHHK',
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'status' => OrderStatus::InTransit,
        'created_by' => $this->user->id,
    ]);

    expect($this->user->can('delete', $draftOrder))->toBeTrue();
    expect($this->user->can('delete', $sentOrder))->toBeFalse();
    expect($this->user->can('delete', $inTransitOrder))->toBeFalse();
});

test('OrdersTable only allows selecting Draft orders', function () {
    Gate::before(fn () => true);

    $draftOrder = Order::create([
        'order_code' => 'ORD-SEL-DRAFT',
        'type' => 'HHHK',
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'status' => OrderStatus::Draft,
        'created_by' => $this->user->id,
    ]);

    $sentOrder = Order::create([
        'order_code' => 'ORD-SEL-SENT',
        'type' => 'HHHK',
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'status' => OrderStatus::Sent,
        'created_by' => $this->user->id,
    ]);

    $component = Livewire::test(ListOrders::class, ['showMineOnly' => false, 'activePlaceFilter' => 'all']);

    $table = $component->instance()->getTable();
    expect($table->isRecordSelectable($draftOrder))->toBeTrue();
    expect($table->isRecordSelectable($sentOrder))->toBeFalse();
});

test('DeleteBulkAction only deletes Draft orders and leaves other orders untouched', function () {
    Gate::before(fn () => true);

    $draftOrder = Order::create([
        'order_code' => 'ORD-BULK-DRAFT',
        'type' => 'HHHK',
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'status' => OrderStatus::Draft,
        'created_by' => $this->user->id,
    ]);

    $sentOrder = Order::create([
        'order_code' => 'ORD-BULK-SENT',
        'type' => 'HHHK',
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'status' => OrderStatus::Sent,
        'created_by' => $this->user->id,
    ]);

    Livewire::test(ListOrders::class, ['showMineOnly' => false, 'activePlaceFilter' => 'all'])
        ->callTableBulkAction('delete', [$draftOrder, $sentOrder]);

    expect(Order::find($draftOrder->id))->toBeNull();
    expect(Order::find($sentOrder->id))->not->toBeNull();
});

test('row level DeleteAction is hidden on non-Draft orders and visible on Draft orders', function () {
    Gate::before(fn () => true);

    $draftOrder = Order::create([
        'order_code' => 'ORD-ROW-DRAFT',
        'type' => 'HHHK',
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'status' => OrderStatus::Draft,
        'created_by' => $this->user->id,
    ]);

    $sentOrder = Order::create([
        'order_code' => 'ORD-ROW-SENT',
        'type' => 'HHHK',
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'status' => OrderStatus::Sent,
        'created_by' => $this->user->id,
    ]);

    Livewire::test(ListOrders::class, ['showMineOnly' => false, 'activePlaceFilter' => 'all'])
        ->assertTableActionVisible('delete', $draftOrder)
        ->assertTableActionHidden('delete', $sentOrder);
});
