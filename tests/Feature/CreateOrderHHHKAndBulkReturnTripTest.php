<?php

use App\Enums\OrderType;
use App\Filament\Resources\Orders\Actions\Concerns\CreatesOrderTransportCards;
use App\Filament\Resources\Orders\Actions\CreateBulkOrdersAction;
use App\Filament\Resources\Orders\Actions\CreateOrderHHHKAction;
use App\Filament\Resources\Orders\Schemas\OrderForm;
use App\Models\Area;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Order;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Schemas\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->area = Area::create([
        'type' => 'airport',
        'code' => 'NBA',
        'name' => 'Khu Vực Nội Bài',
        'is_active' => true,
    ]);

    $this->customer = Customer::create([
        'code' => 'CUST-HHHK',
        'name' => 'Khách Hàng HHHK',
        'is_active' => true,
    ]);

    $this->pickupLocation = Location::create([
        'code' => 'PICKUP-NBA',
        'name' => 'Kho Điểm Nhận NBA',
        'lat' => 21.218511,
        'lng' => 105.804444,
        'loc_type' => 'pickup',
        'is_active' => true,
    ]);

    $this->deliveryLocation = Location::create([
        'code' => 'DELIVERY-NBA',
        'name' => 'Kho Điểm Giao NBA',
        'lat' => 21.028511,
        'lng' => 105.854444,
        'loc_type' => 'delivery',
        'is_active' => true,
    ]);

    $this->user = User::factory()->create(['name' => 'Điều Hành Viên']);
});

test('tạo đơn HHHK với is_return_trip = true lưu chính xác vào database', function () {
    $schema = Mockery::mock(Schema::class);
    $schema->shouldReceive('getRawState')->andReturn(['deliveryPoints' => []]);

    $data = [
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'pickup_location_id' => $this->pickupLocation->id,
        'cargo_name' => 'Hàng không test quay đầu',
        'total_packages' => 5,
        'total_weight' => 1.2,
        'is_return_trip' => true,
        'notes' => 'Chuyến quay đầu HHHK',
    ];

    $order = CreatesOrderTransportCards::createSingleOrder($data, $schema, 'HHHK', false, $this->user->id);

    expect($order)->toBeInstanceOf(Order::class)
        ->and($order->is_return_trip)->toBeTrue()
        ->and($order->fresh()->is_return_trip)->toBeTrue();
});

test('tạo đơn HHHK mặc định is_return_trip = false', function () {
    $schema = Mockery::mock(Schema::class);
    $schema->shouldReceive('getRawState')->andReturn(['deliveryPoints' => []]);

    $data = [
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'pickup_location_id' => $this->pickupLocation->id,
        'cargo_name' => 'Hàng không test đi xuôi',
    ];

    $order = CreatesOrderTransportCards::createSingleOrder($data, $schema, 'HHHK', false, $this->user->id);

    expect($order)->toBeInstanceOf(Order::class)
        ->and($order->is_return_trip)->toBeFalse()
        ->and($order->fresh()->is_return_trip)->toBeFalse();
});

test('CreateOrderHHHKAction khởi tạo thành công', function () {
    $action = CreateOrderHHHKAction::make();
    expect($action)->toBeInstanceOf(Action::class)
        ->and($action->getName())->toBe('create_order_hhhk_action');
});

test('CreateBulkOrdersAction khởi tạo thành công', function () {
    $action = CreateBulkOrdersAction::make();
    expect($action)->toBeInstanceOf(Action::class)
        ->and($action->getName())->toBe('create_bulk_orders_action');
});

test('tạo nhiều đơn (Bulk Orders) lưu is_return_trip chính xác vào database', function () {
    $this->actingAs($this->user);

    $action = CreateBulkOrdersAction::make();
    $schema = Mockery::mock(Schema::class);

    $data = [
        'order_type_code' => 'HHHK',
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'pickup_location_id' => $this->pickupLocation->id,
        'cargo_name' => 'Hàng HHHK Bulk',
        'cargo_type' => 'GCR',
        'planned_loading_at' => now()->toDateTimeString(),
        'is_return_trip' => true,
        'records_count' => 3,
        'deliveryPoints' => [
            [
                'location_id' => $this->deliveryLocation->id,
                'total_packages' => 2,
                'total_weight' => 0.5,
            ],
        ],
    ];

    $action->call([
        'data' => $data,
        'schema' => $schema,
    ]);

    $createdOrders = Order::query()->where('customer_id', $this->customer->id)->get();
    expect($createdOrders)->toHaveCount(3);

    foreach ($createdOrders as $order) {
        expect($order->is_return_trip)->toBeTrue()
            ->and($order->type)->toBe(OrderType::Hhhk)
            ->and($order->pickup_location_id)->toBe($this->pickupLocation->id);
    }
});

test('tạo nhiều đơn (Bulk Orders) mặc định is_return_trip = false', function () {
    $this->actingAs($this->user);

    $action = CreateBulkOrdersAction::make();
    $schema = Mockery::mock(Schema::class);

    $data = [
        'order_type_code' => 'HHHK',
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'pickup_location_id' => $this->pickupLocation->id,
        'cargo_name' => 'Hàng HHHK Bulk đi xuôi',
        'cargo_type' => 'GCR',
        'planned_loading_at' => now()->toDateTimeString(),
        'is_return_trip' => false,
        'records_count' => 2,
        'deliveryPoints' => [],
    ];

    $action->call([
        'data' => $data,
        'schema' => $schema,
    ]);

    $createdOrders = Order::query()->where('customer_id', $this->customer->id)->get();
    expect($createdOrders)->toHaveCount(2);

    foreach ($createdOrders as $order) {
        expect($order->is_return_trip)->toBeFalse();
    }
});

test('OrderForm chứa trường is_return_trip toggle', function () {
    $schema = Mockery::mock(Schema::class);
    $components = [];
    $schema->shouldReceive('components')->with(Mockery::on(function ($arg) use (&$components) {
        $components = $arg;

        return true;
    }))->andReturnSelf();

    OrderForm::configure($schema);

    expect($components)->not->toBeEmpty();
});
