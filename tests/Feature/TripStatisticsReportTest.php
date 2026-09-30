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
use App\Models\OrderEditLog;
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
        'activeTab' => 'external',
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

test('trip statistics report formats duration from minutes to hours and minutes correctly', function () {
    $component = Livewire::test(TripStatisticsReport::class);
    $report = $component->instance();

    // User's specific example: 11609 minutes = 193h 29p
    expect($report->formatDuration(11609))->toBe('193h 29p');
    expect($report->formatDurationTooltip(11609))->toBe('193 giờ 29 phút (11,609 phút)');

    // Other cases
    expect($report->formatDuration(40))->toBe('40p');
    expect($report->formatDurationTooltip(40))->toBe('40 phút');

    expect($report->formatDuration(60))->toBe('1h');
    expect($report->formatDurationTooltip(60))->toBe('1 giờ (60 phút)');

    expect($report->formatDuration(75))->toBe('1h 15p');
    expect($report->formatDurationTooltip(75))->toBe('1 giờ 15 phút (75 phút)');

    expect($report->formatDuration(0))->toBe('0p');
    expect($report->formatDurationTooltip(0))->toBe('0 phút');

    expect($report->formatDuration(null))->toBe('—');
    expect($report->formatDurationTooltip(null))->toBe('Chưa có dữ liệu');
});

test('trip statistics report separates data into 3 tabs and updates counts', function () {
    // 1. Chuyến HHHK
    $tripHHHK = Trip::create([
        'trip_code' => 'TRIP-TAB-HHHK',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'status' => TripStatus::Completed,
        'started_at' => Carbon::parse('2026-04-20 08:00:00'),
        'completed_at' => Carbon::parse('2026-04-20 09:00:00'),
    ]);
    Order::create([
        'order_code' => 'ORD-TAB-HHHK',
        'type' => OrderType::Hhhk,
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'created_by' => $this->driver->id,
        'trip_id' => $tripHHHK->id,
        'status' => OrderStatus::Completed,
        'cargo_type' => CargoType::Gcr,
    ]);

    // 2. Chuyến Hàng ngoài
    $tripExt = Trip::create([
        'trip_code' => 'TRIP-TAB-EXT',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'status' => TripStatus::Completed,
        'started_at' => Carbon::parse('2026-04-20 10:00:00'),
        'completed_at' => Carbon::parse('2026-04-20 11:00:00'),
    ]);
    Order::create([
        'order_code' => 'ORD-TAB-EXT',
        'type' => OrderType::External,
        'area_id' => $this->area->id,
        'customer_id' => $this->customer2->id,
        'created_by' => $this->driver->id,
        'trip_id' => $tripExt->id,
        'status' => OrderStatus::Completed,
        'cargo_type' => CargoType::Gcr,
    ]);

    // 3. Chuyến Xe không hàng (empty run - không có order)
    $tripEmpty = Trip::create([
        'trip_code' => 'TRIP-TAB-EMPTY',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'status' => TripStatus::Completed,
        'started_at' => Carbon::parse('2026-04-20 13:00:00'),
        'completed_at' => Carbon::parse('2026-04-20 14:00:00'),
        'total_km_empty' => 30,
    ]);

    $component = Livewire::test(TripStatisticsReport::class, [
        'startDate' => '2026-04-01',
        'endDate' => '2026-04-30',
    ]);

    // Kiểm tra tab counts
    $tabCounts = $component->instance()->getTabCounts();
    expect($tabCounts['HHHK'])->toBe(1);
    expect($tabCounts['external'])->toBe(1);
    expect($tabCounts['empty'])->toBe(1);

    // Mặc định là Tab HHHK
    expect($component->get('activeTab'))->toBe('HHHK');
    $rowsHHHK = $component->instance()->getAllRows();
    expect($rowsHHHK)->toHaveCount(1);
    expect($rowsHHHK->first()['code'])->toBe('ORD-TAB-HHHK');
    expect($rowsHHHK->first()['service_type'])->toBe('HHHK');

    // Chuyển sang Tab Hàng ngoài
    $component->call('setActiveTab', 'external');
    expect($component->get('activeTab'))->toBe('external');
    $rowsExt = $component->instance()->getAllRows();
    expect($rowsExt)->toHaveCount(1);
    expect($rowsExt->first()['code'])->toBe('ORD-TAB-EXT');
    expect($rowsExt->first()['service_type'])->toBe('Hàng ngoài');

    // Chuyển sang Tab Xe không hàng
    $component->call('setActiveTab', 'empty');
    expect($component->get('activeTab'))->toBe('empty');
    $rowsEmpty = $component->instance()->getAllRows();
    expect($rowsEmpty)->toHaveCount(1);
    expect($rowsEmpty->first()['code'])->toBe('TRIP-TAB-EMPTY');
    expect($rowsEmpty->first()['service_type'])->toBe('Xe không hàng');

    // Kiểm tra các nhãn tab hiển thị trong HTML
    $component->assertSee('HHHK (Hàng không)')
        ->assertSee('Hàng ngoài')
        ->assertSee('Xe không hàng');
});

test('can quick edit pcs and gw inline via updateOrderMetric', function () {
    $trip = Trip::create([
        'trip_code' => 'TRIP-METRIC-1',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'status' => TripStatus::Completed,
        'started_at' => Carbon::parse('2026-04-10 08:00:00'),
        'completed_at' => Carbon::parse('2026-04-10 10:00:00'),
        'total_km_loaded' => 20,
    ]);

    $order = Order::create([
        'order_code' => 'ORD-METRIC-1',
        'customer_id' => $this->customer->id,
        'created_by' => $this->driver->id,
        'area_id' => $this->area->id,
        'pickup_location_id' => $this->pickupLocation->id,
        'trip_id' => $trip->id,
        'status' => OrderStatus::Completed,
        'type' => OrderType::Hhhk,
        'cargo_type' => CargoType::Gcr,
        'total_packages' => 10,
        'total_weight' => 100.5,
        'chargeable_weight' => 1.5,
    ]);

    $component = Livewire::test(TripStatisticsReport::class, [
        'startDate' => '2026-04-01',
        'endDate' => '2026-04-30',
        'activeTab' => 'HHHK',
    ]);

    // Kiểm tra ban đầu
    $metrics = $component->instance()->getSummaryMetrics();
    expect($metrics['total_pcs'])->toBe(10);
    expect((float) $metrics['total_gw'])->toBe(100.5);

    // Cập nhật PCS
    $component->call('updateOrderMetric', $order->id, 'total_packages', 35);
    $order->refresh();
    expect($order->total_packages)->toBe(35);

    // Metrics sau khi sửa PCS
    $metricsAfterPcs = $component->instance()->getSummaryMetrics();
    expect($metricsAfterPcs['total_pcs'])->toBe(35);

    // Cập nhật GW
    $component->call('updateOrderMetric', $order->id, 'total_weight', 250.75);
    $order->refresh();
    expect((float) $order->total_weight)->toBe(250.75);

    // Metrics sau khi sửa GW
    $metricsAfterGw = $component->instance()->getSummaryMetrics();
    expect((float) $metricsAfterGw['total_gw'])->toBe(250.75);

    // Kiểm tra OrderObserver đã tự động ghi log vào OrderEditLog
    expect(OrderEditLog::where('order_id', $order->id)->where('field', 'total_packages')->exists())->toBeTrue();
    expect(OrderEditLog::where('order_id', $order->id)->where('field', 'total_weight')->exists())->toBeTrue();
});

test('handles invalid fields and non-existent orders for updateOrderMetric cleanly', function () {
    $trip = Trip::create([
        'trip_code' => 'TRIP-METRIC-2',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'status' => TripStatus::Completed,
        'started_at' => Carbon::parse('2026-04-12 08:00:00'),
        'completed_at' => Carbon::parse('2026-04-12 10:00:00'),
    ]);

    $order = Order::create([
        'order_code' => 'ORD-METRIC-2',
        'customer_id' => $this->customer->id,
        'created_by' => $this->driver->id,
        'area_id' => $this->area->id,
        'trip_id' => $trip->id,
        'status' => OrderStatus::Completed,
        'type' => OrderType::Hhhk,
        'total_packages' => 5,
        'total_weight' => 50.0,
    ]);

    $component = Livewire::test(TripStatisticsReport::class, [
        'startDate' => '2026-04-01',
        'endDate' => '2026-04-30',
    ]);

    // Trường không hợp lệ (không cho phép sửa)
    $component->call('updateOrderMetric', $order->id, 'status', 'cancelled');
    $order->refresh();
    expect($order->status)->toBe(OrderStatus::Completed);

    // ID đơn hàng không tồn tại (xử lý an toàn không sập)
    $component->call('updateOrderMetric', 999999, 'total_packages', 20);

    // Cập nhật giá trị rỗng/null cho PCS và GW
    $component->call('updateOrderMetric', $order->id, 'total_packages', '');
    $order->refresh();
    expect($order->total_packages)->toBeNull();

    $component->call('updateOrderMetric', $order->id, 'total_weight', '');
    $order->refresh();
    expect($order->total_weight)->toBeNull();
});
