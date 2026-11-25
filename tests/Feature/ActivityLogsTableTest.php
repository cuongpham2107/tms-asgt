<?php

use App\Enums\CheckpointType;
use App\Enums\TripStatus;
use App\Enums\VehicleOwnerType;
use App\Enums\VehicleStatus;
use App\Enums\VehicleType;
use App\Filament\Resources\ActivityLogs\Pages\ListActivityLogs;
use App\Filament\Resources\ActivityLogs\Tables\ActivityLogsTable;
use App\Models\Area;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Trip;
use App\Models\TripCheckpoint;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

test('activity log subject title renders checkpoint type label', function () {
    $vehicle = Vehicle::create([
        'plate_number' => '29C-800.01',
        'owner' => 'ASGT',
        'type' => VehicleOwnerType::Company,
        'vehicle_type' => VehicleType::Normal,
        'status' => VehicleStatus::On,
        'is_active' => true,
    ]);

    $trip = Trip::create([
        'trip_code' => 'TRIP-ACTIVITY-01',
        'vehicle_id' => $vehicle->id,
        'status' => TripStatus::Pending,
    ]);

    $checkpoint = TripCheckpoint::create([
        'trip_id' => $trip->id,
        'checkpoint_type' => CheckpointType::Started->value,
        'occurred_at' => now(),
    ]);

    $activity = Activity::create([
        'subject_type' => TripCheckpoint::class,
        'subject_id' => $checkpoint->id,
        'description' => 'updated',
    ]);

    expect(ActivityLogsTable::formatSubjectTitle($activity))
        ->toBe('Mốc hành trình #'.$checkpoint->id.' (Bắt đầu chuyến)');
});

test('deletion context note is recorded only for deleted events', function () {
    $vehicle = Vehicle::create([
        'plate_number' => '29C-800.02',
        'owner' => 'ASGT',
        'type' => VehicleOwnerType::Company,
        'vehicle_type' => VehicleType::Normal,
        'status' => VehicleStatus::On,
        'is_active' => true,
    ]);

    $createdLog = Activity::query()
        ->where('subject_type', Vehicle::class)
        ->where('subject_id', $vehicle->id)
        ->where('event', 'created')
        ->latest('id')
        ->first();

    expect($createdLog)->not->toBeNull()
        ->and($createdLog->description)->toBe('created')
        ->and($createdLog->properties->has('deletion_context'))->toBeFalse();

    $vehicle->delete();

    $deletedLog = Activity::query()
        ->where('subject_type', Vehicle::class)
        ->where('subject_id', $vehicle->id)
        ->where('event', 'deleted')
        ->latest('id')
        ->first();

    expect($deletedLog)->not->toBeNull()
        ->and($deletedLog->description)->not->toBe('deleted')
        ->and($deletedLog->properties->has('deletion_context'))->toBeTrue()
        ->and($deletedLog->properties['deletion_context'])->toHaveKeys([
            'note',
            'channel',
            'trigger',
            'page_url',
            'request_path',
            'ip',
            'user_agent',
            'app_trace',
        ]);
});

test('deleted order log displays order code in subject title and detail modal', function () {
    $area = Area::create(['code' => 'NBA', 'name' => 'Nội Bài', 'is_active' => true]);
    $customer = Customer::create(['code' => 'CUST-DEL', 'name' => 'Khách xóa', 'is_active' => true]);
    $user = User::factory()->create();

    $order = Order::create([
        'order_code' => 'ASG-DEL-999',
        'area_id' => $area->id,
        'customer_id' => $customer->id,
        'created_by' => $user->id,
    ]);
    $orderId = $order->id;
    $order->delete();

    $deletedLog = Activity::query()
        ->where('subject_type', Order::class)
        ->where('subject_id', $orderId)
        ->where('event', 'deleted')
        ->latest('id')
        ->first();

    expect($deletedLog)->not->toBeNull()
        ->and(ActivityLogsTable::formatSubjectTitle($deletedLog))
        ->toBe("Đơn hàng: ASG-DEL-999 (#{$orderId})");

    $html = view('filament.actions.activity-log-single-detail', ['activity' => $deletedLog])->render();

    expect($html)
        ->toContain("Đơn hàng: ASG-DEL-999 (#{$orderId})")
        ->toContain('Dữ liệu trước khi xóa')
        ->toContain('ASG-DEL-999');
});

test('activity logs table displays and searches order code even after order deletion', function () {
    Gate::before(fn () => true);

    $user = User::factory()->create();
    $this->actingAs($user);

    $area = Area::create(['code' => 'NBA', 'name' => 'Nội Bài', 'is_active' => true]);
    $customer = Customer::create(['code' => 'CUST-SEARCH', 'name' => 'Khách tìm kiếm', 'is_active' => true]);

    $deletedOrder = Order::create([
        'order_code' => 'ASG-SEARCH-DEL',
        'area_id' => $area->id,
        'customer_id' => $customer->id,
        'created_by' => $user->id,
    ]);
    $deletedOrderId = $deletedOrder->id;
    $deletedOrder->delete();

    $otherOrder = Order::create([
        'order_code' => 'ASG-OTHER-001',
        'area_id' => $area->id,
        'customer_id' => $customer->id,
        'created_by' => $user->id,
    ]);

    $deletedLog = Activity::query()
        ->where('subject_type', Order::class)
        ->where('subject_id', $deletedOrderId)
        ->where('event', 'deleted')
        ->latest('id')
        ->first();

    $otherLog = Activity::query()
        ->where('subject_type', Order::class)
        ->where('subject_id', $otherOrder->id)
        ->where('event', 'created')
        ->latest('id')
        ->first();

    expect(ActivityLogsTable::resolveOrderCode($deletedLog))->toBe('ASG-SEARCH-DEL');

    Livewire::test(ListActivityLogs::class)
        ->loadTable()
        ->searchTable('ASG-SEARCH-DEL')
        ->assertCanSeeTableRecords([$deletedLog])
        ->assertCanNotSeeTableRecords([$otherLog]);
});

test('deleted trip log resolves order code and vehicle plate number and supports plate search', function () {
    Gate::before(fn () => true);

    $user = User::factory()->create();
    $this->actingAs($user);

    $area = Area::create(['code' => 'NBA', 'name' => 'Nội Bài', 'is_active' => true]);
    $customer = Customer::create(['code' => 'CUST-TRIP-DEL', 'name' => 'Khách chuyến xóa', 'is_active' => true]);
    $vehicle = Vehicle::create([
        'plate_number' => '29E-999.88',
        'owner' => 'ASGT',
        'type' => VehicleOwnerType::Company,
        'vehicle_type' => VehicleType::Normal,
        'status' => VehicleStatus::On,
        'is_active' => true,
    ]);

    $trip = Trip::create([
        'trip_code' => 'CD-DEL-TRIP-01',
        'vehicle_id' => $vehicle->id,
        'status' => TripStatus::Pending,
    ]);

    $order = Order::create([
        'order_code' => 'ASG-TRIP-DEL-01',
        'area_id' => $area->id,
        'customer_id' => $customer->id,
        'trip_id' => $trip->id,
        'created_by' => $user->id,
    ]);

    $tripId = $trip->id;
    $order->delete();
    $trip->delete();

    $deletedTripLog = Activity::query()
        ->where('subject_type', Trip::class)
        ->where('subject_id', $tripId)
        ->where('event', 'deleted')
        ->latest('id')
        ->first();

    expect($deletedTripLog)->not->toBeNull()
        ->and(ActivityLogsTable::resolveOrderCode($deletedTripLog))->toBe('ASG-TRIP-DEL-01')
        ->and(ActivityLogsTable::resolvePlateNumber($deletedTripLog))->toBe('29E-999.88');

    Livewire::test(ListActivityLogs::class)
        ->loadTable()
        ->searchTable('29E-999.88')
        ->assertCanSeeTableRecords([$deletedTripLog]);

    Livewire::test(ListActivityLogs::class)
        ->loadTable()
        ->searchTable('ASG-TRIP-DEL-01')
        ->assertCanSeeTableRecords([$deletedTripLog]);
});
