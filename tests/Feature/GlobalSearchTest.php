<?php

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Filament\Resources\Areas\AreaResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Area;
use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

beforeEach(function () {
    Gate::before(fn () => true);
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    Filament::setCurrentPanel(Filament::getPanel('app'));
});

test('orders are found by code and open the filtered order list', function () {
    $area = Area::create(['code' => 'NBA', 'name' => 'Nội Bài', 'is_active' => true, 'sort_order' => 1]);
    $customer = Customer::create(['code' => 'ALSB', 'name' => 'ALSB', 'is_active' => true]);
    $order = Order::create([
        'order_code' => 'ASG-1596',
        'type' => OrderType::Hhhk,
        'area_id' => $area->id,
        'customer_id' => $customer->id,
        'status' => OrderStatus::Completed,
        'created_by' => $this->user->id,
    ]);

    $results = OrderResource::getGlobalSearchResults('1596');

    expect($results)->toHaveCount(1)
        ->and($results->first()->title)->toBe('ASG-1596')
        ->and($results->first()->details)->toMatchArray(['Khách hàng' => 'ALSB'])
        ->and($results->first()->url)->toContain('search=ASG-1596');
});

test('only opted-in resources take part in global search', function () {
    expect(OrderResource::canGloballySearch())->toBeTrue()
        ->and(AreaResource::canGloballySearch())->toBeFalse();
});
