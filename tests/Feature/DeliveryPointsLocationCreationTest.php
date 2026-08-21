<?php

use App\Enums\LocationType;
use App\Enums\OrderDeliveryPointStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\Priority;
use App\Filament\Resources\Orders\Actions\Concerns\CreatesOrderTransportCards;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Models\Area;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Order;
use App\Models\User;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->area = Area::create([
        'type' => OrderType::Hhhk,
        'code' => 'NBO',
        'name' => 'Nội bộ TN',
        'is_active' => true,
    ]);

    $this->customer = Customer::create([
        'code' => 'CUST-01',
        'name' => 'Công ty Test',
        'is_active' => true,
    ]);

    $this->user = User::factory()->create();
});

test('delivery points repeater location_id options are dynamic and include created locations without cache staleness', function () {
    $existingLocation = Location::create([
        'code' => 'SEVT',
        'name' => 'SEVT',
        'loc_type' => LocationType::Delivery,
        'area_id' => $this->area->id,
        'is_active' => true,
    ]);

    $repeater = CreatesOrderTransportCards::getDeliveryPointsRepeaterField('HHHK');
    $container = Schema::make(Livewire\Livewire::new(ListOrders::class));
    $repeater->container($container);
    expect($repeater)->toBeInstanceOf(Repeater::class);

    // Find location_id select in repeater schema
    $grid = $repeater->getChildComponents()[0];
    expect($grid)->toBeInstanceOf(Grid::class);

    $locationSelect = collect($grid->getChildComponents())
        ->first(fn ($c) => $c instanceof Select && $c->getName() === 'location_id');

    expect($locationSelect)->not->toBeNull();

    // Mock Get helper for area_id
    $getMock = function (string $path) {
        if ($path === '../../area_id' || $path === 'area_id') {
            return $this->area->id;
        }

        return null;
    };

    // First check options before creating new location
    $options = CreatesOrderTransportCards::getLocationOptions('HHHK');
    expect($options)->toHaveKey($existingLocation->id);

    // Now create a new location via createOptionUsing callback
    $createOptionUsing = $locationSelect->getCreateOptionUsing();
    expect($createOptionUsing)->not->toBeNull();

    $newLocationId = $createOptionUsing([
        'code' => 'SHINSUNG',
        'name' => 'SHINSUNG',
        'address' => 'Địa chỉ Shinsung',
        'area_id' => $this->area->id,
    ], new Get($locationSelect));

    expect($newLocationId)->toBeInt();

    $createdLocation = Location::find($newLocationId);
    expect($createdLocation)->not->toBeNull();
    expect($createdLocation->code)->toBe('SHINSUNG');
    expect($createdLocation->area_id)->toBe($this->area->id);
    expect($createdLocation->loc_type)->toBe(LocationType::Delivery);

    // Call options again immediately — must include the newly created location
    $updatedOptions = CreatesOrderTransportCards::getLocationOptions('HHHK');
    expect($updatedOptions)->toHaveKey($newLocationId);
    expect($updatedOptions[$newLocationId])->toBe('SHINSUNG');
});

test('creating bulk orders with newly created delivery location persists correctly', function () {
    $newLocation = Location::create([
        'code' => 'SHINSUNG',
        'name' => 'SHINSUNG',
        'address' => 'Khu CN Điềm Thụy',
        'area_id' => $this->area->id,
        'loc_type' => LocationType::Delivery,
        'is_active' => true,
    ]);

    $deliveryPointsRaw = [
        [
            'location_id' => $newLocation->id,
            'contact_person' => 'Nguyễn Văn A',
            'contact_phone' => '0912345678',
            'total_packages' => 5,
            'total_weight' => 1.5,
        ],
    ];

    $order = Order::create([
        'order_code' => 'ORD-TEST-001',
        'type' => 'HHHK',
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'status' => OrderStatus::Draft->value,
        'priority' => Priority::Medium->value,
        'created_by' => $this->user->id,
    ]);

    $deliveryPoints = collect($deliveryPointsRaw)
        ->values()
        ->map(function (array $deliveryPoint, int $idx): array {
            $address = null;
            if (filled($deliveryPoint['location_id'] ?? null)) {
                $address = Location::query()->find($deliveryPoint['location_id'])?->address;
            }

            return [
                'location_id' => $deliveryPoint['location_id'] ?? null,
                'address' => $address,
                'contact_person' => $deliveryPoint['contact_person'] ?? null,
                'contact_phone' => $deliveryPoint['contact_phone'] ?? null,
                'total_packages' => $deliveryPoint['total_packages'] ?? null,
                'total_weight' => $deliveryPoint['total_weight'] ?? null,
                'sequence' => $idx + 1,
                'status' => OrderDeliveryPointStatus::Pending->value,
            ];
        })
        ->all();

    $order->deliveryPoints()->createMany($deliveryPoints);

    expect($order->deliveryPoints()->count())->toBe(1);
    $dp = $order->deliveryPoints()->first();
    expect($dp->location_id)->toBe($newLocation->id);
    expect($dp->address)->toBe('Khu CN Điềm Thụy');
    expect($dp->contact_person)->toBe('Nguyễn Văn A');
});
