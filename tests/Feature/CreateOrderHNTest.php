<?php

use App\Filament\Resources\Orders\Actions\Concerns\CreatesOrderTransportCards;
use App\Models\Area;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Order;
use App\Models\User;
use Filament\Schemas\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->area = Area::create([
        'type' => 'external',
        'code' => 'HN-AREA',
        'name' => 'Khu Vực Hàng Ngoài',
        'is_active' => true,
    ]);

    $this->customer = Customer::create([
        'code' => 'CUST-HN',
        'name' => 'Khách Hàng HN',
        'is_active' => true,
    ]);

    $this->pickupLocation = Location::create([
        'code' => 'PICKUP-HN',
        'name' => 'Kho Điểm Nhận HN',
        'lat' => 21.028511,
        'lng' => 105.854444,
        'loc_type' => 'pickup',
        'is_active' => true,
    ]);

    $this->deliveryLocation = Location::create([
        'code' => 'DELIVERY-HN',
        'name' => 'Kho Điểm Giao HN',
        'lat' => 20.844912,
        'lng' => 106.688084,
        'loc_type' => 'delivery',
        'is_active' => true,
    ]);

    $this->user = User::factory()->create(['name' => 'Điều Hành Viên']);
});

test('tạo đơn hàng ngoài với is_return_trip = true lưu chính xác vào database', function () {
    $schema = Mockery::mock(Schema::class);
    $schema->shouldReceive('getRawState')->andReturn(['deliveryPoints' => []]);

    $data = [
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'pickup_location_id' => $this->pickupLocation->id,
        'cargo_name' => 'Hàng mẫu test quay đầu',
        'cargo_type' => 'GCR',
        'total_packages' => 10,
        'total_weight' => 2.5,
        'is_return_trip' => true,
        'notes' => 'Chuyến xe quay đầu',
    ];

    $order = CreatesOrderTransportCards::createSingleOrder($data, $schema, 'external', false, $this->user->id);

    expect($order)->toBeInstanceOf(Order::class);
    expect($order->is_return_trip)->toBeTrue();
    expect($order->fresh()->is_return_trip)->toBeTrue();
});

test('tạo đơn hàng ngoài mặc định is_return_trip = false', function () {
    $schema = Mockery::mock(Schema::class);
    $schema->shouldReceive('getRawState')->andReturn(['deliveryPoints' => []]);

    $data = [
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'pickup_location_id' => $this->pickupLocation->id,
        'cargo_name' => 'Hàng mẫu đi xuôi',
        'cargo_type' => 'GCR',
    ];

    $order = CreatesOrderTransportCards::createSingleOrder($data, $schema, 'external', false, $this->user->id);

    expect($order)->toBeInstanceOf(Order::class);
    expect($order->is_return_trip)->toBeFalse();
    expect($order->fresh()->is_return_trip)->toBeFalse();
});
