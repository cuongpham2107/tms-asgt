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
            'orderSearch' => $keyword,
        ])
            ->assertCanSeeTableRecords([$this->order]);
    }
});

test('list trips searches case-insensitively by order code, trip code, plate number, and driver', function () {
    foreach (['asg-450', 'cd-2026-11-18-450', '29e25824', 'nguyễn văn admin'] as $keyword) {
        Livewire::test(ListTrips::class, [
            'tripSearch' => $keyword,
        ])
            ->assertCanSeeTableRecords([$this->trip]);
    }
});

test('list orders and list trips search bypasses default status, type, area, mine-only, and date filters', function () {
    $otherUser = User::factory()->create(['name' => 'Người Tạo Khác']);
    $otherArea = Area::create([
        'type' => 'external',
        'code' => 'HPH',
        'name' => 'Hải Phòng',
        'is_active' => true,
        'sort_order' => 2,
    ]);

    $oldTrip = Trip::create([
        'trip_code' => 'CD-2025-01-10-999',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $otherUser->id,
        'status' => TripStatus::Completed,
        'started_at' => now()->subMonths(3),
        'completed_at' => now()->subMonths(3)->addHour(),
        'created_by' => $otherUser->id,
    ]);

    $oldOrder = Order::create([
        'order_code' => 'EXT-999',
        'type' => OrderType::External,
        'area_id' => $otherArea->id,
        'customer_id' => $this->customer->id,
        'trip_id' => $oldTrip->id,
        'status' => OrderStatus::Completed,
        'planned_loading_at' => now()->subMonths(3),
        'created_by' => $otherUser->id,
    ]);

    Livewire::test(ListOrders::class, [
        'orderSearch' => 'ext-999',
    ])
        ->assertCanSeeTableRecords([$oldOrder]);

    Livewire::test(ListTrips::class, [
        'tripSearch' => 'cd-2025-01-10-999',
    ])
        ->assertCanSeeTableRecords([$oldTrip]);
});
