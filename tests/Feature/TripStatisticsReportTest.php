<?php

use App\Enums\CargoType;
use App\Enums\CheckpointType;
use App\Enums\DriverSwapReason;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\TripStatus;
use App\Enums\VehicleOwnerType;
use App\Enums\VehicleType;
use App\Filament\Pages\TripStatisticsReport;
use App\Filament\Widgets\TripStatisticsTable;
use App\Models\Area;
use App\Models\Customer;
use App\Models\DriverSwap;
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
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
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
        ->assertSee('Bảng thống kê tổng hợp các chuyến đã phục vụ')
        ->assertActionVisible('export')
        ->assertActionVisible('pickRange');
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
    // time4 (22h wait) = time1 (60) + time2 (60) + (22:00 - 19:00 = 180) = 300 mins (khi C < 22:00 và D >= 22:00)
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

test('trip statistics report exportExcel includes both HHHK and external cargo regardless of active tab and respects right-side filters', function () {
    $loadExportedSpreadsheet = function (StreamedResponse $response): Spreadsheet {
        ob_start();
        $response->sendContent();
        $binary = ob_get_clean();

        $tmpPath = tempnam(sys_get_temp_dir(), 'trip_stats_export_').'.xlsx';
        file_put_contents($tmpPath, $binary);

        try {
            return IOFactory::load($tmpPath);
        } finally {
            @unlink($tmpPath);
        }
    };

    $tripHhhk = Trip::create([
        'trip_code' => 'TRIP-BOTH-HHHK',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'status' => TripStatus::Completed,
        'started_at' => Carbon::parse('2026-04-20 09:00:00'),
        'completed_at' => Carbon::parse('2026-04-20 10:00:00'),
    ]);

    Order::create([
        'order_code' => 'ORD-BOTH-HHHK',
        'type' => OrderType::Hhhk,
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'created_by' => $this->driver->id,
        'trip_id' => $tripHhhk->id,
        'status' => OrderStatus::Completed,
        'cargo_type' => CargoType::Gcr,
    ]);

    $tripExt = Trip::create([
        'trip_code' => 'TRIP-BOTH-EXT',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'status' => TripStatus::Completed,
        'started_at' => Carbon::parse('2026-04-20 11:00:00'),
        'completed_at' => Carbon::parse('2026-04-20 12:00:00'),
    ]);

    Order::create([
        'order_code' => 'ORD-BOTH-EXT',
        'type' => OrderType::External,
        'area_id' => $this->area->id,
        'customer_id' => $this->customer2->id,
        'created_by' => $this->driver->id,
        'trip_id' => $tripExt->id,
        'status' => OrderStatus::Completed,
        'cargo_type' => CargoType::Gcr,
    ]);

    Trip::create([
        'trip_code' => 'TRIP-BOTH-EMPTY',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'status' => TripStatus::Completed,
        'started_at' => Carbon::parse('2026-04-20 13:00:00'),
        'completed_at' => Carbon::parse('2026-04-20 14:00:00'),
    ]);

    // 1. Đang ở tab HHHK: Sheet 1 phải có cả HHHK và Hàng ngoài (sắp xếp theo loại hình phục vụ: HHHK trước, Hàng ngoài sau; không có Xe không hàng), Sheet 2 vẫn có Hàng ngoài
    $hhhkTab = Livewire::test(TripStatisticsReport::class, [
        'startDate' => '2026-04-01',
        'endDate' => '2026-04-30',
        'activeTab' => 'HHHK',
    ]);
    $spreadsheetHhhk = $loadExportedSpreadsheet($hhhkTab->instance()->exportExcel());

    expect($spreadsheetHhhk->getSheet(0)->getCell('A5')->getValue())->toBe('HHHK')
        ->and($spreadsheetHhhk->getSheet(0)->getCell('B5')->getValue())->toBe('ORD-BOTH-HHHK')
        ->and($spreadsheetHhhk->getSheet(0)->getCell('A6')->getValue())->toBe('Hàng ngoài')
        ->and($spreadsheetHhhk->getSheet(0)->getCell('B6')->getValue())->toBe('ORD-BOTH-EXT')
        ->and($spreadsheetHhhk->getSheet(0)->getCell('B7')->getValue())->toBeNull()
        ->and($spreadsheetHhhk->getSheet(1)->getCell('B3')->getValue())->toBe('ORD-BOTH-EXT');

    // 2. Đang ở tab Hàng ngoài: Sheet 1 cũng phải có cả HHHK và Hàng ngoài theo đúng thứ tự loại hình phục vụ
    $extTab = Livewire::test(TripStatisticsReport::class, [
        'startDate' => '2026-04-01',
        'endDate' => '2026-04-30',
        'activeTab' => 'external',
    ]);
    $spreadsheetExt = $loadExportedSpreadsheet($extTab->instance()->exportExcel());

    expect($spreadsheetExt->getSheet(0)->getCell('A5')->getValue())->toBe('HHHK')
        ->and($spreadsheetExt->getSheet(0)->getCell('B5')->getValue())->toBe('ORD-BOTH-HHHK')
        ->and($spreadsheetExt->getSheet(0)->getCell('A6')->getValue())->toBe('Hàng ngoài')
        ->and($spreadsheetExt->getSheet(0)->getCell('B6')->getValue())->toBe('ORD-BOTH-EXT')
        ->and($spreadsheetExt->getSheet(0)->getCell('B7')->getValue())->toBeNull()
        ->and($spreadsheetExt->getSheet(1)->getCell('B3')->getValue())->toBe('ORD-BOTH-EXT');

    // 3. Lọc theo filter bên phải (customerId): cả Sheet 1 và Sheet 2 chỉ giữ đơn của khách được chọn
    $filteredTab = Livewire::test(TripStatisticsReport::class, [
        'startDate' => '2026-04-01',
        'endDate' => '2026-04-30',
        'activeTab' => 'HHHK',
        'customerId' => $this->customer2->id,
    ]);
    $spreadsheetFiltered = $loadExportedSpreadsheet($filteredTab->instance()->exportExcel());

    expect($spreadsheetFiltered->getSheet(0)->getCell('B5')->getValue())->toBe('ORD-BOTH-EXT')
        ->and($spreadsheetFiltered->getSheet(0)->getCell('B6')->getValue())->toBeNull()
        ->and($spreadsheetFiltered->getSheet(1)->getCell('B3')->getValue())->toBe('ORD-BOTH-EXT');

    // 4. Đang ở tab Xe không hàng: Sheet 1 xuất Xe không hàng, Sheet 2 vẫn xuất Hàng ngoài theo bộ lọc bên phải
    $emptyTab = Livewire::test(TripStatisticsReport::class, [
        'startDate' => '2026-04-01',
        'endDate' => '2026-04-30',
        'activeTab' => 'empty',
    ]);
    $spreadsheetEmpty = $loadExportedSpreadsheet($emptyTab->instance()->exportExcel());

    expect($spreadsheetEmpty->getSheet(0)->getCell('A5')->getValue())->toBe('Xe không hàng')
        ->and($spreadsheetEmpty->getSheet(0)->getCell('B5')->getValue())->toBe('TRIP-BOTH-EMPTY')
        ->and($spreadsheetEmpty->getSheet(0)->getCell('B6')->getValue())->toBeNull()
        ->and($spreadsheetEmpty->getSheet(1)->getCell('B3')->getValue())->toBe('ORD-BOTH-EXT');
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

    // Khi ngày trùng nhau (ví dụ: ngày 7 của tháng thì 7 ngày qua và tháng này cùng khoảng 01 -> 07)
    // chỉ có 1 nút được active duy nhất theo datePreset đang chọn
    $component->call('setDatePreset', 'thisMonth');
    expect($component->instance()->isDatePresetActive('thisMonth'))->toBeTrue()
        ->and($component->instance()->isDatePresetActive('last7days'))->toBeFalse();

    $component->call('setDatePreset', 'last7days');
    expect($component->instance()->isDatePresetActive('last7days'))->toBeTrue()
        ->and($component->instance()->isDatePresetActive('thisMonth'))->toBeFalse();
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

test('trip statistics report formats duration as total minutes correctly', function () {
    $component = Livewire::test(TripStatisticsReport::class);
    $report = $component->instance();

    expect($report->formatDuration(11609))->toBe('11609');
    expect($report->formatDurationTooltip(11609))->toBe('193 giờ 29 phút (11,609 phút)');

    expect($report->formatDuration(90))->toBe('90');
    expect($report->formatDurationTooltip(90))->toBe('1 giờ 30 phút (90 phút)');

    expect($report->formatDuration(40))->toBe('40');
    expect($report->formatDurationTooltip(40))->toBe('40 phút');

    expect($report->formatDuration(60))->toBe('60');
    expect($report->formatDurationTooltip(60))->toBe('1 giờ (60 phút)');

    expect($report->formatDuration(75))->toBe('75');
    expect($report->formatDurationTooltip(75))->toBe('1 giờ 15 phút (75 phút)');

    expect($report->formatDuration(0))->toBe('0');
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

    // Nhãn tab do widget React vẽ, lấy từ cấu hình gửi xuống
    $tabs = Livewire::test(TripStatisticsTable::class, [
        'isReady' => true,
        'startDate' => '2026-04-01',
        'endDate' => '2026-04-30',
        'activeTab' => 'empty',
    ])->instance()->getRendererConfiguration()['tabs'];

    expect(array_column($tabs, 'label'))->toBe(['HHHK (Hàng không)', 'Hàng ngoài', 'Xe không hàng']);
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

test('pagination works with the react widget configuration', function () {
    // Chuyến không hàng để bảng có dữ liệu
    Trip::create([
        'trip_code' => 'TRIP-PAGE-01',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'status' => TripStatus::Completed,
        'started_at' => Carbon::parse('2026-04-20 09:00:00'),
        'completed_at' => Carbon::parse('2026-04-20 10:00:00'),
    ]);

    $pagination = Livewire::test(TripStatisticsTable::class, [
        'isReady' => true,
        'startDate' => '2026-04-01',
        'endDate' => '2026-04-30',
        'activeTab' => 'empty',
    ])->instance()->getRendererConfiguration()['pagination'];

    expect($pagination['total'])->toBe(1)
        ->and($pagination['lastPage'])->toBe(1)
        ->and($pagination['perPage'])->toBe(25);

    Livewire::test(TripStatisticsReport::class, ['activeTab' => 'empty'])
        ->set('startDate', '2026-04-01')
        ->set('endDate', '2026-04-30')
        ->call('nextPage', 'page')
        ->assertSet('page', 2)
        ->call('previousPage', 'page')
        ->assertSet('page', 1)
        ->call('previousPage', 'page')
        ->assertSet('page', 1)
        ->call('gotoPage', 3, 'page')
        ->assertSet('page', 3)
        ->set('perPage', 50)
        ->assertSet('page', 1);
});

test('react widget renders the host with escaped configuration', function () {
    Trip::create([
        'trip_code' => 'TRIP-REACT-01',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'status' => TripStatus::Completed,
        'started_at' => Carbon::parse('2026-10-05 08:00:00'),
        'completed_at' => Carbon::parse('2026-10-05 10:00:00'),
        // Nội dung người dùng nhập không được đóng thẻ script chứa JSON
        'note' => '</script><script>alert(1)</script>',
    ]);

    $component = Livewire::test(TripStatisticsTable::class, [
        'isReady' => true,
        'activeTab' => 'empty',
        'startDate' => '2026-10-01',
        'endDate' => '2026-10-31',
    ])
        ->assertSuccessful()
        ->assertSeeHtml('jsWidgetComponent')
        ->assertSeeHtml('data-renderer-configuration')
        ->assertSeeHtml('TRIP-REACT-01')
        ->assertDontSeeHtml('</script><script>alert(1)</script>');

    expect($component->instance()->getRendererConfiguration()['rows'][0]['note'])
        ->toBe('</script><script>alert(1)</script>');
});

test('chargeable weight column is hidden on the HHHK tab only', function () {
    $hhhk = Livewire::test(TripStatisticsTable::class, ['isReady' => true, 'activeTab' => 'HHHK'])->instance()->getRendererConfiguration();
    $external = Livewire::test(TripStatisticsTable::class, ['isReady' => true, 'activeTab' => 'external'])->instance()->getRendererConfiguration();

    expect($hhhk['showChargeableWeight'])->toBeFalse()
        ->and($external['showChargeableWeight'])->toBeTrue();
});

test('last month preset does not overflow into the current month on the 31st', function () {
    // 31/10 − 1 tháng không được tràn sang 01/10
    Carbon::setTestNow('2026-10-31 09:00:00');

    $component = Livewire::test(TripStatisticsReport::class)
        ->call('setDatePreset', 'lastMonth')
        ->assertSet('startDate', '2026-09-01')
        ->assertSet('endDate', '2026-09-30');

    expect($component->instance()->isDatePresetActive('lastMonth'))->toBeTrue()
        ->and($component->instance()->isDatePresetActive('thisMonth'))->toBeFalse();

    Carbon::setTestNow();
});

test('editCharge modal displays and saves timestamps for multiple delivery points', function () {
    $trip = Trip::create([
        'trip_code' => 'TRIP-MULTI-DP',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'status' => TripStatus::Completed,
        'started_at' => Carbon::parse('2026-10-05 08:00:00'),
        'completed_at' => Carbon::parse('2026-10-05 12:00:00'),
    ]);

    $order = Order::create([
        'order_code' => 'ORD-MULTI-DP',
        'trip_id' => $trip->id,
        'customer_id' => $this->customer->id,
        'area_id' => $this->area->id,
        'pickup_location_id' => $this->pickupLocation->id,
        'cargo_type' => CargoType::Gcr,
        'type' => OrderType::External,
        'status' => OrderStatus::Completed,
        'created_by' => $this->driver->id,
        'planned_loading_at' => Carbon::parse('2026-10-05 07:30:00'),
    ]);

    $dp1 = OrderDeliveryPoint::create([
        'order_id' => $order->id,
        'location_id' => $this->deliveryLocation->id,
        'sequence' => 1,
        'arrived_at' => Carbon::parse('2026-10-05 09:00:00'),
        'delivered_at' => Carbon::parse('2026-10-05 09:30:00'),
        'total_packages' => 20,
        'total_weight' => 500,
    ]);

    $loc2 = Location::create([
        'code' => 'KHO-2',
        'name' => 'Kho Phụ 2',
        'loc_type' => 'delivery',
        'is_active' => true,
    ]);

    $dp2 = OrderDeliveryPoint::create([
        'order_id' => $order->id,
        'location_id' => $loc2->id,
        'sequence' => 2,
        'arrived_at' => Carbon::parse('2026-10-05 10:15:00'),
        'delivered_at' => Carbon::parse('2026-10-05 11:00:00'),
        'total_packages' => 10,
        'total_weight' => 250,
    ]);

    $component = Livewire::test(TripStatisticsReport::class)
        ->mountAction('editCharge', ['orderId' => $order->id]);

    $formData = $component->instance()->mountedActions[0]['data'];
    expect($formData['delivery_points'])->toHaveKey($dp1->id)
        ->and($formData['delivery_points'])->toHaveKey($dp2->id)
        ->and($formData['delivery_points'][$dp1->id]['arrived_at'])->toBe('2026-10-05 09:00:00')
        ->and($formData['delivery_points'][$dp2->id]['delivered_at'])->toBe('2026-10-05 11:00:00');

    // Chỉnh sửa thời gian mốc các điểm trả hàng
    $component->setActionData([
        'summary_requested_at' => '2026-10-05 07:45:00',
        'summary_loading_at' => '2026-10-05 08:00:00',
        'summary_departed_at' => '2026-10-05 08:30:00',
        'delivery_points' => [
            $dp1->id => [
                'arrived_at' => '2026-10-05 09:10:00',
                'delivered_at' => '2026-10-05 09:40:00',
            ],
            $dp2->id => [
                'arrived_at' => '2026-10-05 10:30:00',
                'delivered_at' => '2026-10-05 11:15:00',
            ],
        ],
    ])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect($dp1->fresh()->arrived_at->format('Y-m-d H:i:s'))->toBe('2026-10-05 09:10:00')
        ->and($dp1->fresh()->delivered_at->format('Y-m-d H:i:s'))->toBe('2026-10-05 09:40:00')
        ->and($dp2->fresh()->arrived_at->format('Y-m-d H:i:s'))->toBe('2026-10-05 10:30:00')
        ->and($dp2->fresh()->delivered_at->format('Y-m-d H:i:s'))->toBe('2026-10-05 11:15:00');

    // Kiểm tra checkpoint tương ứng đã được lưu/cập nhật
    $cp1Arrived = TripCheckpoint::where('order_id', $order->id)
        ->where('delivery_point_id', $dp1->id)
        ->where('checkpoint_type', CheckpointType::ArrivedDelivery)
        ->first();
    expect($cp1Arrived)->not->toBeNull()
        ->and($cp1Arrived->occurred_at->format('Y-m-d H:i:s'))->toBe('2026-10-05 09:10:00');

    $cp2Completed = TripCheckpoint::where('order_id', $order->id)
        ->where('delivery_point_id', $dp2->id)
        ->where('checkpoint_type', CheckpointType::Completed)
        ->first();
    expect($cp2Completed)->not->toBeNull()
        ->and($cp2Completed->occurred_at->format('Y-m-d H:i:s'))->toBe('2026-10-05 11:15:00');
});

test('trip statistics correctly calculates durations for multi-delivery point orders', function () {
    $trip = Trip::create([
        'trip_code' => 'TRIP-MULTI-POINTS',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'status' => TripStatus::Completed,
        'started_at' => Carbon::parse('2026-10-05 08:30:00'),
        'completed_at' => Carbon::parse('2026-10-05 11:15:00'),
    ]);

    $order = Order::create([
        'order_code' => 'ORD-MULTI-CALC',
        'type' => OrderType::External,
        'area_id' => $this->area->id,
        'customer_id' => $this->customer2->id,
        'created_by' => $this->driver->id,
        'pickup_location_id' => $this->pickupLocation->id,
        'planned_loading_at' => Carbon::parse('2026-10-05 08:00:00'),
        'trip_id' => $trip->id,
        'status' => OrderStatus::Completed,
        'cargo_type' => CargoType::Gcr,
    ]);

    // A = 08:00, B = 08:30 -> Loading = 30m
    TripCheckpoint::create([
        'trip_id' => $trip->id,
        'order_id' => $order->id,
        'checkpoint_type' => CheckpointType::ArrivedPickup,
        'occurred_at' => Carbon::parse('2026-10-05 08:00:00'),
    ]);
    TripCheckpoint::create([
        'trip_id' => $trip->id,
        'order_id' => $order->id,
        'checkpoint_type' => CheckpointType::LeftPickup,
        'occurred_at' => Carbon::parse('2026-10-05 08:30:00'),
    ]);

    // DP1: Arrived C1 = 09:10, Delivered D1 = 09:40
    // Travel leg 1: 08:30 -> 09:10 = 40m
    // Unloading drop 1: 09:10 -> 09:40 = 30m
    $dp1 = OrderDeliveryPoint::create([
        'order_id' => $order->id,
        'location_id' => $this->deliveryLocation->id,
        'sequence' => 1,
        'arrived_at' => Carbon::parse('2026-10-05 09:10:00'),
        'delivered_at' => Carbon::parse('2026-10-05 09:40:00'),
    ]);
    TripCheckpoint::create([
        'trip_id' => $trip->id,
        'order_id' => $order->id,
        'delivery_point_id' => $dp1->id,
        'checkpoint_type' => CheckpointType::ArrivedDelivery,
        'occurred_at' => Carbon::parse('2026-10-05 09:10:00'),
    ]);
    TripCheckpoint::create([
        'trip_id' => $trip->id,
        'order_id' => $order->id,
        'delivery_point_id' => $dp1->id,
        'checkpoint_type' => CheckpointType::Completed,
        'occurred_at' => Carbon::parse('2026-10-05 09:40:00'),
    ]);

    // DP2: Arrived C2 = 10:30, Delivered D2 = 11:15
    // Travel leg 2: 09:40 -> 10:30 = 50m
    // Unloading drop 2: 10:30 -> 11:15 = 45m
    $dp2 = OrderDeliveryPoint::create([
        'order_id' => $order->id,
        'location_id' => $this->deliveryLocation->id,
        'sequence' => 2,
        'arrived_at' => Carbon::parse('2026-10-05 10:30:00'),
        'delivered_at' => Carbon::parse('2026-10-05 11:15:00'),
    ]);
    TripCheckpoint::create([
        'trip_id' => $trip->id,
        'order_id' => $order->id,
        'delivery_point_id' => $dp2->id,
        'checkpoint_type' => CheckpointType::ArrivedDelivery,
        'occurred_at' => Carbon::parse('2026-10-05 10:30:00'),
    ]);
    TripCheckpoint::create([
        'trip_id' => $trip->id,
        'order_id' => $order->id,
        'delivery_point_id' => $dp2->id,
        'checkpoint_type' => CheckpointType::Completed,
        'occurred_at' => Carbon::parse('2026-10-05 11:15:00'),
    ]);

    $service = app(TripExcelExportService::class);
    $rows = $service->getTripSummaryRowsFromOrders(Order::query()->whereKey($order->id));
    $row = $rows->first();

    // Total Travel = Leg 1 (40) + Leg 2 (50) = 90 mins
    // Total Unloading = Drop 1 (30) + Drop 2 (45) = 75 mins
    // Total Loading = 30 mins
    // Total Trip = 30 + 90 + 75 = 195 mins
    expect($row['time_loading'])->toBe(30)
        ->and($row['time_travel'])->toBe(90)
        ->and($row['time_unloading'])->toBe(75)
        ->and($row['time_waiting_22h'])->toBe(0)
        ->and($row['time_total_trip'])->toBe(195);
});

test('adjusted_at takes precedence over occurred_at for checkpoints and excel export', function () {
    $cp = TripCheckpoint::create([
        'checkpoint_type' => CheckpointType::ArrivedPickup,
        'occurred_at' => Carbon::parse('2026-10-05 08:00:00'),
        'adjusted_at' => Carbon::parse('2026-10-05 08:15:00'),
    ]);

    expect($cp->effectiveOccurredAt()->format('H:i'))->toBe('08:15');

    $cpWithoutAdjusted = TripCheckpoint::create([
        'checkpoint_type' => CheckpointType::LeftPickup,
        'occurred_at' => Carbon::parse('2026-10-05 08:45:00'),
    ]);

    expect($cpWithoutAdjusted->effectiveOccurredAt()->format('H:i'))->toBe('08:45');
});

test('sheet 1 trip summary simplifies multi-point journey to first and last point only', function () {
    $service = app(TripExcelExportService::class);
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();

    $service->writeTripSummaryRowFromData($sheet, 5, [
        'service_type' => 'Hàng ngoài',
        'code' => 'ORD-MULTI-TEST',
        'date' => Carbon::parse('2026-11-06'),
        'plate_number' => '29C-12345',
        'journey' => 'NEWWING -> NCTS -> ALSC -> ALSE',
    ]);

    expect($sheet->getCell('E5')->getValue())->toBe('NEWWING -> ALSE');

    // Single delivery point preserves both points
    $service->writeTripSummaryRowFromData($sheet, 6, [
        'service_type' => 'Hàng ngoài',
        'code' => 'ORD-SINGLE-TEST',
        'date' => Carbon::parse('2026-11-06'),
        'plate_number' => '29C-12345',
        'journey' => 'NEWWING -> ALSE',
    ]);

    expect($sheet->getCell('E6')->getValue())->toBe('NEWWING -> ALSE');
});

test('statistics widget first render sends only the frame so React can show the skeleton', function () {
    $config = Livewire::test(TripStatisticsTable::class, [
        'activeTab' => 'external',
        'startDate' => '2026-10-01',
        'endDate' => '2026-10-31',
    ])->instance()->getRendererConfiguration();

    expect($config['loaded'])->toBeFalse()
        ->and($config['rows'])->toBe([])
        ->and($config['pagination']['total'])->toBe(0)
        ->and(collect($config['tabs'])->pluck('key')->all())->toBe(['HHHK', 'external', 'empty'])
        ->and($config['filters']['activeTab'])->toBe('external');
});

test('trip statistics report and excel export display external_driver_name for rented vehicles', function () {
    $rentedVehicle = Vehicle::create([
        'plate_number' => '15H-08970',
        'owner' => 'ASGS',
        'type' => VehicleOwnerType::Rent,
        'vehicle_type' => VehicleType::Normal,
        'external_driver_name' => 'Nguyễn Văn Thuê Ngoài',
        'is_active' => true,
    ]);

    $trip = Trip::create([
        'trip_code' => 'TRIP-RENT-STAT-1',
        'vehicle_id' => $rentedVehicle->id,
        'driver_id' => null,
        'status' => TripStatus::Completed,
        'started_at' => Carbon::parse('2026-04-20 09:00:00'),
        'completed_at' => Carbon::parse('2026-04-20 10:00:00'),
    ]);

    Order::create([
        'order_code' => 'ASG-RENT-3873',
        'type' => OrderType::Hhhk,
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'created_by' => $this->driver->id,
        'trip_id' => $trip->id,
        'status' => OrderStatus::Completed,
        'cargo_type' => CargoType::Gcr,
    ]);

    $component = Livewire::test(TripStatisticsReport::class, [
        'startDate' => '2026-04-01',
        'endDate' => '2026-04-30',
        'activeTab' => 'HHHK',
        'search' => 'Thuê Ngoài',
    ]);

    $tableConfig = $component->instance()->getReactTableConfiguration();

    expect($tableConfig['rows'])->toHaveCount(1)
        ->and($tableConfig['rows'][0]['driverName'])->toBe('Nguyễn Văn Thuê Ngoài');

    $spreadsheet = app(TripExcelExportService::class)
        ->buildSpreadsheetFromRows($component->instance()->getAllRows(), $component->instance()->getFilteredTrips());

    expect($spreadsheet->getSheet(0)->getCell('U5')->getValue())->toBe('Nguyễn Văn Thuê Ngoài');
});

test('summary sheet column P only displays ACSV, ALSC, NCTS and leaves others empty', function () {
    $service = app(TripExcelExportService::class);

    $makeRow = fn (string $code, string $journey, string $warehouse): array => [
        'service_type' => 'Hàng ngoài',
        'code' => $code,
        'date' => Carbon::parse('2026-04-20'),
        'plate_number' => '29E12067',
        'journey' => $journey,
        'time_a' => null,
        'time_b' => null,
        'time_c' => null,
        'time_d' => null,
        'time_loading' => null,
        'time_travel' => null,
        'time_unloading' => null,
        'time_waiting_22h' => 0,
        'time_total_trip' => null,
        'customer' => 'SEVT',
        'warehouse' => $warehouse,
        'vehicle_owner' => 'Xe công ty',
        'vehicle_type' => 'Xe thường',
        'note' => '',
        'is_return_trip' => false,
        'driver_name' => 'Tài xế A',
        'km_loaded' => 0,
        'km_empty' => 0,
        'pcs' => 0,
        'gw' => 0,
        'cargo_type' => 'GCR',
        'chargeable_weight' => 0,
    ];

    $spreadsheet = $service->buildSpreadsheetFromRows(collect([
        $makeRow('ORD-1', 'ASG -> ALSC', 'ALSC'),
        $makeRow('ORD-2', 'ACSV -> SEVT', 'SEVT'),
        $makeRow('ORD-3', 'NEWWING -> ALSE -> NCTS', 'ALSE'),
        $makeRow('ORD-4', 'SEVT -> ASGL', 'ASGL'),
        $makeRow('ORD-5', 'ALSB -> ASG', 'ALSB'),
    ]));

    $sheet = $spreadsheet->getSheet(0);

    expect($sheet->getCell('P5')->getValue())->toBe('ALSC')
        ->and($sheet->getCell('P6')->getValue())->toBe('ACSV')
        ->and($sheet->getCell('P7')->getValue())->toBe('NCTS')
        ->and($sheet->getCell('P8')->getValue())->toBe('')
        ->and($sheet->getCell('P9')->getValue())->toBe('');
});

test('summary sheet duration columns output numeric minutes in Excel', function () {
    $service = app(TripExcelExportService::class);

    $spreadsheet = $service->buildSpreadsheetFromRows(collect([[
        'service_type' => 'Hàng ngoài',
        'code' => 'ORD-DUR-1',
        'date' => Carbon::parse('2026-04-20'),
        'plate_number' => '29E12067',
        'journey' => 'ASG -> ALSC',
        'time_a' => null,
        'time_b' => null,
        'time_c' => null,
        'time_d' => null,
        'time_loading' => 0,
        'time_travel' => 90,
        'time_unloading' => 60,
        'time_waiting_22h' => 150,
        'time_total_trip' => 150,
        'customer' => 'SEVT',
        'warehouse' => 'ALSC',
        'vehicle_owner' => 'Xe công ty',
        'vehicle_type' => 'Xe thường',
        'note' => '',
        'is_return_trip' => false,
        'driver_name' => 'Tài xế A',
        'km_loaded' => 0,
        'km_empty' => 0,
        'pcs' => 0,
        'gw' => 0,
        'cargo_type' => 'GCR',
        'chargeable_weight' => 0,
    ]]));

    $sheet = $spreadsheet->getSheet(0);

    expect($sheet->getCell('J4')->getValue())->toBe('Thời gian xe đóng hàng (phút)')
        ->and($sheet->getCell('N4')->getValue())->toBe('Thời gian cho 1 chuyến hàng (phút)')
        ->and($sheet->getCell('J5')->getValue())->toBe(0)
        ->and($sheet->getCell('K5')->getValue())->toBe(90)
        ->and($sheet->getCell('L5')->getValue())->toBe(60)
        ->and($sheet->getCell('M5')->getValue())->toBe(150)
        ->and($sheet->getCell('N5')->getValue())->toBe(150);
});

test('trip statistics table warehouse column only displays ACSV, ALSC, NCTS and leaves others empty', function () {
    $acsvLocation = Location::create([
        'code' => 'ACSV',
        'name' => 'Kho ACSV',
        'address' => 'Nội Bài',
        'lat' => 21.221,
        'lng' => 105.806,
        'loc_type' => 'pickup',
        'is_active' => true,
    ]);

    $sevtLocation = Location::create([
        'code' => 'SEVT',
        'name' => 'Samsung Thái Nguyên',
        'address' => 'Thái Nguyên',
        'lat' => 21.425,
        'lng' => 105.879,
        'loc_type' => 'delivery',
        'is_active' => true,
    ]);

    $tripPickupAcsv = Trip::create([
        'trip_code' => 'TRIP-WH-ACSV',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'status' => TripStatus::Completed,
        'started_at' => Carbon::parse('2026-04-20 09:00:00'),
        'completed_at' => Carbon::parse('2026-04-20 10:00:00'),
    ]);

    $orderPickupAcsv = Order::create([
        'order_code' => 'ORD-WH-1',
        'type' => OrderType::Hhhk,
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'created_by' => $this->driver->id,
        'pickup_location_id' => $acsvLocation->id,
        'trip_id' => $tripPickupAcsv->id,
        'status' => OrderStatus::Completed,
        'cargo_type' => CargoType::Gcr,
    ]);

    OrderDeliveryPoint::create([
        'order_id' => $orderPickupAcsv->id,
        'location_id' => $sevtLocation->id,
        'sequence' => 1,
        'arrived_at' => Carbon::parse('2026-04-20 09:30:00'),
        'delivered_at' => Carbon::parse('2026-04-20 10:00:00'),
    ]);

    $tripOther = Trip::create([
        'trip_code' => 'TRIP-WH-OTHER',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'status' => TripStatus::Completed,
        'started_at' => Carbon::parse('2026-04-20 11:00:00'),
        'completed_at' => Carbon::parse('2026-04-20 12:00:00'),
    ]);

    $orderOther = Order::create([
        'order_code' => 'ORD-WH-2',
        'type' => OrderType::Hhhk,
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'created_by' => $this->driver->id,
        'pickup_location_id' => $this->pickupLocation->id,
        'trip_id' => $tripOther->id,
        'status' => OrderStatus::Completed,
        'cargo_type' => CargoType::Gcr,
    ]);

    OrderDeliveryPoint::create([
        'order_id' => $orderOther->id,
        'location_id' => $sevtLocation->id,
        'sequence' => 1,
        'arrived_at' => Carbon::parse('2026-04-20 11:30:00'),
        'delivered_at' => Carbon::parse('2026-04-20 12:00:00'),
    ]);

    $component = Livewire::test(TripStatisticsReport::class, [
        'startDate' => '2026-04-01',
        'endDate' => '2026-04-30',
        'activeTab' => 'HHHK',
    ]);

    $rowsByCode = collect($component->instance()->getReactTableConfiguration()['rows'])
        ->keyBy('code');

    expect($rowsByCode['ORD-WH-1']['warehouse'])->toBe('ACSV')
        ->and($rowsByCode['ORD-WH-2']['warehouse'])->toBe('');
});

test('trip statistics table includes area column and maps PROVINCE to Điểm khác', function () {
    $provinceArea = Area::create([
        'code' => 'PROVINCE',
        'name' => 'Hàng đi điểm khác 3 điểm chính',
        'is_active' => true,
        'sort_order' => 6,
    ]);

    $tripNba = Trip::create([
        'trip_code' => 'TRIP-AREA-NBA',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'status' => TripStatus::Completed,
        'started_at' => Carbon::parse('2026-04-21 09:00:00'),
        'completed_at' => Carbon::parse('2026-04-21 10:00:00'),
    ]);

    Order::create([
        'order_code' => 'ORD-AREA-NBA',
        'type' => OrderType::Hhhk,
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'created_by' => $this->driver->id,
        'trip_id' => $tripNba->id,
        'status' => OrderStatus::Completed,
        'cargo_type' => CargoType::Gcr,
    ]);

    $tripProvince = Trip::create([
        'trip_code' => 'TRIP-AREA-PROV',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'status' => TripStatus::Completed,
        'started_at' => Carbon::parse('2026-04-21 11:00:00'),
        'completed_at' => Carbon::parse('2026-04-21 12:00:00'),
    ]);

    Order::create([
        'order_code' => 'ORD-AREA-PROV',
        'type' => OrderType::External,
        'area_id' => $provinceArea->id,
        'customer_id' => $this->customer->id,
        'created_by' => $this->driver->id,
        'trip_id' => $tripProvince->id,
        'status' => OrderStatus::Completed,
        'cargo_type' => CargoType::Gcr,
    ]);

    $hhhkRows = collect(Livewire::test(TripStatisticsReport::class, [
        'startDate' => '2026-04-01',
        'endDate' => '2026-04-30',
        'activeTab' => 'HHHK',
    ])->instance()->getReactTableConfiguration()['rows'])->keyBy('code');

    $externalRows = collect(Livewire::test(TripStatisticsReport::class, [
        'startDate' => '2026-04-01',
        'endDate' => '2026-04-30',
        'activeTab' => 'external',
    ])->instance()->getReactTableConfiguration()['rows'])->keyBy('code');

    expect($hhhkRows['ORD-AREA-NBA']['area'])->toBe('NBA')
        ->and($externalRows['ORD-AREA-PROV']['area'])->toBe('Điểm khác');

    $filteredByArea = Livewire::test(TripStatisticsReport::class, [
        'startDate' => '2026-04-01',
        'endDate' => '2026-04-30',
        'activeTab' => 'external',
        'areaId' => $provinceArea->id,
    ]);

    expect($filteredByArea->instance()->getAllRows()->pluck('code')->all())->toBe(['ORD-AREA-PROV'])
        ->and($filteredByArea->instance()->getAreaFilters())->toContain(['id' => $provinceArea->id, 'label' => 'Điểm khác', 'count' => 1]);

    $filteredByArea->call('toggleArea', $provinceArea->id);
    expect($filteredByArea->get('areaId'))->toBeNull();

    $filteredByArea->call('toggleArea', $provinceArea->id);
    expect($filteredByArea->get('areaId'))->toBe($provinceArea->id);

    $spreadsheet = app(TripExcelExportService::class)
        ->buildSpreadsheetFromRows($filteredByArea->instance()->getAllRows(), $filteredByArea->instance()->getFilteredTrips());

    expect($spreadsheet->getSheet(0)->getCell('AB4')->getValue())->toBe('Khu vực')
        ->and($spreadsheet->getSheet(0)->getCell('AB5')->getValue())->toBe('Điểm khác');

    $filteredByArea->call('resetFilters');
    expect($filteredByArea->get('areaId'))->toBeNull();
});

test('trip statistics journey falls back to trip endLocation when order has no delivery points', function () {
    $nctsLocation = Location::create([
        'code' => 'NCTS',
        'name' => 'Kho NCTS',
        'address' => 'Nội Bài',
        'lat' => 21.221,
        'lng' => 105.806,
        'loc_type' => 'pickup',
        'is_active' => true,
    ]);

    $sdvLocation = Location::create([
        'code' => 'SDV',
        'name' => 'Công ty SDV',
        'address' => 'Bắc Ninh',
        'lat' => 21.18,
        'lng' => 106.05,
        'loc_type' => 'delivery',
        'is_active' => true,
    ]);

    $trip = Trip::create([
        'trip_code' => 'TRIP-NO-DP-1',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'status' => TripStatus::Completed,
        'start_location_id' => $nctsLocation->id,
        'end_location_id' => $sdvLocation->id,
        'started_at' => Carbon::parse('2026-04-22 09:00:00'),
        'completed_at' => Carbon::parse('2026-04-22 10:00:00'),
    ]);

    Order::create([
        'order_code' => 'ORD-NO-DP-1',
        'type' => OrderType::Hhhk,
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'created_by' => $this->driver->id,
        'pickup_location_id' => $nctsLocation->id,
        'trip_id' => $trip->id,
        'status' => OrderStatus::Completed,
        'cargo_type' => CargoType::Gcr,
    ]);

    $rows = collect(Livewire::test(TripStatisticsReport::class, [
        'startDate' => '2026-04-01',
        'endDate' => '2026-04-30',
        'activeTab' => 'HHHK',
    ])->instance()->getReactTableConfiguration()['rows'])->keyBy('code');

    expect($rows['ORD-NO-DP-1']['journey'])->toBe('NCTS -> SDV')
        ->and($rows['ORD-NO-DP-1']['warehouse'])->toBe('NCTS');
});

test('trip statistics report supports column sorting and filtering by journey', function () {
    $acsvLocation = Location::create([
        'code' => 'ACSV',
        'name' => 'Kho ACSV',
        'address' => 'Nội Bài',
        'lat' => 21.221,
        'lng' => 105.806,
        'loc_type' => 'pickup',
        'is_active' => true,
    ]);

    $sevtLocation = Location::create([
        'code' => 'SEVT',
        'name' => 'Samsung TN',
        'address' => 'Thái Nguyên',
        'lat' => 21.425,
        'lng' => 105.879,
        'loc_type' => 'delivery',
        'is_active' => true,
    ]);

    $trip1 = Trip::create([
        'trip_code' => 'TRIP-SORT-1',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'status' => TripStatus::Completed,
        'started_at' => Carbon::parse('2026-04-23 09:00:00'),
        'completed_at' => Carbon::parse('2026-04-23 10:00:00'),
    ]);

    $order1 = Order::create([
        'order_code' => 'ORD-SORT-1',
        'type' => OrderType::Hhhk,
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'created_by' => $this->driver->id,
        'pickup_location_id' => $this->pickupLocation->id, // ASG
        'trip_id' => $trip1->id,
        'status' => OrderStatus::Completed,
        'cargo_type' => CargoType::Gcr,
    ]);
    OrderDeliveryPoint::create([
        'order_id' => $order1->id,
        'location_id' => $sevtLocation->id,
        'sequence' => 1,
        'arrived_at' => Carbon::parse('2026-04-23 09:30:00'),
        'delivered_at' => Carbon::parse('2026-04-23 10:00:00'),
    ]);

    $trip2 = Trip::create([
        'trip_code' => 'TRIP-SORT-2',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'status' => TripStatus::Completed,
        'started_at' => Carbon::parse('2026-04-23 11:00:00'),
        'completed_at' => Carbon::parse('2026-04-23 12:00:00'),
    ]);

    $order2 = Order::create([
        'order_code' => 'ORD-SORT-2',
        'type' => OrderType::Hhhk,
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'created_by' => $this->driver->id,
        'pickup_location_id' => $acsvLocation->id,
        'trip_id' => $trip2->id,
        'status' => OrderStatus::Completed,
        'cargo_type' => CargoType::Gcr,
    ]);
    OrderDeliveryPoint::create([
        'order_id' => $order2->id,
        'location_id' => $this->deliveryLocation->id, // ALSC
        'sequence' => 1,
        'arrived_at' => Carbon::parse('2026-04-23 11:30:00'),
        'delivered_at' => Carbon::parse('2026-04-23 12:00:00'),
    ]);

    $component = Livewire::test(TripStatisticsReport::class, [
        'startDate' => '2026-04-01',
        'endDate' => '2026-04-30',
        'activeTab' => 'HHHK',
    ]);

    expect($component->instance()->getJourneyOptions())->toBe([
        ['value' => 'ACSV -> ALSC', 'count' => 1],
        ['value' => 'ASG -> SEVT', 'count' => 1],
    ]);

    // Asc by journey: ACSV -> ALSC before ASG -> SEVT
    $component->call('sortTable', 'journey');
    expect($component->get('sortBy'))->toBe('journey')
        ->and($component->get('sortDirection'))->toBe('asc')
        ->and($component->instance()->getAllRows()->pluck('journey')->all())->toBe(['ACSV -> ALSC', 'ASG -> SEVT']);

    // Desc by journey: ASG -> SEVT before ACSV -> ALSC
    $component->call('sortTable', 'journey');
    expect($component->get('sortDirection'))->toBe('desc')
        ->and($component->instance()->getAllRows()->pluck('journey')->all())->toBe(['ASG -> SEVT', 'ACSV -> ALSC']);

    // Direct setSort from Excel popover
    $component->call('setSort', 'journey', 'asc');
    expect($component->get('sortBy'))->toBe('journey')
        ->and($component->get('sortDirection'))->toBe('asc');

    // Second click after asc switches to desc, third click resets to default
    $component->call('sortTable', 'journey');
    expect($component->get('sortDirection'))->toBe('desc');
    $component->call('sortTable', 'journey');
    expect($component->get('sortBy'))->toBeNull();

    // Filter by multiple journeys
    $component->call('setJourneys', ['ACSV -> ALSC', 'ASG -> SEVT']);
    expect($component->instance()->getAllRows()->pluck('code')->all())->toEqualCanonicalizing(['ORD-SORT-1', 'ORD-SORT-2']);

    // Filter by single journey
    $component->call('setJourneys', ['ACSV -> ALSC']);
    expect($component->instance()->getAllRows()->pluck('code')->all())->toBe(['ORD-SORT-2']);

    $component->call('resetFilters');
    expect($component->get('journeys'))->toBe([])
        ->and($component->get('sortBy'))->toBeNull();
});

test('trip excel summary splits km by driver when trip has driver swap', function () {
    $driverFirst = User::factory()->create(['name' => 'Đỗ Văn Thìn']);
    $driverSecond = User::factory()->create(['name' => 'Trần Văn Thích']);

    $trip = Trip::create([
        'trip_code' => 'TRIP-SWAP-393',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $driverSecond->id,
        'status' => TripStatus::Completed,
        'started_at' => Carbon::parse('2026-04-10 08:30:00'),
        'completed_at' => Carbon::parse('2026-04-10 11:30:00'),
        'start_km' => 153892,
        'end_km' => 153901,
        'total_km' => 9,
        'total_km_loaded' => 6,
        'total_km_empty' => 3,
    ]);

    $order = Order::create([
        'order_code' => 'ASG-393-SWAP',
        'customer_id' => $this->customer->id,
        'created_by' => $this->driver->id,
        'area_id' => $this->area->id,
        'trip_id' => $trip->id,
        'type' => OrderType::Hhhk,
        'status' => OrderStatus::Completed,
        'pickup_location_id' => $this->pickupLocation->id,
        'planned_loading_at' => Carbon::parse('2026-04-10 08:30:00'),
        'total_packages' => 1,
        'total_weight' => 100,
        'loaded_km' => 6,
    ]);

    TripCheckpoint::create([
        'trip_id' => $trip->id,
        'order_id' => $order->id,
        'driver_id' => $driverFirst->id,
        'checkpoint_type' => CheckpointType::Started,
        'km_reading' => 153892,
        'occurred_at' => Carbon::parse('2026-04-10 08:30:00'),
    ]);
    TripCheckpoint::create([
        'trip_id' => $trip->id,
        'order_id' => $order->id,
        'driver_id' => $driverFirst->id,
        'checkpoint_type' => CheckpointType::ArrivedPickup,
        'km_reading' => 153895,
        'occurred_at' => Carbon::parse('2026-04-10 08:45:00'),
    ]);
    $swapCp = TripCheckpoint::create([
        'trip_id' => $trip->id,
        'order_id' => $order->id,
        'driver_id' => $driverFirst->id,
        'checkpoint_type' => CheckpointType::DriverSwap,
        'km_reading' => 153895,
        'occurred_at' => Carbon::parse('2026-04-10 09:30:00'),
    ]);
    DriverSwap::create([
        'trip_id' => $trip->id,
        'from_driver_id' => $driverFirst->id,
        'to_driver_id' => $driverSecond->id,
        'checkpoint_id' => $swapCp->id,
        'handover_km' => 153895,
        'reason' => DriverSwapReason::CargoNotUnloaded,
        'created_by' => $driverFirst->id,
    ]);
    TripCheckpoint::create([
        'trip_id' => $trip->id,
        'order_id' => $order->id,
        'driver_id' => $driverSecond->id,
        'checkpoint_type' => CheckpointType::Completed,
        'km_reading' => 153901,
        'occurred_at' => Carbon::parse('2026-04-10 11:30:00'),
    ]);

    $spreadsheet = app(TripExcelExportService::class)->buildSpreadsheet(collect([$trip->fresh(['vehicle', 'driver', 'orders.customer', 'orders.area', 'orders.pickupLocation', 'orders.deliveryPoints.location', 'orders.tripCheckpoints', 'checkpoints', 'startLocation.area', 'endLocation.area'])]));
    $sheet = $spreadsheet->getSheet(0);

    expect($sheet->getCell('AC4')->getValue())->toBe('Họ tên lái xe đảo lái')
        ->and($sheet->getCell('AD4')->getValue())->toBe('Km có hàng')
        ->and($sheet->getCell('AE4')->getValue())->toBe('Km không hàng')
        ->and($sheet->getCell('U5')->getValue())->toBe('Đỗ Văn Thìn')
        ->and($sheet->getCell('V5')->getValue())->toBe('')
        ->and((float) $sheet->getCell('W5')->getValue())->toBe(3.0)
        ->and($sheet->getCell('AC5')->getValue())->toBe('Trần Văn Thích')
        ->and((float) $sheet->getCell('AD5')->getValue())->toBe(6.0)
        ->and($sheet->getCell('AE5')->getValue())->toBe('');
});

test('trip excel summary displays rented vehicle owner name when available', function () {
    $rentWithOwner = Vehicle::create([
        'plate_number' => '29E-99901',
        'type' => VehicleOwnerType::Rent,
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'Tam Bảo',
        'is_active' => true,
    ]);

    $rentWithoutOwner = Vehicle::create([
        'plate_number' => '29E-99902',
        'type' => VehicleOwnerType::Rent,
        'vehicle_type' => VehicleType::Normal,
        'owner' => '',
        'is_active' => true,
    ]);

    $trip1 = Trip::create([
        'trip_code' => 'TRIP-RENT-1',
        'vehicle_id' => $rentWithOwner->id,
        'status' => TripStatus::Completed,
        'started_at' => Carbon::parse('2026-04-11 08:30:00'),
        'completed_at' => Carbon::parse('2026-04-11 09:30:00'),
    ]);
    $trip2 = Trip::create([
        'trip_code' => 'TRIP-RENT-2',
        'vehicle_id' => $rentWithoutOwner->id,
        'status' => TripStatus::Completed,
        'started_at' => Carbon::parse('2026-04-11 10:30:00'),
        'completed_at' => Carbon::parse('2026-04-11 11:30:00'),
    ]);

    Order::create([
        'order_code' => 'ORD-RENT-1',
        'customer_id' => $this->customer->id,
        'created_by' => $this->driver->id,
        'area_id' => $this->area->id,
        'trip_id' => $trip1->id,
        'type' => OrderType::Hhhk,
        'status' => OrderStatus::Completed,
        'pickup_location_id' => $this->pickupLocation->id,
    ]);
    Order::create([
        'order_code' => 'ORD-RENT-2',
        'customer_id' => $this->customer->id,
        'created_by' => $this->driver->id,
        'area_id' => $this->area->id,
        'trip_id' => $trip2->id,
        'type' => OrderType::Hhhk,
        'status' => OrderStatus::Completed,
        'pickup_location_id' => $this->pickupLocation->id,
    ]);

    $spreadsheet = app(TripExcelExportService::class)->buildSpreadsheet(
        collect([$trip1->fresh(['vehicle', 'driver', 'orders.customer', 'orders.area', 'orders.pickupLocation', 'orders.deliveryPoints.location', 'orders.tripCheckpoints', 'checkpoints', 'startLocation.area', 'endLocation.area']), $trip2->fresh(['vehicle', 'driver', 'orders.customer', 'orders.area', 'orders.pickupLocation', 'orders.deliveryPoints.location', 'orders.tripCheckpoints', 'checkpoints', 'startLocation.area', 'endLocation.area'])])
    );
    $sheet = $spreadsheet->getSheet(0);

    expect($sheet->getCell('Q5')->getValue())->toBe('Tam Bảo')
        ->and($sheet->getCell('Q6')->getValue())->toBe('Xe thuê');
});

test('trip statistics report filters by loading date (time_a) instead of unloading date and returns only the selected calendar date', function () {
    Carbon::setTestNow('2026-11-19 07:30:00');

    // Chuyến 1: Đóng hàng tối 18/11 (23:18 18/11) nhưng hạ hàng rạng sáng 19/11 (01:25 19/11)
    $tripLoaded18Unloaded19 = Trip::create([
        'trip_code' => 'TRIP-LOAD-18',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'status' => TripStatus::Completed,
        'started_at' => Carbon::parse('2026-11-19 00:45:00'),
        'completed_at' => Carbon::parse('2026-11-19 01:25:00'),
    ]);

    $order18 = Order::create([
        'order_code' => 'ASG-651',
        'type' => OrderType::Hhhk,
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'created_by' => $this->driver->id,
        'pickup_location_id' => $this->pickupLocation->id,
        'planned_loading_at' => Carbon::parse('2026-11-18 23:00:00'),
        'trip_id' => $tripLoaded18Unloaded19->id,
        'status' => OrderStatus::Completed,
        'cargo_type' => CargoType::Gcr,
    ]);

    TripCheckpoint::create([
        'trip_id' => $tripLoaded18Unloaded19->id,
        'order_id' => $order18->id,
        'checkpoint_type' => CheckpointType::ArrivedPickup,
        'occurred_at' => Carbon::parse('2026-11-18 23:18:00'),
    ]);
    TripCheckpoint::create([
        'trip_id' => $tripLoaded18Unloaded19->id,
        'order_id' => $order18->id,
        'checkpoint_type' => CheckpointType::LeftPickup,
        'occurred_at' => Carbon::parse('2026-11-19 00:45:00'),
    ]);
    TripCheckpoint::create([
        'trip_id' => $tripLoaded18Unloaded19->id,
        'order_id' => $order18->id,
        'checkpoint_type' => CheckpointType::ArrivedDelivery,
        'occurred_at' => Carbon::parse('2026-11-19 00:56:00'),
    ]);
    TripCheckpoint::create([
        'trip_id' => $tripLoaded18Unloaded19->id,
        'order_id' => $order18->id,
        'checkpoint_type' => CheckpointType::Completed,
        'occurred_at' => Carbon::parse('2026-11-19 01:25:00'),
    ]);

    // Chuyến 2: Đóng hàng sáng 19/11 (03:12 19/11) và hạ hàng 06:12 19/11
    $tripLoaded19 = Trip::create([
        'trip_code' => 'TRIP-LOAD-19',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'status' => TripStatus::Completed,
        'started_at' => Carbon::parse('2026-11-19 04:08:00'),
        'completed_at' => Carbon::parse('2026-11-19 06:12:00'),
    ]);

    $order19 = Order::create([
        'order_code' => 'ASG-662',
        'type' => OrderType::Hhhk,
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'created_by' => $this->driver->id,
        'pickup_location_id' => $this->pickupLocation->id,
        'planned_loading_at' => Carbon::parse('2026-11-19 03:00:00'),
        'trip_id' => $tripLoaded19->id,
        'status' => OrderStatus::Completed,
        'cargo_type' => CargoType::Gcr,
    ]);

    TripCheckpoint::create([
        'trip_id' => $tripLoaded19->id,
        'order_id' => $order19->id,
        'checkpoint_type' => CheckpointType::ArrivedPickup,
        'occurred_at' => Carbon::parse('2026-11-19 03:12:00'),
    ]);
    TripCheckpoint::create([
        'trip_id' => $tripLoaded19->id,
        'order_id' => $order19->id,
        'checkpoint_type' => CheckpointType::LeftPickup,
        'occurred_at' => Carbon::parse('2026-11-19 04:08:00'),
    ]);
    TripCheckpoint::create([
        'trip_id' => $tripLoaded19->id,
        'order_id' => $order19->id,
        'checkpoint_type' => CheckpointType::ArrivedDelivery,
        'occurred_at' => Carbon::parse('2026-11-19 05:16:00'),
    ]);
    TripCheckpoint::create([
        'trip_id' => $tripLoaded19->id,
        'order_id' => $order19->id,
        'checkpoint_type' => CheckpointType::Completed,
        'occurred_at' => Carbon::parse('2026-11-19 06:12:00'),
    ]);

    // 1. Chọn ngày 19/11/2026 (hoặc bấm Hôm nay lúc 07:30 sáng 19/11): chỉ ra đúng đơn đóng hàng ngày 19/11 (ASG-662), không dính đơn đóng hàng ngày 18/11 (ASG-651)
    $component = Livewire::test(TripStatisticsReport::class)
        ->call('setDatePreset', 'today')
        ->assertSet('startDate', '2026-11-19')
        ->assertSet('endDate', '2026-11-19');

    $rows19 = $component->instance()->getAllRows();
    expect($rows19->pluck('code')->all())->toBe(['ASG-662']);
    expect(Carbon::parse($rows19->first()['date'])->format('d/m/Y'))->toBe('19/11/2026');

    // 2. Chọn ngày 18/11/2026 (hoặc Hôm qua): chỉ ra đúng đơn đóng hàng ngày 18/11 (ASG-651) và cột Ngày hiển thị 18/11/2026
    $component->call('setDatePreset', 'yesterday')
        ->assertSet('startDate', '2026-11-18')
        ->assertSet('endDate', '2026-11-18');

    $rows18 = $component->instance()->getAllRows();
    expect($rows18->pluck('code')->all())->toBe(['ASG-651']);
    expect(Carbon::parse($rows18->first()['date'])->format('d/m/Y'))->toBe('18/11/2026');

    Carbon::setTestNow();
});

test('trip excel export includes trip status column when includeStatusColumn is enabled', function () {
    $completedTrip = Trip::create([
        'trip_code' => 'TRIP-ST-DONE',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'status' => TripStatus::Completed,
        'started_at' => Carbon::parse('2026-11-19 08:00:00'),
        'completed_at' => Carbon::parse('2026-11-19 09:00:00'),
    ]);
    $cancelledTrip = Trip::create([
        'trip_code' => 'TRIP-ST-CANCEL',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'status' => TripStatus::Cancelled,
        'started_at' => Carbon::parse('2026-11-19 10:00:00'),
    ]);
    $inProgressTrip = Trip::create([
        'trip_code' => 'TRIP-ST-RUN',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'status' => TripStatus::Delivering,
        'started_at' => Carbon::parse('2026-11-19 11:00:00'),
    ]);

    $trips = collect([$completedTrip, $cancelledTrip, $inProgressTrip]);

    $withStatus = app(TripExcelExportService::class)->buildSpreadsheet($trips, includeStatusColumn: true)->getSheet(0);
    expect($withStatus->getCell('AF4')->getValue())->toBe('Trạng thái chuyến')
        ->and($withStatus->getCell('AF5')->getValue())->toBe('Hoàn thành')
        ->and($withStatus->getCell('AF6')->getValue())->toBe('Đã huỷ')
        ->and($withStatus->getCell('AF7')->getValue())->toBe('Chưa hoàn thành (Đi giao hàng)');

    // Mặc định (Bảng kê) không có cột Trạng thái chuyến
    $withoutStatus = app(TripExcelExportService::class)->buildSpreadsheet($trips)->getSheet(0);
    expect($withoutStatus->getCell('AF4')->getValue())->toBeNull();
});
