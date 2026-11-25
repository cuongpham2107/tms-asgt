<?php

use App\Enums\OrderDeliveryPointStatus;
use App\Enums\OrderStatus;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Models\Area;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Order;
use App\Models\OrderDeliveryPoint;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('replicating an order resets delivery point statuses and execution fields', function () {
    Gate::before(fn () => true);

    $user = User::factory()->create();
    $this->actingAs($user);

    $area = Area::create([
        'type' => 'HHHK',
        'code' => 'NBA',
        'name' => 'Noi Bai',
    ]);
    $customer = Customer::create([
        'code' => 'CUST-REP',
        'name' => 'Khách sao chép',
        'is_active' => true,
    ]);
    $pickup = Location::create(['code' => 'ALSC', 'name' => 'ALSC', 'is_active' => true]);
    $drop1 = Location::create(['code' => 'CLC', 'name' => 'CLC', 'is_active' => true]);
    $drop2 = Location::create(['code' => 'SDV', 'name' => 'SDV', 'is_active' => true]);

    $source = Order::create([
        'order_code' => 'ASG-SRC-REP',
        'type' => 'HHHK',
        'area_id' => $area->id,
        'customer_id' => $customer->id,
        'pickup_location_id' => $pickup->id,
        'planned_loading_at' => now()->subHours(2),
        'status' => OrderStatus::Completed,
        'sent_at' => now()->subHours(2),
        'loaded_km' => 42.5,
        'is_return_trip' => true,
        'created_by' => $user->id,
    ]);

    OrderDeliveryPoint::create([
        'order_id' => $source->id,
        'location_id' => $drop1->id,
        'sequence' => 1,
        'status' => OrderDeliveryPointStatus::Delivered,
        'is_driver_added' => false,
        'arrived_at' => now()->subHour(),
        'delivered_at' => now()->subMinutes(40),
    ]);
    OrderDeliveryPoint::create([
        'order_id' => $source->id,
        'location_id' => $drop2->id,
        'sequence' => 2,
        'status' => OrderDeliveryPointStatus::Arrived,
        'is_driver_added' => true,
        'arrived_at' => now()->subMinutes(10),
    ]);

    Livewire::test(ListOrders::class, ['showMineOnly' => false, 'activePlaceFilter' => 'all'])
        ->loadTable()
        ->callTableAction('replicate', $source, data: [
            'planned_loading_at' => now()->format('Y-m-d H:i:s'),
        ])
        ->assertHasNoTableActionErrors();

    $replica = Order::query()->whereKeyNot($source->id)->latest('id')->first();

    expect($replica)->not->toBeNull()
        ->and($replica->order_code)->not->toBe('ASG-SRC-REP')
        ->and($replica->status)->toBe(OrderStatus::Draft)
        ->and($replica->trip_id)->toBeNull()
        ->and($replica->sent_at)->toBeNull()
        ->and($replica->loaded_km)->toBeNull()
        ->and($replica->is_return_trip)->toBeFalse();

    $points = $replica->deliveryPoints()->orderBy('sequence')->get();
    expect($points)->toHaveCount(2)
        ->and($points[0]->status)->toBe(OrderDeliveryPointStatus::Pending)
        ->and($points[0]->is_driver_added)->toBeFalse()
        ->and($points[0]->arrived_at)->toBeNull()
        ->and($points[0]->delivered_at)->toBeNull()
        ->and($points[1]->status)->toBe(OrderDeliveryPointStatus::Pending)
        ->and($points[1]->is_driver_added)->toBeFalse()
        ->and($points[1]->arrived_at)->toBeNull()
        ->and($points[1]->delivered_at)->toBeNull();
});
