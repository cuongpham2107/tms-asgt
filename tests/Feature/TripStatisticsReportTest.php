<?php

use App\Enums\CargoType;
use App\Enums\CheckpointType;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\TripStatus;
use App\Enums\VehicleOwnerType;
use App\Enums\VehicleType;
use App\Filament\Pages\TripStatisticsReport;
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
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\StreamedResponse;

uses(RefreshDatabase::class);

beforeEach(function () {
    Gate::before(fn () => true);

    $this->area = Area::create([
        'code' => 'NBA',
        'name' => 'Nội Bài',
        'is_active' => true,
        'sort_order' => 1,
    ]);

    $this->customer = Customer::create([
        'code' => 'ASGL',
        'name' => 'CÔNG TY CỔ PHẦN LOGISTICS ASG',
        'is_active' => true,
    ]);

    $this->customer2 = Customer::create([
        'code' => 'SAFO',
        'name' => 'SAFO LOGISTICS',
        'is_active' => true,
    ]);

    $this->pickupLocation = Location::create([
        'code' => 'ASG',
        'name' => 'Kho ASG Nội Bài',
        'address' => 'Sân bay Nội Bài, Hà Nội',
        'lat' => 21.2188,
        'lng' => 105.8044,
        'loc_type' => 'pickup',
        'is_active' => true,
    ]);

    $this->deliveryLocation = Location::create([
        'code' => 'ALSC',
        'name' => 'Nhà ga ALSC',
        'address' => 'Nội Bài, Hà Nội',
        'lat' => 21.2200,
        'lng' => 105.8050,
        'loc_type' => 'delivery',
        'is_active' => true,
    ]);

    $this->vehicle = Vehicle::create([
        'plate_number' => '20C04357',
        'owner' => 'ASGL',
        'type' => VehicleOwnerType::Company,
        'vehicle_type' => VehicleType::Normal,
        'is_active' => true,
    ]);

    $this->driver = User::factory()->create([
        'name' => 'Đặng Ngọc Lợi',
    ]);

    $this->actingAs($this->driver);
});

test('trip statistics report page can be rendered', function () {
    Livewire::test(TripStatisticsReport::class)
        ->assertSuccessful()
        ->assertSee('BẢNG THỐNG KÊ TỔNG HỢP CÁC CHUYẾN ĐÃ PHỤC VỤ')
        ->assertSee('Xuất Excel (.xlsx)');
});

test('trip statistics report calculates 27 columns correctly for hhhk trip', function () {
    $trip = Trip::create([
        'trip_code' => 'TRIP-ASG-001',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'status' => TripStatus::Completed,
        'started_at' => Carbon::parse('2026-04-20 09:00:00'),
        'completed_at' => Carbon::parse('2026-04-20 09:30:00'),
        'total_km' => 70,
        'total_km_loaded' => 50,
        'total_km_empty' => 20,
    ]);

    $order = Order::create([
        'order_code' => 'ASG1',
        'type' => OrderType::Hhhk,
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'created_by' => $this->driver->id,
        'pickup_location_id' => $this->pickupLocation->id,
        'planned_loading_at' => Carbon::parse('2026-04-20 08:20:00'),
        'trip_id' => $trip->id,
        'status' => OrderStatus::Completed,
        'cargo_type' => CargoType::Gcr,
        'total_packages' => 10,
        'total_weight' => 500,
        'chargeable_weight' => 2.5,
        'notes' => 'EI',
        'loaded_km' => 50,
    ]);

    $dp = OrderDeliveryPoint::create([
        'order_id' => $order->id,
        'location_id' => $this->deliveryLocation->id,
        'sequence' => 1,
        'arrived_at' => Carbon::parse('2026-04-20 09:10:00'),
        'delivered_at' => Carbon::parse('2026-04-20 09:30:00'),
    ]);

    // Checkpoints: A=08:20, B=09:00, C=09:10, D=09:30
    TripCheckpoint::create([
        'trip_id' => $trip->id,
        'order_id' => $order->id,
        'checkpoint_type' => CheckpointType::ArrivedPickup,
        'occurred_at' => Carbon::parse('2026-04-20 08:20:00'),
    ]);

    TripCheckpoint::create([
        'trip_id' => $trip->id,
        'order_id' => $order->id,
        'checkpoint_type' => CheckpointType::LeftPickup,
        'occurred_at' => Carbon::parse('2026-04-20 09:00:00'),
    ]);

    TripCheckpoint::create([
        'trip_id' => $trip->id,
        'order_id' => $order->id,
        'checkpoint_type' => CheckpointType::ArrivedDelivery,
        'occurred_at' => Carbon::parse('2026-04-20 09:10:00'),
    ]);

    TripCheckpoint::create([
        'trip_id' => $trip->id,
        'order_id' => $order->id,
        'checkpoint_type' => CheckpointType::Completed,
        'occurred_at' => Carbon::parse('2026-04-20 09:30:00'),
    ]);

    $component = Livewire::test(TripStatisticsReport::class, [
        'startDate' => '2026-04-01',
        'endDate' => '2026-04-30',
    ]);

    $rows = $component->instance()->getAllRows();

    expect($rows)->toHaveCount(1);
    $row = $rows->first();

    expect($row['service_type'])->toBe('HHHK');
    expect($row['code'])->toBe('ASG1');
    expect($row['plate_number'])->toBe('20C04357');
    expect($row['journey'])->toBe('ASG -> ALSC');
    expect($row['time_loading'])->toBe(40); // 09:00 - 08:20
    expect($row['time_travel'])->toBe(10);  // 09:10 - 09:00
    expect($row['time_unloading'])->toBe(20); // 09:30 - 09:10
    expect($row['time_waiting_22h'])->toBe(0); // D < 22:00
    expect($row['time_total_trip'])->toBe(70); // 40 + 10 + 20
    expect($row['customer'])->toBe('ASGL');
    expect($row['warehouse'])->toBe('ALSC');
    expect($row['vehicle_owner'])->toBe('Xe công ty');
    expect($row['vehicle_type'])->toBe('Xe thường');
    expect($row['note'])->toBe('EI');
    expect($row['driver_name'])->toBe('Đặng Ngọc Lợi');
    expect($row['km_loaded'])->toBe(50.0);
    expect($row['km_empty'])->toBe(20.0);
});

test('trip statistics report calculates waiting 22h correctly when delivery extends past 22:00', function () {
    $trip = Trip::create([
        'trip_code' => 'TRIP-ASG-NIGHT',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'status' => TripStatus::Completed,
        'started_at' => Carbon::parse('2026-04-20 18:00:00'),
        'completed_at' => Carbon::parse('2026-04-20 22:45:00'),
    ]);

    $order = Order::create([
        'order_code' => 'ASG-NIGHT',
        'type' => OrderType::External,
        'area_id' => $this->area->id,
        'customer_id' => $this->customer2->id,
        'created_by' => $this->driver->id,
        'pickup_location_id' => $this->pickupLocation->id,
        'planned_loading_at' => Carbon::parse('2026-04-20 17:00:00'),
        'trip_id' => $trip->id,
        'status' => OrderStatus::Completed,
        'cargo_type' => CargoType::Gcr,
    ]);

    // A=17:00, B=18:00, C=19:00, D=22:45
    // time1 = 18:00 - 17:00 = 60 mins
    // time2 = 19:00 - 18:00 = 60 mins
    // time3 = 22:45 - 19:00 = 225 mins
    // time4 (22h wait) = time1 (60) + time2 (60) + (22:00 - 19:00 = 180) = 300 mins
    TripCheckpoint::create([
        'trip_id' => $trip->id,
        'order_id' => $order->id,
        'checkpoint_type' => CheckpointType::ArrivedPickup,
        'occurred_at' => Carbon::parse('2026-04-20 17:00:00'),
    ]);

    TripCheckpoint::create([
        'trip_id' => $trip->id,
        'order_id' => $order->id,
        'checkpoint_type' => CheckpointType::LeftPickup,
        'occurred_at' => Carbon::parse('2026-04-20 18:00:00'),
    ]);

    TripCheckpoint::create([
        'trip_id' => $trip->id,
        'order_id' => $order->id,
        'checkpoint_type' => CheckpointType::ArrivedDelivery,
        'occurred_at' => Carbon::parse('2026-04-20 19:00:00'),
    ]);

    TripCheckpoint::create([
        'trip_id' => $trip->id,
        'order_id' => $order->id,
        'checkpoint_type' => CheckpointType::Completed,
        'occurred_at' => Carbon::parse('2026-04-20 22:45:00'),
    ]);

    $component = Livewire::test(TripStatisticsReport::class, [
        'startDate' => '2026-04-01',
        'endDate' => '2026-04-30',
    ]);

    $rows = $component->instance()->getAllRows();
    $nightRow = $rows->firstWhere('code', 'ASG-NIGHT');

    expect($nightRow)->not->toBeNull();
    expect($nightRow['time_loading'])->toBe(60);
    expect($nightRow['time_travel'])->toBe(60);
    expect($nightRow['time_unloading'])->toBe(225);
    expect($nightRow['time_waiting_22h'])->toBe(300); // 60 + 60 + 180
    expect($nightRow['time_total_trip'])->toBe(345); // 60 + 60 + 225
});

test('trip statistics report can export excel', function () {
    $trip = Trip::create([
        'trip_code' => 'TRIP-EXP-001',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'status' => TripStatus::Completed,
        'started_at' => Carbon::parse('2026-04-20 09:00:00'),
        'completed_at' => Carbon::parse('2026-04-20 10:00:00'),
    ]);

    $component = Livewire::test(TripStatisticsReport::class, [
        'startDate' => '2026-04-01',
        'endDate' => '2026-04-30',
    ]);

    $response = $component->instance()->exportExcel();
    expect($response)->toBeInstanceOf(StreamedResponse::class);
});

test('trip statistics report filters by serviceType and customer', function () {
    $trip1 = Trip::create([
        'trip_code' => 'TRIP-HHHK',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'status' => TripStatus::Completed,
        'started_at' => Carbon::parse('2026-04-20 09:00:00'),
        'completed_at' => Carbon::parse('2026-04-20 10:00:00'),
    ]);

    Order::create([
        'order_code' => 'ORD-HHHK-1',
        'type' => OrderType::Hhhk,
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'created_by' => $this->driver->id,
        'trip_id' => $trip1->id,
        'status' => OrderStatus::Completed,
        'cargo_type' => CargoType::Gcr,
    ]);

    $trip2 = Trip::create([
        'trip_code' => 'TRIP-EXT',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'status' => TripStatus::Completed,
        'started_at' => Carbon::parse('2026-04-20 11:00:00'),
        'completed_at' => Carbon::parse('2026-04-20 12:00:00'),
    ]);

    Order::create([
        'order_code' => 'ORD-EXT-1',
        'type' => OrderType::External,
        'area_id' => $this->area->id,
        'customer_id' => $this->customer2->id,
        'created_by' => $this->driver->id,
        'trip_id' => $trip2->id,
        'status' => OrderStatus::Completed,
        'cargo_type' => CargoType::Gcr,
    ]);

    // Test filter HHHK
    $component = Livewire::test(TripStatisticsReport::class, [
        'startDate' => '2026-04-01',
        'endDate' => '2026-04-30',
        'serviceType' => 'HHHK',
    ]);

    $rows = $component->instance()->getAllRows();
    expect($rows)->toHaveCount(1);
    expect($rows->first()['service_type'])->toBe('HHHK');

    // Test filter External
    $component->set('serviceType', 'external');
    $rows = $component->instance()->getAllRows();
    expect($rows)->toHaveCount(1);
    expect($rows->first()['service_type'])->toBe('Hàng ngoài');

    // Test filter Customer
    $component->set('serviceType', 'all');
    $component->set('customerId', $this->customer->id);
    $rows = $component->instance()->getAllRows();
    expect($rows)->toHaveCount(1);
    expect($rows->first()['code'])->toBe('ORD-HHHK-1');

    // Test search filter
    $component->set('customerId', null);
    $component->set('search', 'ORD-EXT');
    $rows = $component->instance()->getAllRows();
    expect($rows)->toHaveCount(1);
    expect($rows->first()['code'])->toBe('ORD-EXT-1');
});

test('trip statistics report exports excel reflecting active filters', function () {
    $trip1 = Trip::create([
        'trip_code' => 'TRIP-FILTER-1',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'status' => TripStatus::Completed,
        'started_at' => Carbon::parse('2026-04-20 09:00:00'),
        'completed_at' => Carbon::parse('2026-04-20 10:00:00'),
    ]);

    Order::create([
        'order_code' => 'ORD-EXPORT-HHHK',
        'type' => OrderType::Hhhk,
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'created_by' => $this->driver->id,
        'trip_id' => $trip1->id,
        'status' => OrderStatus::Completed,
        'cargo_type' => CargoType::Gcr,
    ]);

    $trip2 = Trip::create([
        'trip_code' => 'TRIP-FILTER-2',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'status' => TripStatus::Completed,
        'started_at' => Carbon::parse('2026-04-20 11:00:00'),
        'completed_at' => Carbon::parse('2026-04-20 12:00:00'),
    ]);

    Order::create([
        'order_code' => 'ORD-EXPORT-EXT',
        'type' => OrderType::External,
        'area_id' => $this->area->id,
        'customer_id' => $this->customer2->id,
        'created_by' => $this->driver->id,
        'trip_id' => $trip2->id,
        'status' => OrderStatus::Completed,
        'cargo_type' => CargoType::Gcr,
    ]);

    // Filter to HHHK only
    $component = Livewire::test(TripStatisticsReport::class, [
        'startDate' => '2026-04-01',
        'endDate' => '2026-04-30',
        'serviceType' => 'HHHK',
    ]);

    $rows = $component->instance()->getAllRows();
    expect($rows)->toHaveCount(1);
    expect($rows->first()['code'])->toBe('ORD-EXPORT-HHHK');

    $exportService = app(TripExcelExportService::class);
    $spreadsheet = $exportService->buildSpreadsheetFromRows($rows, $component->instance()->getFilteredTrips());
    $sheet1 = $spreadsheet->getSheet(0);

    // Dòng 5 (dòng đầu tiên của dữ liệu) phải là ORD-EXPORT-HHHK
    expect($sheet1->getCell('A5')->getValue())->toBe('HHHK');
    expect($sheet1->getCell('B5')->getValue())->toBe('ORD-EXPORT-HHHK');

    // Dòng 6 không có dữ liệu vì đã lọc loại bỏ ORD-EXPORT-EXT
    expect($sheet1->getCell('B6')->getValue())->toBeNull();
});

test('trip statistics report date preset buttons update dates and active state correctly', function () {
    $component = Livewire::test(TripStatisticsReport::class);

    // Mặc định là Tháng này
    expect($component->instance()->isDatePresetActive('thisMonth'))->toBeTrue();
    expect($component->instance()->isDatePresetActive('today'))->toBeFalse();

    // Chọn Hôm nay
    $component->call('setDatePreset', 'today');
    expect($component->instance()->isDatePresetActive('today'))->toBeTrue();
    expect($component->instance()->isDatePresetActive('thisMonth'))->toBeFalse();
    expect($component->get('startDate'))->toBe(now()->format('Y-m-d'));
    expect($component->get('endDate'))->toBe(now()->format('Y-m-d'));

    // Chọn Hôm qua
    $component->call('setDatePreset', 'yesterday');
    expect($component->instance()->isDatePresetActive('yesterday'))->toBeTrue();
    expect($component->instance()->isDatePresetActive('today'))->toBeFalse();

    // Chọn 7 ngày qua
    $component->call('setDatePreset', 'last7days');
    expect($component->instance()->isDatePresetActive('last7days'))->toBeTrue();

    // Chọn Tháng trước
    $component->call('setDatePreset', 'lastMonth');
    expect($component->instance()->isDatePresetActive('lastMonth'))->toBeTrue();

    // Khi người dùng đổi ngày thủ công sang ngày khác
    $component->set('startDate', '2025-01-01');
    $component->set('endDate', '2025-01-15');
    expect($component->instance()->isDatePresetActive('thisMonth'))->toBeFalse();
    expect($component->instance()->isDatePresetActive('today'))->toBeFalse();
    expect($component->instance()->isDatePresetActive('yesterday'))->toBeFalse();

    // Reset filters quay về Tháng này
    $component->call('resetFilters');
    expect($component->instance()->isDatePresetActive('thisMonth'))->toBeTrue();
});

test('trip statistics report only includes completed trips', function () {
    $completedTrip = Trip::create([
        'trip_code' => 'TRIP-COMPLETED',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'status' => TripStatus::Completed,
        'started_at' => Carbon::parse('2026-04-20 09:00:00'),
        'completed_at' => Carbon::parse('2026-04-20 10:00:00'),
    ]);

    Order::create([
        'order_code' => 'ORD-COMPLETED',
        'type' => OrderType::Hhhk,
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'created_by' => $this->driver->id,
        'trip_id' => $completedTrip->id,
        'status' => OrderStatus::Completed,
        'cargo_type' => CargoType::Gcr,
    ]);

    Trip::create([
        'trip_code' => 'TRIP-PENDING',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'status' => TripStatus::Pending,
        'started_at' => Carbon::parse('2026-04-20 09:00:00'),
    ]);

    Trip::create([
        'trip_code' => 'TRIP-DELIVERING',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'status' => TripStatus::Delivering,
        'started_at' => Carbon::parse('2026-04-20 09:00:00'),
    ]);

    Trip::create([
        'trip_code' => 'TRIP-CANCELLED',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'status' => TripStatus::Cancelled,
        'started_at' => Carbon::parse('2026-04-20 09:00:00'),
    ]);

    $component = Livewire::test(TripStatisticsReport::class, [
        'startDate' => '2026-04-01',
        'endDate' => '2026-04-30',
    ]);

    $rows = $component->instance()->getAllRows();
    $codes = $rows->pluck('code')->all();
    $tripIds = $rows->pluck('trip_id')->all();

    expect($codes)->toContain('ORD-COMPLETED')
        ->and($tripIds)->toContain($completedTrip->id)
        ->and($codes)->not->toContain('TRIP-PENDING')
        ->and($codes)->not->toContain('TRIP-DELIVERING')
        ->and($codes)->not->toContain('TRIP-CANCELLED');
});
