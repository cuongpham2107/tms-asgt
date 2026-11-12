<?php

use App\Enums\CargoType;
use App\Enums\FreightRateType;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\TripStatus;
use App\Enums\VehicleOwnerType;
use App\Enums\VehicleType;
use App\Filament\Pages\TripStatisticsReport;
use App\Models\Area;
use App\Models\BillingZone;
use App\Models\Customer;
use App\Models\FeeType;
use App\Models\FreightRate;
use App\Models\Location;
use App\Models\Order;
use App\Models\OrderCharge;
use App\Models\OrderDeliveryPoint;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Export\BillingStatement\BillingStatementExporter;
use App\Services\Export\BillingStatement\BillingStatementTemplates;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

uses(RefreshDatabase::class);

beforeEach(function () {
    Gate::before(fn () => true);

    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    $this->customer = Customer::create([
        'code' => 'ALSB',
        'name' => 'CÔNG TY TNHH ALS BẮC NINH',
        'address' => 'Lô CN 05, KCN Yên Phong',
        'tax_code' => '2300478023',
        'fuel_adjustment_percent' => 16.14,
        'is_active' => true,
    ]);
    $area = Area::create(['code' => 'NBA', 'name' => 'Nội Bài', 'is_active' => true, 'sort_order' => 1]);
    $pickupZone = BillingZone::create(['code' => 'SEVT', 'name' => 'Sevt']);
    $dropZone = BillingZone::create(['code' => 'SEV', 'name' => 'Sev']);
    $pickup = Location::create(['code' => 'SEVT', 'name' => 'SEVT', 'address' => 'SEVT, KCN Yên Bình, Thái Nguyên', 'billing_zone_id' => $pickupZone->id, 'loc_type' => 'pickup', 'is_active' => true]);
    $drop = Location::create(['code' => 'SEV', 'name' => 'SEV', 'address' => 'SEV, KCN Yên Phong, Bắc Ninh', 'billing_zone_id' => $dropZone->id, 'loc_type' => 'delivery', 'is_active' => true]);
    $vehicle = Vehicle::create(['plate_number' => '20C09032', 'owner' => 'ASGL', 'type' => VehicleOwnerType::Company, 'vehicle_type' => VehicleType::Normal, 'is_active' => true]);

    FreightRate::create([
        'customer_id' => $this->customer->id,
        'origin_zone_id' => $pickupZone->id,
        'destination_zone_id' => $dropZone->id,
        'cargo_type' => CargoType::Gcr,
        'prices' => ['1.25' => 1_000_000],
    ]);

    $trip = Trip::create([
        'trip_code' => 'TRIP-ALSB-01',
        'vehicle_id' => $vehicle->id,
        'driver_id' => $this->user->id,
        'status' => TripStatus::Completed,
        'started_at' => Carbon::parse('2026-10-20 14:00:00'),
        'completed_at' => Carbon::parse('2026-10-20 17:00:00'),
    ]);

    $this->order = Order::create([
        'order_code' => 'ASG-900',
        'type' => OrderType::External,
        'area_id' => $area->id,
        'customer_id' => $this->customer->id,
        'trip_id' => $trip->id,
        'pickup_location_id' => $pickup->id,
        'cargo_type' => CargoType::Gcr,
        'chargeable_weight' => 1.25,
        'planned_loading_at' => Carbon::parse('2026-10-20 14:00:00'),
        'status' => OrderStatus::Sent,
        'created_by' => $this->user->id,
    ]);
    OrderDeliveryPoint::create(['order_id' => $this->order->id, 'location_id' => $drop->id, 'sequence' => 1]);
    $this->order->update(['status' => OrderStatus::Completed]);

    $this->charge = OrderCharge::where('order_id', $this->order->id)->firstOrFail();
    $this->charge->update(['vat_percent' => 8]);
    $this->charge->fees()->createMany([
        ['fee_type_id' => FeeType::where('code', 'dgr_permit')->value('id'), 'amount' => 300_000],
        ['fee_type_id' => FeeType::where('code', 'city_entry')->value('id'), 'amount' => 50_000, 'note' => 'phố cổ'],
    ]);
    $this->charge->recalculateTotals()->save();

    $this->page = fn () => Livewire::test(TripStatisticsReport::class, [
        'startDate' => '2026-10-01',
        'endDate' => '2026-10-31',
        'activeTab' => 'external',
        'customerId' => $this->customer->id,
    ]);
});

/**
 * Tìm ô có giá trị đúng bằng $text, trả về toạ độ.
 */
function findCell(Worksheet $sheet, string $text): ?string
{
    foreach ($sheet->getRowIterator() as $row) {
        foreach ($row->getCellIterator() as $cell) {
            if ($cell->getValue() === $text) {
                return $cell->getCoordinate();
            }
        }
    }

    return null;
}

test('billing statement follows the customer template and writes the stored charge numbers', function () {
    $rows = ($this->page)()->instance()->getAllRows();

    $result = app(BillingStatementExporter::class)->build($rows, $this->customer, 'ALSB', [
        'title' => 'BẢNG KÊ ĐỐI CHIẾU VẬN CHUYỂN HÀNG ALSB THÁNG 10/2026',
        'sign_date' => '2026-10-26',
    ]);
    $sheet = $result['spreadsheet']->getActiveSheet();

    // 1.000.000 × 1,1614 → 1.162.000; + VCHNH 300k + phí vào phố 50k (không có cột riêng → "Phí khác").
    expect($result['order_ids'])->toBe([$this->order->id])
        ->and($result['unmapped_fees'])->toBe([])
        ->and($sheet->getCell('A5')->getValue())->toBe('BẢNG KÊ ĐỐI CHIẾU VẬN CHUYỂN HÀNG ALSB THÁNG 10/2026')
        ->and($sheet->getCell('B7')->getValue())->toBe('Bên thuê vận chuyển: CÔNG TY TNHH ALS BẮC NINH')
        ->and($sheet->getCell('B9')->getValue())->toBe('Mã số thuế: 2300478023')
        ->and(findCell($sheet, 'VAT 8% (VND)'))->not->toBeNull()
        ->and($sheet->getCell('D13')->getValue())->toBe('SEVT, KCN Yên Bình, Thái Nguyên')
        ->and($sheet->getCell('E13')->getValue())->toBe('SEV, KCN Yên Phong, Bắc Ninh')
        ->and($sheet->getCell('R13')->getValue())->toBe(1_000_000.0)
        ->and($sheet->getCell('S13')->getValue())->toBe(1_162_000.0)
        ->and($sheet->getCell('U13')->getValue())->toBe(300_000.0)
        ->and($sheet->getCell('V13')->getValue())->toBe(50_000.0)
        ->and($sheet->getCell('W13')->getValue())->toBe(1_512_000.0)
        ->and($sheet->getCell('Y13')->getValue())->toBe(1_632_960.0)
        ->and($sheet->getCell('Z13')->getValue())->toContain('phố cổ')
        ->and($sheet->getCell('AA13')->getValue())->toBeNull()
        ->and($sheet->getStyle('A11')->getFill()->getStartColor()->getARGB())->toBe('FF92D050')
        ->and($sheet->getRowDimension(5)->getRowHeight())->toBe(30.0)
        ->and($sheet->getFreezePane())->toBe('G13')
        ->and($sheet->getRowDimension(11)->getRowHeight())->toBe(50.0)
        ->and($sheet->getRowDimension(12)->getRowHeight())->toBe(50.0)
        ->and($sheet->getCell('Y14')->getValue())->toBe('=SUM(Y13:Y13)')
        ->and(findCell($sheet, 'Tổng số tiền bằng chữ : Một triệu, sáu trăm ba mươi hai nghìn, chín trăm sáu mươi đồng chẵn./.'))->not->toBeNull()
        ->and(findCell($sheet, 'Thái Nguyên, ngày 26 tháng 10 năm 2026'))->not->toBeNull();
});

test('fees without a column in the template go to the note and are reported', function () {
    $this->customer->update(['code' => 'SDS HCM']);
    $rows = ($this->page)()->instance()->getAllRows();

    $result = app(BillingStatementExporter::class)->build($rows, $this->customer->fresh(), 'SDS HCM');

    expect($result['unmapped_fees'])->toContain('Phí vào phố')
        ->and($result['unmapped_fees'])->toContain('Chi phí giấy phép vận chuyển hàng nguy hiểm');
});

test('export action downloads the statement and locks only priced charges', function () {
    ($this->page)()
        ->callAction('exportBillingStatement', data: [
            'template' => 'ALSB',
            'title' => 'BẢNG KÊ ALSB',
            'sign_date' => '2026-10-26',
            'lock_after_export' => true,
        ])
        ->assertHasNoActionErrors()
        ->assertFileDownloaded();

    expect($this->charge->fresh()->isLocked())->toBeTrue();
});

test('export action does not lock charges that have no price', function () {
    $this->charge->update(['base_price' => null]);

    ($this->page)()
        ->callAction('exportBillingStatement', data: [
            'template' => 'ALSB',
            'title' => 'BẢNG KÊ ALSB',
            'sign_date' => '2026-10-26',
            'lock_after_export' => true,
        ])
        ->assertFileDownloaded();

    expect($this->charge->fresh()->isLocked())->toBeFalse();
});

test('export action is disabled until one customer with a template is filtered', function () {
    Livewire::test(TripStatisticsReport::class, [
        'startDate' => '2026-10-01',
        'endDate' => '2026-10-31',
        'activeTab' => 'external',
    ])->assertActionDisabled('exportBillingStatement');
});

test('every template builds for its customer', function (string $templateKey) {
    $template = BillingStatementTemplates::all()[$templateKey];
    $this->customer->update(['code' => $template['customer_code']]);
    $this->charge->update(['rate_type' => $template['rate_types'][0] ?? '']);
    $rows = ($this->page)()->instance()->getAllRows();

    $result = app(BillingStatementExporter::class)->build($rows, $this->customer->fresh(), $templateKey);

    expect($result['order_ids'])->toBe([$this->order->id]);
})->with(fn (): array => array_keys(BillingStatementTemplates::all()));

test('clc sdv template skips orders of other rate types and prefixes the company name to addresses', function () {
    $this->customer->update(['code' => 'CLC']);
    $this->order->pickupLocation->update(['name' => 'CÔNG TY CỔ PHẦN TIẾP VẬN THẾ KỶ (CLC)', 'address' => 'KCN Yên Phong, Yên Trung, Bắc Ninh']);
    $rows = ($this->page)()->instance()->getAllRows();
    $build = fn (): array => app(BillingStatementExporter::class)->build($rows, $this->customer->fresh(), 'CLC_KY_02');

    expect($build()['order_ids'])->toBe([]);

    $this->charge->update(['rate_type' => FreightRateType::ClcSdv->value]);
    $sheet = $build()['spreadsheet']->getActiveSheet();

    expect(findCell($sheet, 'CÔNG TY CỔ PHẦN TIẾP VẬN THẾ KỶ (CLC) - KCN Yên Phong, Yên Trung, Bắc Ninh'))->not->toBeNull()
        ->and(findCell($sheet, 'SEV, KCN Yên Phong, Bắc Ninh'))->not->toBeNull();
});

test('per drop layout writes one row per delivery point with the money on the first row', function () {
    $this->customer->update(['code' => 'ASGS (SAFO)']);
    $second = Location::create(['code' => 'UPS', 'name' => 'UPS', 'address' => 'Nội Bài, Hà Nội', 'loc_type' => 'delivery', 'is_active' => true]);
    OrderDeliveryPoint::create([
        'order_id' => $this->order->id,
        'location_id' => $second->id,
        'sequence' => 2,
        'arrived_at' => Carbon::parse('2026-10-20 18:00:00'),
        'delivered_at' => Carbon::parse('2026-10-20 20:30:00'),
    ]);
    $rows = ($this->page)()->instance()->getAllRows();

    $sheet = app(BillingStatementExporter::class)->build($rows, $this->customer->fresh(), 'SAFO_XUAT')['spreadsheet']->getActiveSheet();

    // Cột: A STT … F Điểm trả, G Địa chỉ trả hàng; AE Trị giá.
    $totalColumn = 'AE';
    expect($sheet->getCell('A13')->getValue())->toBe(1)
        ->and($sheet->getCell('A14')->getValue())->toBe(1)
        ->and($sheet->getCell('G14')->getValue())->toBe('UPS')
        ->and($sheet->getCell('F14')->getValue())->toBe('Nội Bài, Hà Nội')
        ->and($sheet->getCell('C14')->getValue())->toBeNull()
        ->and($sheet->getCell("{$totalColumn}13")->getValue())->toBe(1_632_960.0)
        ->and($sheet->getCell("{$totalColumn}14")->getValue())->toBeNull()
        ->and($sheet->getCell("{$totalColumn}15")->getValue())->toBe("=SUM({$totalColumn}13:{$totalColumn}14)");
});

test('billing statement writes base price and waiting fee properly', function () {
    $this->charge->update([
        'waiting_minutes' => 150,
        'waiting_fee' => 120_000,
    ]);
    $this->charge->recalculateTotals()->save();

    $rows = ($this->page)()->instance()->getAllRows();
    $result = app(BillingStatementExporter::class)->build($rows, $this->customer, 'ALSB', [
        'title' => 'BẢNG KÊ ĐỐI CHIẾU VẬN CHUYỂN HÀNG ALSB',
        'sign_date' => '2026-10-26',
    ]);
    $sheet = $result['spreadsheet']->getActiveSheet();

    expect($sheet->getCell('R13')->getValue())->toBe(1_000_000.0)
        ->and($sheet->getCell('S13')->getValue())->toBe(1_162_000.0)
        ->and($sheet->getCell('T13')->getValue())->toBe(120_000.0)
        ->and($sheet->getCell('T13')->getStyle()->getNumberFormat()->getFormatCode())->toBe('#,##0');
});

test('vfc statement lists delivery note numbers and summed weights in one row', function () {
    $this->customer->update(['code' => 'ASGS (VFC)']);
    $this->charge->update(['delivery_notes' => [
        ['number' => '9109755528', 'actual_weight' => 1000, 'chargeable_weight' => 1000],
        ['number' => '9109792605', 'actual_weight' => 52.5, 'chargeable_weight' => 52.5],
    ]]);
    $rows = ($this->page)()->instance()->getAllRows();

    $sheet = app(BillingStatementExporter::class)->build($rows, $this->customer->fresh(), 'VFC_THUONG')['spreadsheet']->getActiveSheet();

    expect($sheet->getCell('C13')->getValue())->toBe('9109755528 9109792605')
        ->and($sheet->getCell('I13')->getValue())->toBe(1052.5)
        ->and($sheet->getCell('J13')->getValue())->toBe(1052.5)
        ->and($sheet->getCell('A14')->getValue())->toBe('Tổng');
});

test('data rows grow tall enough for long wrapped addresses', function () {
    $this->order->pickupLocation->update(['address' => str_repeat('Khu công nghiệp Yên Bình, Vạn Xuân, Thái Nguyên ', 3)]);
    $rows = ($this->page)()->instance()->getAllRows();

    $sheet = app(BillingStatementExporter::class)->build($rows, $this->customer, 'ALSB')['spreadsheet']->getActiveSheet();

    expect($sheet->getRowDimension(13)->getRowHeight())->toBeGreaterThanOrEqual(33.0);
});

test('return trip order follows its parent order and shares its stt', function () {
    $makeOrder = function (string $code, string $date, array $attributes = []): Order {
        $trip = Trip::create([
            'trip_code' => "TRIP-{$code}",
            'vehicle_id' => $this->order->trip->vehicle_id,
            'driver_id' => $this->user->id,
            'status' => TripStatus::Completed,
            'started_at' => Carbon::parse("{$date} 08:00:00"),
            'completed_at' => Carbon::parse("{$date} 10:00:00"),
        ]);
        $order = Order::create([
            'order_code' => $code,
            'type' => OrderType::External,
            'area_id' => $this->order->area_id,
            'customer_id' => $this->customer->id,
            'trip_id' => $trip->id,
            'pickup_location_id' => $this->order->pickup_location_id,
            'cargo_type' => CargoType::Gcr,
            'chargeable_weight' => 1.25,
            'status' => OrderStatus::Sent,
            'created_by' => $this->user->id,
            ...$attributes,
        ]);
        OrderDeliveryPoint::create(['order_id' => $order->id, 'location_id' => $this->order->deliveryPoints->first()->location_id, 'sequence' => 1]);
        $order->update(['status' => OrderStatus::Completed]);

        return $order;
    };
    $makeOrder('ASG-901', '2026-10-22');
    $makeOrder('ASG-900-RT', '2026-10-25', ['is_return_trip' => true, 'parent_order_id' => $this->order->id]);
    $rows = ($this->page)()->instance()->getAllRows();

    $sheet = app(BillingStatementExporter::class)->build($rows, $this->customer, 'ALSB')['spreadsheet']->getActiveSheet();

    // Đơn quay đầu (25/10) đứng ngay dưới đơn gốc (20/10) với cùng STT 1, cước 50%; đơn 22/10 thành STT 2.
    expect($sheet->getCell('A13')->getValue())->toBe(1)
        ->and($sheet->getCell('A14')->getValue())->toBe(1)
        ->and($sheet->getCell('S14')->getValue())->toBe(581_000.0)
        ->and($sheet->getCell('A15')->getValue())->toBe(2)
        ->and($sheet->getCell('S15')->getValue())->toBe(1_162_000.0);
});
