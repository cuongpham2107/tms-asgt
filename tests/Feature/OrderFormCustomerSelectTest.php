<?php

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Filament\Resources\Orders\Schemas\OrderForm;
use App\Models\Area;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Order;
use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Component;

uses(RefreshDatabase::class);

test('order form customer select preloads code - name options without name-only dynamic search', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $area = Area::create([
        'type' => 'HHHK',
        'code' => 'NBA',
        'name' => 'Nội Bài',
        'is_active' => true,
        'sort_order' => 1,
    ]);

    $alsb = Customer::create([
        'code' => 'ALSB',
        'name' => 'CÔNG TY TRÁCH NHIỆM HỮU HẠN ALS BẮC NINH',
        'is_active' => true,
    ]);

    $inactive = Customer::create([
        'code' => 'OLD1',
        'name' => 'Khách cũ ngừng hoạt động',
        'is_active' => false,
    ]);

    $location = Location::create([
        'code' => 'ALSB',
        'name' => 'ALS Bắc Ninh',
        'address' => 'Bắc Ninh',
        'is_active' => true,
    ]);

    $order = Order::create([
        'order_code' => 'ASG-1026',
        'type' => OrderType::Hhhk,
        'area_id' => $area->id,
        'customer_id' => $inactive->id,
        'pickup_location_id' => $location->id,
        'status' => OrderStatus::Draft,
        'created_by' => $user->id,
    ]);

    $livewire = new class extends Component implements HasSchemas
    {
        use InteractsWithSchemas;
    };

    $schema = OrderForm::configure(Schema::make($livewire)->model($order)->statePath('data'));
    $livewire->data = ['customer_id' => $inactive->id];

    $findSelect = function (array $components, string $name) use (&$findSelect): ?Select {
        foreach ($components as $component) {
            if ($component instanceof Select && $component->getName() === $name) {
                return $component;
            }
            if (method_exists($component, 'getDefaultChildComponents')) {
                $found = $findSelect($component->getDefaultChildComponents(), $name);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    };

    $customerSelect = $findSelect($schema->getComponents(), 'customer_id');
    expect($customerSelect)->not->toBeNull();
    $customerSelect->container($schema);

    expect($customerSelect->hasDynamicSearchResults())->toBeFalse()
        ->and($customerSelect->isPreloaded())->toBeTrue()
        ->and($customerSelect->getOptions())
        ->toHaveKey($alsb->id, 'ALSB - CÔNG TY TRÁCH NHIỆM HỮU HẠN ALS BẮC NINH')
        ->toHaveKey($inactive->id, 'OLD1 - Khách cũ ngừng hoạt động');

    $pickupSelect = $findSelect($schema->getComponents(), 'pickup_location_id');
    expect($pickupSelect)->not->toBeNull();
    $pickupSelect->container($schema);

    expect($pickupSelect->getSearchColumns())->toBe(['code', 'name'])
        ->and($pickupSelect->getSearchResults('Bắc Ninh'))->toHaveKey($location->id, 'ALSB')
        ->and($pickupSelect->getSearchResults('ALSB'))->toHaveKey($location->id, 'ALSB');
});
