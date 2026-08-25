<?php

use App\Enums\CargoType;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\VehicleStatus;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Models\Area;
use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

test('external order without chargeable_weight requires input when assigning transport', function () {
    Gate::before(fn () => true);

    Role::create(['name' => 'driver', 'guard_name' => 'web']);

    $user = User::factory()->create();
    $this->actingAs($user);

    $vehicle = Vehicle::factory()->create(['status' => VehicleStatus::On]);
    $area = Area::create(['code' => 'HN', 'name' => 'Hà Nội', 'type' => 'external']);
    $customer = Customer::create(['code' => 'CUST01', 'name' => 'Khách A']);

    $externalOrder = Order::create([
        'order_code' => 'EXT001',
        'type' => OrderType::External,
        'status' => OrderStatus::Draft,
        'area_id' => $area->id,
        'customer_id' => $customer->id,
        'cargo_name' => 'Hàng mẫu',
        'cargo_type' => CargoType::Gcr,
        'chargeable_weight' => null,
        'created_by' => $user->id,
    ]);

    Livewire::test(ListOrders::class, [
        'activeOrderTypeFilter' => 'external',
        'activePlaceFilter' => 'HN',
        'showMineOnly' => false,
    ])
        ->callTableAction('assign_transport', $externalOrder, [
            'vehicle_id' => $vehicle->id,
            'chargeable_weight' => 3.5,
        ])
        ->assertHasNoTableActionErrors();

    $externalOrder->refresh();
    expect((float) $externalOrder->chargeable_weight)->toEqual(3.5)
        ->and($externalOrder->status)->toBe(OrderStatus::Assigned);
});
