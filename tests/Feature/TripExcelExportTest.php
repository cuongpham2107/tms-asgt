<?php

use App\Enums\CargoType;
use App\Enums\CheckpointType;
use App\Enums\FreightPricingMethod;
use App\Enums\OrderDeliveryPointStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\TripStatus;
use App\Enums\VehicleOwnerType;
use App\Enums\VehicleType;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Trips\Pages\ListTrips;
use App\Models\Area;
use App\Models\BillingZone;
use App\Models\Customer;
use App\Models\FreightRate;
use App\Models\Location;
use App\Models\Order;
use App\Models\OrderCharge;
use App\Models\OrderDeliveryPoint;
use App\Models\Trip;
use App\Models\TripCheckpoint;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Export\TripExcelExportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
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

    $this->deliveryLocation2 = Location::create([
        'code' => 'ALSE',
        'name' => 'Kho ALSE',
        'address' => 'KCN VSIP, Bắc Ninh',
        'lat' => 21.1200,
        'lng' => 105.9500,
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

test('it builds spreadsheet with 2 sheets and correct sheet titles', function () {
    $service = new TripExcelExportService;
    $spreadsheet = $service->buildSpreadsheet(collect());

    expect($spreadsheet->getSheetCount())->toBe(2);
    expect($spreadsheet->getSheet(0)->getTitle())->toBe('Bảng tổng hợp chuyến phục vụ');
    expect($spreadsheet->getSheet(1)->getTitle())->toBe('Bảng dữ liệu hàng ngoài');
});

test('it exports trip summary sheet with hhhk order details and journey', function () {
    $trip = Trip::create([
        'trip_code' => 'TRIP-HHHK-01',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'status' => TripStatus::Completed,
        'started_at' => Carbon::parse('2026-09-10 08:00:00'),
        'completed_at' => Carbon::parse('2026-09-10 10:30:00'),
        'total_km_loaded' => 25.5,
        'total_km_empty' => 5.0,
    ]);

    $order = Order::create([
        'order_code' => 'ORD-HHHK-01',
        'type' => OrderType::Hhhk,
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'trip_id' => $trip->id,
        'pickup_location_id' => $this->pickupLocation->id,
        'cargo_type' => CargoType::Gcr,
        'total_packages' => 12,
        'total_weight' => 500,
        'chargeable_weight' => 0.5,
        'status' => OrderStatus::Completed,
        'notes' => 'Ghi chú test',
        'created_by' => $this->driver->id,
    ]);

    OrderDeliveryPoint::create([
        'order_id' => $order->id,
        'location_id' => $this->deliveryLocation->id,
        'sequence' => 1,
        'address' => 'ALSC Nội Bài',
        'status' => OrderDeliveryPointStatus::Delivered,
    ]);

    // Checkpoints
    TripCheckpoint::create([
        'trip_id' => $trip->id,
        'order_id' => $order->id,
        'checkpoint_type' => CheckpointType::ArrivedPickup,
        'occurred_at' => Carbon::parse('2026-09-10 08:15:00'),
    ]);
    TripCheckpoint::create([
        'trip_id' => $trip->id,
        'order_id' => $order->id,
        'checkpoint_type' => CheckpointType::LeftPickup,
        'occurred_at' => Carbon::parse('2026-09-10 08:45:00'),
    ]);
    TripCheckpoint::create([
        'trip_id' => $trip->id,
        'order_id' => $order->id,
        'checkpoint_type' => CheckpointType::ArrivedDelivery,
        'occurred_at' => Carbon::parse('2026-09-10 09:15:00'),
    ]);
    TripCheckpoint::create([
        'trip_id' => $trip->id,
        'order_id' => $order->id,
        'checkpoint_type' => CheckpointType::Completed,
        'occurred_at' => Carbon::parse('2026-09-10 09:45:00'),
    ]);

    $service = new TripExcelExportService;
    $spreadsheet = $service->buildSpreadsheet(Trip::where('id', $trip->id));
    $sheet1 = $spreadsheet->getSheet(0);

    // Dòng 5 là dòng dữ liệu đầu tiên
    expect($sheet1->getCell('A5')->getValue())->toBe('HHHK');
    expect($sheet1->getCell('B5')->getValue())->toBe('ORD-HHHK-01');
    expect($sheet1->getCell('D5')->getValue())->toBe('20C04357');
    expect($sheet1->getCell('E5')->getValue())->toBe('ASG -> ALSC'); // Hành trình ghép
    expect($sheet1->getCell('J5')->getValue())->toBe(30); // 8:45 - 8:15 = 30 phút
    expect($sheet1->getCell('K5')->getValue())->toBe(30); // 9:15 - 8:45 = 30 phút
    expect($sheet1->getCell('L5')->getValue())->toBe(30); // 9:45 - 9:15 = 30 phút
    expect($sheet1->getCell('N5')->getValue())->toBe(90); // Tổng = 90 phút
    expect($sheet1->getCell('O5')->getValue())->toBe('ASGL');
    expect($sheet1->getCell('P5')->getValue())->toBe('ALSC');
    expect($sheet1->getCell('Q5')->getValue())->toBe('Xe công ty');
    expect($sheet1->getCell('U5')->getValue())->toBe('Đặng Ngọc Lợi');
    expect((float) $sheet1->getCell('V5')->getValue())->toBe(25.5);
    expect((float) $sheet1->getCell('W5')->getValue())->toBe(5.0);
    expect((int) $sheet1->getCell('X5')->getValue())->toBe(12);
});

test('it exports external order with single point to sheet 2 section 1', function () {
    $trip = Trip::create([
        'trip_code' => 'TRIP-HN-01',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'status' => TripStatus::Completed,
        'started_at' => Carbon::parse('2026-09-11 07:00:00'),
        'completed_at' => Carbon::parse('2026-09-11 09:00:00'),
    ]);

    $order = Order::create([
        'order_code' => 'ORD-HN-01',
        'type' => OrderType::External,
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'trip_id' => $trip->id,
        'pickup_location_id' => $this->pickupLocation->id,
        'cargo_type' => CargoType::Dangerous,
        'total_packages' => 8,
        'chargeable_weight' => 5.0,
        'status' => OrderStatus::Completed,
        'planned_loading_at' => Carbon::parse('2026-09-11 07:00:00'),
        'created_by' => $this->driver->id,
    ]);

    OrderDeliveryPoint::create([
        'order_id' => $order->id,
        'location_id' => $this->deliveryLocation->id,
        'sequence' => 1,
        'address' => 'ALSC Nội Bài',
        'status' => OrderDeliveryPointStatus::Delivered,
    ]);

    $service = new TripExcelExportService;
    $spreadsheet = $service->buildSpreadsheet(Trip::where('id', $trip->id));
    $sheet2 = $spreadsheet->getSheet(1);

    // Section 1: Dòng 1 là banner, Dòng 2 là headers, Dòng 3 là data
    expect($sheet2->getCell('A1')->getValue())->toBe('DỮ LIỆU ĐIỂM ĐÓNG VÀ TRẢ: 1 ĐIỂM');
    expect($sheet2->getCell('A3')->getValue())->toBe('ASGL');
    expect($sheet2->getCell('B3')->getValue())->toBe('ORD-HN-01');
    expect($sheet2->getCell('D3')->getValue())->toBe('20C04357');
    expect((float) $sheet2->getCell('I3')->getValue())->toBe(5.0);
});

test('it exports external order with multiple points to sheet 2 section 2', function () {
    $trip = Trip::create([
        'trip_code' => 'TRIP-HN-MULTI',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'status' => TripStatus::Completed,
        'started_at' => Carbon::parse('2026-09-12 06:00:00'),
    ]);

    $order = Order::create([
        'order_code' => 'ORD-HN-MULTI',
        'type' => OrderType::External,
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'trip_id' => $trip->id,
        'pickup_location_id' => $this->pickupLocation->id,
        'status' => OrderStatus::Completed,
        'planned_loading_at' => Carbon::parse('2026-09-12 06:00:00'),
        'created_by' => $this->driver->id,
    ]);

    // 2 delivery points
    OrderDeliveryPoint::create([
        'order_id' => $order->id,
        'location_id' => $this->deliveryLocation->id,
        'sequence' => 1,
        'address' => 'ALSC Nội Bài',
        'status' => OrderDeliveryPointStatus::Delivered,
    ]);

    OrderDeliveryPoint::create([
        'order_id' => $order->id,
        'location_id' => $this->deliveryLocation2->id,
        'sequence' => 2,
        'address' => 'KCN VSIP, Bắc Ninh',
        'status' => OrderDeliveryPointStatus::Delivered,
    ]);

    $service = new TripExcelExportService;
    $spreadsheet = $service->buildSpreadsheet(Trip::where('id', $trip->id));
    $sheet2 = $spreadsheet->getSheet(1);

    // Tìm dòng banner section 2: "DỮ LIỆU ĐIỂM ĐÓNG VÀ TRẢ:  NHIỀU ĐIỂM"
    $foundMultiSection = false;
    for ($r = 1; $r <= 20; $r++) {
        if ($sheet2->getCell("A{$r}")->getValue() === 'DỮ LIỆU ĐIỂM ĐÓNG VÀ TRẢ:  NHIỀU ĐIỂM') {
            $foundMultiSection = true;
            $dataRow1 = $r + 2;
            $dataRow2 = $r + 3;

            expect($sheet2->getCell("B{$dataRow1}")->getValue())->toBe('ORD-HN-MULTI');
            expect($sheet2->getCell("D{$dataRow1}")->getValue())->toBe('20C04357'); // Điểm 1 có BSX
            expect($sheet2->getCell("D{$dataRow2}")->getValue())->toBe(''); // Điểm 2 để trống BSX theo mẫu
            break;
        }
    }

    expect($foundMultiSection)->toBeTrue();
});

test('export returns streamed response for browser download', function () {
    $service = new TripExcelExportService;
    $response = $service->export(Trip::query());

    expect($response)->toBeInstanceOf(StreamedResponse::class);
    expect($response->headers->get('content-type'))->toContain('spreadsheetml.sheet');
});

test('exportFromOrders handles orders query directly', function () {
    $order = Order::create([
        'order_code' => 'ORD-DIRECT-TEST',
        'type' => OrderType::Hhhk,
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'pickup_location_id' => $this->pickupLocation->id,
        'status' => OrderStatus::Draft,
        'planned_loading_at' => now(),
        'created_by' => $this->driver->id,
    ]);

    $service = new TripExcelExportService;
    $response = $service->export(Order::where('id', $order->id));

    expect($response)->toBeInstanceOf(StreamedResponse::class);
});

test('livewire list trips can call exportExcel', function () {
    Livewire\Livewire::test(ListTrips::class)->loadTable()
        ->call('exportExcel')
        ->assertFileDownloaded();
});

test('livewire list trips can call exportExcel action with modal filter data', function () {
    Livewire\Livewire::test(ListTrips::class)->loadTable()
        ->callAction('exportExcel', [
            'status' => 'all',
            'order_type' => 'all',
            'vehicle_owner' => 'all',
            'place' => 'all',
        ])
        ->assertFileDownloaded();
});

test('livewire list orders can call exportExcel', function () {
    Livewire\Livewire::test(ListOrders::class)->loadTable()
        ->call('exportExcel')
        ->assertFileDownloaded();
});

test('livewire list orders can call exportExcel action with modal filter data', function () {
    Livewire\Livewire::test(ListOrders::class)->loadTable()
        ->callAction('exportExcel', [
            'status' => 'all',
            'order_type' => 'all',
            'place' => 'all',
            'show_mine_only' => false,
        ])
        ->assertFileDownloaded();
});

test('list orders getExportOrdersQuery filters correctly by status and type', function () {
    Order::create([
        'order_code' => 'ORD-COMPLETED',
        'type' => OrderType::Hhhk,
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'pickup_location_id' => $this->pickupLocation->id,
        'status' => OrderStatus::Completed,
        'planned_loading_at' => now(),
        'created_by' => $this->driver->id,
    ]);

    Order::create([
        'order_code' => 'ORD-EXTERNAL',
        'type' => OrderType::External,
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'pickup_location_id' => $this->pickupLocation->id,
        'status' => OrderStatus::Draft,
        'planned_loading_at' => now(),
        'created_by' => $this->driver->id,
    ]);

    $listOrders = new ListOrders;

    $queryCompleted = $listOrders->getExportOrdersQuery(['status' => 'completed', 'order_type' => 'all']);
    expect($queryCompleted->pluck('order_code')->all())->toContain('ORD-COMPLETED')
        ->not->toContain('ORD-EXTERNAL');

    $queryExternal = $listOrders->getExportOrdersQuery(['status' => 'all', 'order_type' => 'external']);
    expect($queryExternal->pluck('order_code')->all())->toContain('ORD-EXTERNAL')
        ->not->toContain('ORD-COMPLETED');
});

test('list trips getExportTripsQuery filters correctly by status and vehicle owner', function () {
    $trip1 = Trip::create([
        'trip_code' => 'TRIP-COMPLETED',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'status' => TripStatus::Completed,
        'started_at' => now(),
    ]);

    $rentVehicle = Vehicle::create([
        'plate_number' => '29C99999',
        'owner' => 'THUE_NGOAI',
        'type' => VehicleOwnerType::Rent,
        'vehicle_type' => VehicleType::Normal,
        'is_active' => true,
    ]);

    $trip2 = Trip::create([
        'trip_code' => 'TRIP-RENT',
        'vehicle_id' => $rentVehicle->id,
        'driver_id' => $this->driver->id,
        'status' => TripStatus::Pending,
        'started_at' => now(),
    ]);

    $listTrips = new ListTrips;

    $queryCompany = $listTrips->getExportTripsQuery(['status' => 'all', 'vehicle_owner' => 'company']);
    expect($queryCompany->pluck('trip_code')->all())->toContain('TRIP-COMPLETED')
        ->not->toContain('TRIP-RENT');

    $queryPending = $listTrips->getExportTripsQuery(['status' => 'pending', 'vehicle_owner' => 'all']);
    expect($queryPending->pluck('trip_code')->all())->toContain('TRIP-RENT')
        ->not->toContain('TRIP-COMPLETED');
});

test('it exports weight range order with multiple delivery notes as separate rows with note prices', function () {
    $vfc = Customer::create(['code' => 'ASGS(VFC)', 'name' => 'VFC', 'is_active' => true]);
    $ypZone = BillingZone::firstOrCreate(['code' => 'KCN YÊN PHONG'], ['name' => 'KCN Yên Phong, Bắc Ninh']);
    $hpZone = BillingZone::firstOrCreate(['code' => 'HẢI PHÒNG'], ['name' => 'Các KCN Hải Phòng']);

    $locYP = Location::create(['code' => 'YP', 'billing_zone_id' => $ypZone->id, 'name' => 'KCN Yên Phong', 'loc_type' => 'pickup', 'is_active' => true]);
    $locHP = Location::create(['code' => 'HP', 'billing_zone_id' => $hpZone->id, 'name' => 'KCN Hải Phòng', 'loc_type' => 'delivery', 'is_active' => true]);

    $rate = FreightRate::create([
        'customer_id' => $vfc->id,
        'origin_zone_id' => $ypZone->id,
        'destination_zone_id' => $hpZone->id,
        'cargo_type' => CargoType::Gcr,
        'rate_type' => '',
        'pricing_method' => FreightPricingMethod::ByWeightRange,
        'range_prices' => [
            ['from' => 0, 'to' => 2, 'price' => 1_271_768, 'label' => 'Dưới 2T'],
            ['from' => 2, 'to' => 3, 'price' => 1_447_740, 'label' => 'Từ 2T đến 3T'],
            ['from' => 3, 'to' => 4, 'price' => 2_062_898, 'label' => 'Từ 3T đến 4T'],
            ['from' => 4, 'to' => 7, 'price' => 2_682_969, 'label' => 'Từ 4T đến 7T'],
        ],
    ]);

    $trip = Trip::create([
        'trip_code' => 'TRIP-VFC-01',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'status' => TripStatus::Completed,
        'started_at' => Carbon::parse('2026-10-21 08:00:00'),
        'completed_at' => Carbon::parse('2026-10-21 11:00:00'),
    ]);

    $order = Order::create([
        'order_code' => 'ORD-VFC-SPLIT',
        'type' => OrderType::External,
        'area_id' => $this->area->id,
        'customer_id' => $vfc->id,
        'trip_id' => $trip->id,
        'pickup_location_id' => $locYP->id,
        'cargo_type' => CargoType::Gcr,
        'total_weight' => 10_000,
        'chargeable_weight' => 10,
        'status' => OrderStatus::Completed,
        'planned_loading_at' => Carbon::parse('2026-10-21 08:00:00'),
        'created_by' => $this->driver->id,
    ]);

    OrderDeliveryPoint::create(['order_id' => $order->id, 'location_id' => $locHP->id, 'sequence' => 1, 'status' => OrderDeliveryPointStatus::Delivered]);

    $charge = OrderCharge::create([
        'order_id' => $order->id,
        'customer_id' => $vfc->id,
        'freight_rate_id' => $rate->id,
        'price_code' => 'ASGS_YP_HP',
        'cargo_type' => CargoType::Gcr,
        'chargeable_tonnage' => 10,
        'fuel_adjustment_percent' => 0,
        'vat_percent' => 0,
        'base_price' => 4_745_867,
        'adjusted_price' => 4_745_867,
        'subtotal' => 4_745_867,
        'total' => 4_745_867,
        'delivery_notes' => [
            ['number' => 'DN-01', 'chargeable_weight' => 7000],
            ['number' => 'DN-02', 'chargeable_weight' => '3000'],
        ],
    ]);

    $service = new TripExcelExportService;
    $spreadsheet = $service->buildSpreadsheet(Trip::where('id', $trip->id));
    $sheet2 = $spreadsheet->getSheet(1);

    // Row 3: Phiếu 1 (7T -> 2.682.969)
    expect($sheet2->getCell('A3')->getValue())->toBe('ASGS(VFC)');
    expect($sheet2->getCell('B3')->getValue())->toBe('ORD-VFC-SPLIT');
    expect((float) $sheet2->getCell('I3')->getValue())->toBe(7.0);
    expect((float) $sheet2->getCell('Y3')->getValue())->toBe(2_682_969.0);

    // Row 4: Phiếu 2 (3T -> 2.062.898), thông tin cột A-T chép y hệt
    expect($sheet2->getCell('A4')->getValue())->toBe('ASGS(VFC)');
    expect($sheet2->getCell('B4')->getValue())->toBe('ORD-VFC-SPLIT');
    expect((float) $sheet2->getCell('I4')->getValue())->toBe(3.0);
    expect((float) $sheet2->getCell('Y4')->getValue())->toBe(2_062_898.0);

    // Tổng tiền của 2 dòng = tổng đơn giá gốc
    expect((float) $sheet2->getCell('Y3')->getValue() + (float) $sheet2->getCell('Y4')->getValue())->toBe(4_745_867.0);
});
