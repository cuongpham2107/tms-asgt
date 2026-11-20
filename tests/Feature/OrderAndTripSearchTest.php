<?php

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\TripStatus;
use App\Enums\VehicleOwnerType;
use App\Enums\VehicleStatus;
use App\Enums\VehicleType;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Trips\Pages\ListTrips;
use App\Models\Area;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Gate::before(fn () => true);

    $this->user = User::factory()->create(['name' => 'Nguyễn Văn Admin']);
    $this->actingAs($this->user);

    $this->area = Area::create([
        'type' => 'HHHK',
        'code' => 'NBA',
        'name' => 'Nội Bài',
        'is_active' => true,
        'sort_order' => 1,
    ]);

    $this->customer = Customer::create([
        'code' => 'ALSB',
        'name' => 'ALS Bắc Ninh',
        'is_active' => true,
    ]);

    $this->vehicle = Vehicle::create([
        'plate_number' => '29E25824',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'ASGT',
        'is_active' => true,
        'status' => VehicleStatus::On,
        'type' => VehicleOwnerType::Company,
    ]);

    $this->trip = Trip::create([
        'trip_code' => 'CD-2026-11-18-450',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->user->id,
        'status' => TripStatus::Completed,
        'started_at' => now()->subHour(),
        'completed_at' => now(),
    ]);

    $this->order = Order::create([
        'order_code' => 'ASG-450',
        'type' => OrderType::Hhhk,
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'trip_id' => $this->trip->id,
        'status' => OrderStatus::Completed,
        'planned_loading_at' => now()->subHours(2),
        'created_by' => $this->user->id,
    ]);
});

test('list orders searches case-insensitively by order code, customer code, and plate number', function () {
    foreach (['asg-450', 'ASG-450', 'alsb', '29e25824'] as $keyword) {
        Livewire::test(ListOrders::class, [
            'activeStatusFilter' => 'completed',
            'orderSearch' => $keyword,
        ])
            ->loadTable()
            ->assertCanSeeTableRecords([$this->order]);
    }
});

test('list trips searches case-insensitively by order code, trip code, plate number, and driver', function () {
    foreach (['asg-450', 'cd-2026-11-18-450', '29e25824', 'nguyễn văn admin'] as $keyword) {
        Livewire::test(ListTrips::class, [
            'activeStatusFilter' => 'completed',
            'tripSearch' => $keyword,
        ])
            ->loadTable()
            ->assertCanSeeTableRecords([$this->trip]);
    }
});

test('list orders and list trips search globally across all statuses, areas, types, owners, and dates', function () {
    $otherUser = User::factory()->create(['name' => 'Điều hành khác']);
    $otherArea = Area::create([
        'type' => 'HHHK',
        'code' => 'BN',
        'name' => 'Bắc Ninh',
        'is_active' => true,
        'sort_order' => 2,
    ]);
    $rentVehicle = Vehicle::create([
        'plate_number' => '99C88888',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'Thuê ngoài',
        'is_active' => true,
        'status' => VehicleStatus::On,
        'type' => VehicleOwnerType::Rent,
    ]);

    $oldTrip = Trip::create([
        'trip_code' => 'CD-OLD-999',
        'vehicle_id' => $rentVehicle->id,
        'driver_id' => $otherUser->id,
        'created_by' => $otherUser->id,
        'status' => TripStatus::Cancelled,
        'started_at' => now()->subMonths(3),
        'completed_at' => now()->subMonths(3),
    ]);

    $oldOrder = Order::create([
        'order_code' => 'EXT-OLD-999',
        'type' => OrderType::External,
        'area_id' => $otherArea->id,
        'customer_id' => $this->customer->id,
        'trip_id' => $oldTrip->id,
        'status' => OrderStatus::Cancelled,
        'planned_loading_at' => now()->subMonths(3),
        'created_by' => $otherUser->id,
    ]);

    // ListOrders mặc định đang lọc: HHHK + active (ẩn Completed/Cancelled) + NBA + showMineOnly + tháng này
    // Khi gõ search vẫn phải tìm ra đơn Hàng ngoài, Đã hủy, khu vực BN, của user khác, từ 3 tháng trước
    Livewire::test(ListOrders::class)
        ->loadTable()
        ->assertCanNotSeeTableRecords([$oldOrder])
        ->set('orderSearch', 'ext-old-999')
        ->assertCanSeeTableRecords([$oldOrder]);

    // ListTrips mặc định đang lọc: HHHK + active + NBA + tháng này + bật showMineOnly + Xe công ty
    // Khi gõ search vẫn phải tìm ra chuyến Hàng ngoài, Đã hủy, xe thuê, khu vực BN, của user khác, từ 3 tháng trước
    Livewire::test(ListTrips::class, [
        'showMineOnly' => true,
        'vehicleOwner' => 'company',
    ])
        ->loadTable()
        ->assertCanNotSeeTableRecords([$oldTrip])
        ->set('tripSearch', 'cd-old-999')
        ->assertCanSeeTableRecords([$oldTrip]);
});
