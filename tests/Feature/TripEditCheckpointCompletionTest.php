<?php

use App\Enums\CheckpointType;
use App\Enums\OrderStatus;
use App\Enums\TripStatus;
use App\Enums\VehicleOwnerType;
use App\Enums\VehicleStatus;
use App\Enums\VehicleType;
use App\Filament\Resources\Trips\Pages\ListTrips;
use App\Filament\Resources\Trips\Schemas\TripForm;
use App\Models\Area;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Order;
use App\Models\Trip;
use App\Models\TripCheckpoint;
use App\Models\User;
use App\Models\Vehicle;
use Filament\Forms\Components\Field;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $role = Role::firstOrCreate(['name' => 'driver', 'guard_name' => 'web']);
    $this->driver = User::factory()->create();
    $this->driver->assignRole($role);

    $this->admin = User::factory()->create();
    foreach (['ViewAny:Trip', 'View:Trip', 'Update:Trip'] as $permission) {
        $this->admin->givePermissionTo(Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']));
    }
    $this->actingAs($this->admin);

    $this->vehicle = Vehicle::create([
        'plate_number' => '29C-COMPLETE-01',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'ASGT',
        'is_active' => true,
        'status' => VehicleStatus::On,
        'type' => VehicleOwnerType::Company,
        'current_mileage' => 100000,
    ]);

    $this->area = Area::create([
        'code' => 'COMPLETE-AREA',
        'name' => 'Khu vực test hoàn thành',
    ]);

    $this->customer = Customer::create([
        'code' => 'COMPLETE-CUS',
        'name' => 'Khách test',
    ]);

    $this->trip = Trip::create([
        'trip_code' => 'TRIP-COMPLETE-01',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'status' => TripStatus::Delivering,
        'start_km' => 100000,
        'started_at' => now()->subHours(2),
    ]);

    $this->order = Order::create([
        'order_code' => 'ORD-COMPLETE-01',
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'created_by' => $this->admin->id,
        'trip_id' => $this->trip->id,
        'status' => OrderStatus::InTransit,
    ]);

});

function editTripTableForCompletion()
{
    return Livewire::test(ListTrips::class, [
        'orderType' => 'all',
        'activePlaceFilter' => 'all',
        'activeStatusFilter' => 'all',
    ])->loadTable();
}

test('checkpoint hoàn thành chuyển order sang hoàn thành và trip sang đã giao', function () {
    TripCheckpoint::create([
        'trip_id' => $this->trip->id,
        'checkpoint_type' => CheckpointType::Completed->value,
        'order_id' => $this->order->id,
        'driver_id' => $this->driver->id,
        'km_reading' => 100120,
        'occurred_at' => now()->subMinutes(10),
    ]);

    editTripTableForCompletion()
        ->mountTableAction('edit', $this->trip)
        ->fillForm(['driver_id' => $this->driver->id])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    $trip = $this->trip->fresh();

    expect($trip->status)->toBe(TripStatus::Delivered)
        ->and($trip->completed_at)->toBeNull()
        ->and($this->order->fresh()->status)->toBe(OrderStatus::Completed);
});

test('checkpoint kết thúc xe tự chuyển trip và order sang hoàn thành', function () {
    TripCheckpoint::create([
        'trip_id' => $this->trip->id,
        'checkpoint_type' => CheckpointType::End->value,
        'order_id' => $this->order->id,
        'driver_id' => $this->driver->id,
        'km_reading' => 100150,
        'occurred_at' => now()->subMinutes(5),
    ]);

    editTripTableForCompletion()
        ->mountTableAction('edit', $this->trip)
        ->fillForm(['driver_id' => $this->driver->id])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    $trip = $this->trip->fresh();

    expect($trip->status)->toBe(TripStatus::Completed)
        ->and((float) $trip->end_km)->toBe(100150.0)
        ->and($this->order->fresh()->status)->toBe(OrderStatus::Completed);
});

test('không có checkpoint kết thúc thì không tự hoàn thành', function () {
    TripCheckpoint::create([
        'trip_id' => $this->trip->id,
        'checkpoint_type' => CheckpointType::Started->value,
        'order_id' => $this->order->id,
        'driver_id' => $this->driver->id,
        'km_reading' => 100000,
        'occurred_at' => now()->subHours(2),
    ]);

    editTripTableForCompletion()
        ->mountTableAction('edit', $this->trip)
        ->fillForm(['driver_id' => $this->driver->id])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    expect($this->trip->fresh()->status)->toBe(TripStatus::Delivering)
        ->and($this->order->fresh()->status)->toBe(OrderStatus::InTransit);
});

test('thêm checkpoint hoàn thành trong form hoàn thành order, trip sang đã giao', function () {
    $started = TripCheckpoint::create([
        'trip_id' => $this->trip->id,
        'checkpoint_type' => CheckpointType::Started->value,
        'order_id' => $this->order->id,
        'driver_id' => $this->driver->id,
        'km_reading' => 100000,
        'occurred_at' => now()->subHours(2),
    ]);

    $rows = $this->trip->checkpoints()->get()->mapWithKeys(fn (TripCheckpoint $checkpoint): array => [
        'record-'.$checkpoint->id => [
            'checkpoint_type' => $checkpoint->checkpoint_type->value,
            'order_id' => $checkpoint->order_id,
            'driver_id' => $checkpoint->driver_id,
            'km_reading' => $checkpoint->km_reading,
            'occurred_at' => $checkpoint->occurred_at->format('Y-m-d H:i'),
            'delivery_point_id' => $checkpoint->delivery_point_id,
        ],
    ])->all();

    $rows['new-completed'] = [
        'checkpoint_type' => CheckpointType::Completed->value,
        'order_id' => $this->order->id,
        'driver_id' => $this->driver->id,
        'km_reading' => 100200,
        'occurred_at' => now()->format('Y-m-d H:i'),
    ];

    editTripTableForCompletion()
        ->mountTableAction('edit', $this->trip)
        ->fillForm(['checkpoints' => $rows])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    $trip = $this->trip->fresh();

    expect($trip->status)->toBe(TripStatus::Delivered)
        ->and($trip->completed_at)->toBeNull()
        ->and($trip->checkpoints()->where('checkpoint_type', CheckpointType::Completed->value)->exists())->toBeTrue()
        ->and($this->order->fresh()->status)->toBe(OrderStatus::Completed);

    expect($started->fresh())->not->toBeNull();
});

test('mốc hoàn thành rồi kết thúc xe ở cuối đóng chuyến và đơn', function () {
    foreach ([[CheckpointType::Completed, 100120, 10], [CheckpointType::End, 100150, 5]] as [$type, $km, $minutesAgo]) {
        TripCheckpoint::create([
            'trip_id' => $this->trip->id,
            'checkpoint_type' => $type->value,
            'order_id' => $this->order->id,
            'driver_id' => $this->driver->id,
            'km_reading' => $km,
            'occurred_at' => now()->subMinutes($minutesAgo),
        ]);
    }

    editTripTableForCompletion()
        ->mountTableAction('edit', $this->trip)
        ->fillForm(['driver_id' => $this->driver->id])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    $trip = $this->trip->fresh();

    expect($trip->status)->toBe(TripStatus::Completed)
        ->and((float) $trip->end_km)->toBe(100150.0)
        ->and($this->order->fresh()->status)->toBe(OrderStatus::Completed);
});

test('mốc lấy hàng hiện điểm lấy hàng của đơn ở cột Điểm, không cho chọn điểm giao', function () {
    $pickup = Location::create(['code' => 'SEMV-PICKUP', 'name' => 'Điểm lấy test', 'area_id' => $this->area->id]);
    $this->order->update(['pickup_location_id' => $pickup->id]);

    foreach ([CheckpointType::ArrivedPickup, CheckpointType::ArrivedDelivery] as $minutes => $type) {
        TripCheckpoint::create([
            'trip_id' => $this->trip->id,
            'checkpoint_type' => $type->value,
            'order_id' => $this->order->id,
            'driver_id' => $this->driver->id,
            'km_reading' => 100010 + $minutes,
            'occurred_at' => now()->subMinutes(60 - $minutes),
        ]);
    }

    $form = Schema::make(Livewire::new(ListTrips::class));
    TripForm::configure($form);
    $form->record($this->trip->fresh());
    $form->loadStateFromRelationships();

    [$pickupRow, $deliveryRow] = array_values(array_map(
        fn ($row) => $row->getFlatComponents()['delivery_point_id'],
        $form->getFlatComponents()['checkpoints']->getChildSchemas(),
    ));

    expect($pickupRow->getPlaceholder())->toBe('SEMV-PICKUP')
        ->and($pickupRow->isDisabled())->toBeTrue()
        ->and($deliveryRow->getPlaceholder())->toBe('Chọn điểm')
        ->and($deliveryRow->isDisabled())->toBeFalse();
});

test('popup hành trình hiện điểm lấy hàng cho mốc lấy hàng', function () {
    $pickup = Location::create(['code' => 'SEMV-PICKUP', 'name' => 'Điểm lấy test', 'area_id' => $this->area->id]);
    $this->order->update(['pickup_location_id' => $pickup->id]);

    $checkpoint = TripCheckpoint::create([
        'trip_id' => $this->trip->id,
        'checkpoint_type' => CheckpointType::LeftPickup->value,
        'order_id' => $this->order->id,
        'driver_id' => $this->driver->id,
        'km_reading' => 100010,
        'occurred_at' => now()->subHour(),
    ]);

    expect($checkpoint->fresh()->pointLocation()?->is($pickup))->toBeTrue();

    $html = view('filament.resources.trips.components.trip-timeline-popup', ['trip' => $this->trip->fresh()])->render();

    expect($html)->toContain('SEMV-PICKUP');
});

test('chuyến chờ chạy chưa có mốc vẫn hiển thị phần các mốc hành trình và hiện km xe ở km bắt đầu', function () {
    $pendingTrip = Trip::create([
        'trip_code' => 'TRIP-PENDING-01',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'status' => TripStatus::Pending,
        'start_km' => null,
        'started_at' => null,
    ]);

    $form = Schema::make(Livewire::new(ListTrips::class));
    TripForm::configure($form);
    $form->record($pendingTrip);
    $form->fill();

    $components = $form->getFlatComponents();
    $startKm = $components['start_km'];

    // Km bắt đầu hiển thị hint và helper text với km hiện tại của xe (100.000 km)
    expect($startKm->getHint())->toBe('Km xe: 100.000,0 km')
        ->and($startKm->getState())->toBe(100000.0);

    $belowContent = $startKm->getChildSchema(Field::BELOW_CONTENT_SCHEMA_KEY);
    if ($belowContent) {
        $textComponent = $belowContent->getComponents()[0] ?? null;
        expect($textComponent?->getContent())->toContain('Km hiện tại của xe: 100.000,0 km');
    }

    // Section Các mốc hành trình không bị ẩn khi checkpoints rỗng
    $sections = collect($form->getComponents())
        ->filter(fn ($comp) => $comp instanceof Section);

    $checkpointSection = $sections->first(fn ($s) => $s->getHeading() === 'Các mốc hành trình');
    expect($checkpointSection)->not->toBeNull()
        ->and($checkpointSection->isHidden())->toBeFalse();
});

test('điều hành nhập mốc bắt đầu cho chuyến chờ chạy chuyển trạng thái chuyến sang đã bắt đầu', function () {
    $pendingTrip = Trip::create([
        'trip_code' => 'TRIP-PENDING-02',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'status' => TripStatus::Pending,
        'start_km' => null,
        'started_at' => null,
    ]);

    $pendingOrder = Order::create([
        'order_code' => 'ORD-PENDING-02',
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'created_by' => $this->admin->id,
        'trip_id' => $pendingTrip->id,
        'status' => OrderStatus::Assigned,
    ]);

    $occurredAt = now()->subMinutes(30)->format('Y-m-d H:i');

    editTripTableForCompletion()
        ->mountTableAction('edit', $pendingTrip)
        ->fillForm([
            'driver_id' => $this->driver->id,
            'checkpoints' => [
                'record-1' => [
                    'checkpoint_type' => CheckpointType::Started->value,
                    'order_id' => $pendingOrder->id,
                    'driver_id' => $this->driver->id,
                    'km_reading' => 100005,
                    'occurred_at' => $occurredAt,
                ],
            ],
        ])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    $freshTrip = $pendingTrip->fresh();
    expect($freshTrip->status)->toBe(TripStatus::Started)
        ->and((float) $freshTrip->start_km)->toBe(100005.0)
        ->and($freshTrip->started_at)->not->toBeNull()
        ->and($pendingOrder->fresh()->status)->toBe(OrderStatus::Sent);
});

test('sửa mốc hành trình tự động cập nhật trạng thái chuyến theo mốc mới nhất', function () {
    $trip = Trip::create([
        'trip_code' => 'TRIP-UPDATE-STATUS-01',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'status' => TripStatus::Started,
        'start_km' => 100000,
        'started_at' => now()->subHour(),
    ]);

    $order = Order::create([
        'order_code' => 'ORD-UPDATE-STATUS-01',
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'created_by' => $this->admin->id,
        'trip_id' => $trip->id,
        'status' => OrderStatus::Sent,
    ]);

    $startedCp = TripCheckpoint::create([
        'trip_id' => $trip->id,
        'checkpoint_type' => CheckpointType::Started->value,
        'order_id' => $order->id,
        'driver_id' => $this->driver->id,
        'km_reading' => 100000,
        'occurred_at' => now()->subHour(),
    ]);

    // Admin thêm mốc Đến lấy hàng (ArrivedPickup)
    editTripTableForCompletion()
        ->mountTableAction('edit', $trip)
        ->fillForm([
            'driver_id' => $this->driver->id,
            'checkpoints' => [
                'record-1' => [
                    'checkpoint_type' => CheckpointType::Started->value,
                    'order_id' => $order->id,
                    'driver_id' => $this->driver->id,
                    'km_reading' => 100000,
                    'occurred_at' => now()->subHour()->format('Y-m-d H:i'),
                ],
                'record-2' => [
                    'checkpoint_type' => CheckpointType::ArrivedPickup->value,
                    'order_id' => $order->id,
                    'driver_id' => $this->driver->id,
                    'km_reading' => 100015,
                    'occurred_at' => now()->subMinutes(20)->format('Y-m-d H:i'),
                ],
            ],
        ])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    expect($trip->fresh()->status)->toBe(TripStatus::ArrivedPickup);
});
