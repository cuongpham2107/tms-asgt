<?php

use App\Enums\CheckpointType;
use App\Enums\OrderDeliveryPointStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\TripStatus;
use App\Enums\VehicleOwnerType;
use App\Enums\VehicleStatus;
use App\Enums\VehicleType;
use App\Filament\Resources\Orders\Actions\CreateBulkOrdersAction;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Trips\Tables\TripsTable;
use App\Models\Area;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Order;
use App\Models\OrderDeliveryPoint;
use App\Models\Trip;
use App\Models\TripCheckpoint;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Export\TripExcelExportService;
use App\Services\Trip\DeliveryProgressSyncService;
use App\Services\Trip\Handlers\ArrivedDeliveryHandler;
use Carbon\Carbon;
use Filament\Schemas\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Gate::before(fn () => true);

    $this->user = User::factory()->create(['name' => 'Điều Hành']);
    $this->actingAs($this->user);

    $this->driver = User::factory()->create(['name' => 'Ngô Văn Quỳ']);
    $this->driver->assignRole(Role::firstOrCreate(['name' => 'driver', 'guard_name' => 'web']));

    $this->area = Area::create([
        'type' => 'HHHK',
        'code' => 'PROVINCE',
        'name' => 'Tỉnh',
        'is_active' => true,
    ]);

    $this->customer = Customer::create([
        'code' => 'ASGS',
        'name' => 'ASGS',
        'is_active' => true,
    ]);

    $this->vehicle = Vehicle::create([
        'plate_number' => '99H00902',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'ASGT',
        'is_active' => true,
        'status' => VehicleStatus::Running,
        'type' => VehicleOwnerType::Company,
        'current_mileage' => 455536,
    ]);

    $this->pickup = Location::create(['code' => 'NEWWING', 'name' => 'NEWWING', 'lat' => 21.24, 'lng' => 106.14, 'loc_type' => 'pickup', 'is_active' => true]);
    $this->ncts = Location::create(['code' => 'NCTS', 'name' => 'NCTS', 'lat' => 21.21, 'lng' => 105.80, 'loc_type' => 'delivery', 'is_active' => true]);
    $this->acsv = Location::create(['code' => 'ACSV', 'name' => 'ACSV', 'lat' => 21.21, 'lng' => 105.81, 'loc_type' => 'delivery', 'is_active' => true]);
    $this->klv = Location::create(['code' => 'KLV', 'name' => 'KLV', 'lat' => 21.19, 'lng' => 105.76, 'loc_type' => 'delivery', 'is_active' => true]);
});

test('trip timeline popup orders manually added checkpoints chronologically instead of by id', function () {
    $trip = Trip::create([
        'trip_code' => 'CD-TIMELINE-01',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'status' => TripStatus::Completed,
        'started_at' => Carbon::parse('2026-11-27 13:32:00'),
        'completed_at' => Carbon::parse('2026-11-28 00:41:00'),
    ]);

    $order = Order::create([
        'order_code' => 'ASG-2435-T',
        'type' => OrderType::External,
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'pickup_location_id' => $this->pickup->id,
        'trip_id' => $trip->id,
        'status' => OrderStatus::Completed,
        'created_by' => $this->user->id,
    ]);

    $dpNcts = OrderDeliveryPoint::create(['order_id' => $order->id, 'location_id' => $this->ncts->id, 'sequence' => 1, 'status' => OrderDeliveryPointStatus::Delivered]);
    $dpKlv = OrderDeliveryPoint::create(['order_id' => $order->id, 'location_id' => $this->klv->id, 'sequence' => 2, 'status' => OrderDeliveryPointStatus::Delivered]);

    TripCheckpoint::create([
        'trip_id' => $trip->id,
        'order_id' => $order->id,
        'delivery_point_id' => $dpKlv->id,
        'driver_id' => $this->driver->id,
        'checkpoint_type' => CheckpointType::ArrivedDelivery,
        'km_reading' => 455520,
        'occurred_at' => Carbon::parse('2026-11-27 19:00:00'),
    ]);

    TripCheckpoint::create([
        'trip_id' => $trip->id,
        'order_id' => $order->id,
        'delivery_point_id' => $dpNcts->id,
        'driver_id' => $this->driver->id,
        'checkpoint_type' => CheckpointType::ArrivedDelivery,
        'km_reading' => 455535,
        'occurred_at' => Carbon::parse('2026-11-27 23:28:00'),
    ]);

    TripCheckpoint::create([
        'trip_id' => $trip->id,
        'order_id' => $order->id,
        'delivery_point_id' => $dpNcts->id,
        'driver_id' => $this->driver->id,
        'checkpoint_type' => CheckpointType::End,
        'km_reading' => 455536,
        'occurred_at' => Carbon::parse('2026-11-28 00:41:00'),
    ]);

    // Mốc Hoàn thành KLV được điều hành thêm sau (id lớn hơn mốc Kết thúc xe) nhưng thời gian là 21:00
    TripCheckpoint::create([
        'trip_id' => $trip->id,
        'order_id' => $order->id,
        'delivery_point_id' => $dpKlv->id,
        'driver_id' => $this->driver->id,
        'checkpoint_type' => CheckpointType::Completed,
        'km_reading' => 455520,
        'occurred_at' => Carbon::parse('2026-11-27 21:00:00'),
    ]);

    $html = view('filament.resources.trips.components.trip-timeline-popup', [
        'trip' => $trip->fresh(),
        'osmValidation' => [
            'overall_status' => 'no_data',
            'total_driver_km' => 0,
            'total_osm_km' => 0,
            'diff_km' => 0,
            'diff_percent' => 0,
            'warning_count' => 0,
            'danger_count' => 0,
            'valid_count' => 0,
            'segments' => [],
        ],
    ])->render();

    $posKlvArrived = strpos($html, '19:00 27/11/2026');
    $posKlvCompleted = strpos($html, '21:00 27/11/2026');
    $posNctsArrived = strpos($html, '23:28 27/11/2026');
    $posEnd = strpos($html, '00:41 28/11/2026');

    expect($posKlvArrived)->toBeInt()
        ->and($posKlvCompleted)->toBeGreaterThan($posKlvArrived)
        ->and($posNctsArrived)->toBeGreaterThan($posKlvCompleted)
        ->and($posEnd)->toBeGreaterThan($posNctsArrived)
        ->and($html)->not->toContain('question-mark-circle');
});

test('order route timeline and delivery point sequences stay aligned with actual trip delivery order', function () {
    $trip = Trip::create([
        'trip_code' => 'CD-ROUTE-01',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'status' => TripStatus::Completed,
        'started_at' => Carbon::parse('2026-11-27 13:32:00'),
        'completed_at' => Carbon::parse('2026-11-28 00:41:00'),
    ]);

    $order = Order::create([
        'order_code' => 'ASG-ROUTE-01',
        'type' => OrderType::External,
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'pickup_location_id' => $this->pickup->id,
        'trip_id' => $trip->id,
        'status' => OrderStatus::Completed,
        'created_by' => $this->user->id,
    ]);

    // Thứ tự kế hoạch ban đầu: NCTS (1) -> ACSV (2) -> KLV (3)
    $dpNcts = OrderDeliveryPoint::create(['order_id' => $order->id, 'location_id' => $this->ncts->id, 'sequence' => 1, 'status' => OrderDeliveryPointStatus::Delivered]);
    $dpAcsv = OrderDeliveryPoint::create(['order_id' => $order->id, 'location_id' => $this->acsv->id, 'sequence' => 2, 'status' => OrderDeliveryPointStatus::Delivered]);
    $dpKlv = OrderDeliveryPoint::create(['order_id' => $order->id, 'location_id' => $this->klv->id, 'sequence' => 3, 'status' => OrderDeliveryPointStatus::Delivered]);

    // Thực tế lái xe giao: KLV -> NCTS -> ACSV
    foreach ([
        [$dpKlv, CheckpointType::ArrivedDelivery, '2026-11-27 19:00:00'],
        [$dpKlv, CheckpointType::Completed, '2026-11-27 21:00:00'],
        [$dpNcts, CheckpointType::ArrivedDelivery, '2026-11-27 23:28:00'],
        [$dpAcsv, CheckpointType::ArrivedDelivery, '2026-11-28 00:35:00'],
    ] as [$dp, $type, $at]) {
        TripCheckpoint::create([
            'trip_id' => $trip->id,
            'order_id' => $order->id,
            'delivery_point_id' => $dp->id,
            'driver_id' => $this->driver->id,
            'checkpoint_type' => $type,
            'occurred_at' => Carbon::parse($at),
        ]);
    }

    $trip->load(['orders.deliveryPoints.location', 'checkpoints.deliveryPoint.location', 'endLocation']);
    $order->load(['deliveryPoints.location', 'tripCheckpoints']);

    expect(TripsTable::getDeliveryDestination($trip))->toBe('KLV → NCTS → ACSV')
        ->and($order->orderedDeliveryPoints()->pluck('location.code')->all())->toBe(['KLV', 'NCTS', 'ACSV'])
        ->and(app(TripExcelExportService::class)->buildJourneyString($order, $trip))->toBe('NEWWING -> KLV -> NCTS -> ACSV');

    // Đồng bộ sau khi sửa chuyến cập nhật lại sequence trong order_delivery_points
    app(DeliveryProgressSyncService::class)->syncOrderDeliveryPointsFromCheckpoints($trip);

    expect($order->deliveryPoints()->with('location')->get()->pluck('location.code')->all())
        ->toBe(['KLV', 'NCTS', 'ACSV']);
});

test('arriving at an out-of-order delivery point syncs delivery point sequence immediately', function () {
    $trip = Trip::create([
        'trip_code' => 'CD-ARRIVE-SEQ',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'status' => TripStatus::Delivering,
        'started_at' => now()->subHour(),
    ]);

    $order = Order::create([
        'order_code' => 'ASG-ARRIVE-SEQ',
        'type' => OrderType::Hhhk,
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'pickup_location_id' => $this->pickup->id,
        'trip_id' => $trip->id,
        'status' => OrderStatus::InTransit,
        'created_by' => $this->user->id,
    ]);

    $dpNcts = OrderDeliveryPoint::create(['order_id' => $order->id, 'location_id' => $this->ncts->id, 'sequence' => 1, 'status' => OrderDeliveryPointStatus::Pending]);
    $dpKlv = OrderDeliveryPoint::create(['order_id' => $order->id, 'location_id' => $this->klv->id, 'sequence' => 2, 'status' => OrderDeliveryPointStatus::Pending]);

    $cp = TripCheckpoint::create([
        'trip_id' => $trip->id,
        'order_id' => $order->id,
        'delivery_point_id' => $dpKlv->id,
        'driver_id' => $this->driver->id,
        'checkpoint_type' => CheckpointType::ArrivedDelivery,
        'occurred_at' => now(),
    ]);

    app(ArrivedDeliveryHandler::class)->handle($trip, ['occurred_at' => now()->toDateTimeString()], collect([$cp]));

    expect($dpKlv->fresh()->sequence)->toBe(1)
        ->and($dpNcts->fresh()->sequence)->toBe(2);
});

test('CreateBulkOrdersAction rejects HHHK bulk creation when pickup_location_id is missing', function () {
    $action = CreateBulkOrdersAction::make();
    $schema = Mockery::mock(Schema::class);

    $action->call([
        'data' => [
            'order_type_code' => 'HHHK',
            'area_id' => $this->area->id,
            'customer_id' => $this->customer->id,
            'pickup_location_id' => null,
            'cargo_name' => 'Hàng HHHK',
            'cargo_type' => 'GCR',
            'planned_loading_at' => now()->toDateTimeString(),
            'records_count' => 3,
            'deliveryPoints' => [],
        ],
        'schema' => $schema,
    ]);

    expect(Order::query()->where('customer_id', $this->customer->id)->count())->toBe(0);
});

test('AssignTransportAction requires pickup_location_id when order has no pickup location', function () {
    $order = Order::create([
        'order_code' => 'ASG-NO-PICKUP',
        'type' => OrderType::Hhhk,
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'pickup_location_id' => null,
        'pickup_address' => null,
        'status' => OrderStatus::Draft,
        'created_by' => $this->user->id,
    ]);

    Livewire::test(ListOrders::class)
        ->mountTableAction('assign_transport', $order)
        ->set('mountedActions.0.data.vehicle_id', $this->vehicle->id)
        ->set('mountedActions.0.data.driver_id', $this->driver->id)
        ->callMountedTableAction()
        ->assertHasTableActionErrors(['pickup_location_id' => 'required']);

    expect($order->fresh()->trip_id)->toBeNull();

    Livewire::test(ListOrders::class)
        ->mountTableAction('assign_transport', $order)
        ->set('mountedActions.0.data.pickup_location_id', $this->pickup->id)
        ->set('mountedActions.0.data.vehicle_id', $this->vehicle->id)
        ->set('mountedActions.0.data.driver_id', $this->driver->id)
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    $order->refresh();
    expect($order->pickup_location_id)->toBe($this->pickup->id)
        ->and($order->trip_id)->not->toBeNull()
        ->and($order->trip->start_location_id)->toBe($this->pickup->id);
});
