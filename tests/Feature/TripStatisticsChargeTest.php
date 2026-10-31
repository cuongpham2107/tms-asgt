<?php

use App\Enums\CargoType;
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
use App\Services\Billing\OrderChargeCalculator;
use App\Services\Export\TripExcelExportService;
use Carbon\Carbon;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Gate::before(fn () => true);

    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    $area = Area::create(['code' => 'NBA', 'name' => 'Nội Bài', 'is_active' => true, 'sort_order' => 1]);
    $customer = Customer::create(['code' => 'SAFO', 'name' => 'SAFO', 'is_active' => true]);
    $pickup = Location::create(['code' => 'FUKANG', 'billing_zone_id' => BillingZone::firstOrCreate(['code' => 'KCN QUANG CHÂU'], ['name' => 'Kcn Quang Châu'])->id, 'name' => 'Fukang', 'loc_type' => 'pickup', 'is_active' => true]);
    $delivery = Location::create(['code' => 'ALSC', 'billing_zone_id' => BillingZone::firstOrCreate(['code' => 'NỘI BÀI'], ['name' => 'Nội Bài'])->id, 'name' => 'Nhà ga ALSC', 'loc_type' => 'delivery', 'is_active' => true]);
    $vehicle = Vehicle::create([
        'plate_number' => '20C04368',
        'owner' => 'ASGL',
        'type' => VehicleOwnerType::Company,
        'vehicle_type' => VehicleType::Normal,
        'is_active' => true,
    ]);

    $this->trip = Trip::create([
        'trip_code' => 'TRIP-EXT-01',
        'vehicle_id' => $vehicle->id,
        'driver_id' => $this->user->id,
        'status' => TripStatus::Completed,
        'started_at' => Carbon::parse('2026-10-21 10:00:00'),
        'completed_at' => Carbon::parse('2026-10-21 13:00:00'),
    ]);

    $this->rate = FreightRate::create([
        'customer_id' => $customer->id,
        'origin_zone_id' => BillingZone::firstOrCreate(['code' => 'KCN QUANG CHÂU'], ['name' => 'Kcn Quang Châu'])->id,
        'destination_zone_id' => BillingZone::firstOrCreate(['code' => 'NỘI BÀI'], ['name' => 'Nội Bài'])->id,
        'cargo_type' => CargoType::Gcr,
        'prices' => ['5' => 1_300_000],
    ]);

    $this->order = Order::create([
        'order_code' => 'ASG-272',
        'type' => OrderType::External,
        'area_id' => $area->id,
        'customer_id' => $customer->id,
        'trip_id' => $this->trip->id,
        'pickup_location_id' => $pickup->id,
        'cargo_type' => CargoType::Gcr,
        'total_weight' => 900,
        'chargeable_weight' => 5,
        'planned_loading_at' => Carbon::parse('2026-10-21 10:30:00'),
        'status' => OrderStatus::Sent,
        'created_by' => $this->user->id,
    ]);

    OrderDeliveryPoint::create(['order_id' => $this->order->id, 'location_id' => $delivery->id, 'sequence' => 1]);

    $this->order->update(['status' => OrderStatus::Completed]);
    $this->charge = OrderCharge::where('order_id', $this->order->id)->firstOrFail();

    $this->page = fn () => Livewire::test(TripStatisticsReport::class, [
        'startDate' => '2026-10-01',
        'endDate' => '2026-10-31',
        'activeTab' => 'external',
    ]);
});

test('external tab shows the freight charge of each order', function () {
    ($this->page)()
        ->assertSuccessful()
        ->assertSee('CƯỚC VẬN CHUYỂN')
        ->assertSee('1,300,000');
});

test('edit charge action saves manual fields, other fees and totals', function () {
    $loadingFee = FeeType::where('code', 'loading_unloading')->firstOrFail();

    ($this->page)()
        ->callAction('editCharge', data: [
            'delivery_notes' => [
                ['number' => 'PGH-123', 'actual_weight' => 10_000, 'chargeable_weight' => 7_000],
                ['number' => 'PGH-124', 'actual_weight' => '', 'chargeable_weight' => 3_000],
            ],
            'fuel_adjustment_percent' => 10,
            'vat_percent' => 8,
            'fees' => [
                ['fee_type_id' => $loadingFee->id, 'amount' => 200_000],
            ],
        ], arguments: ['orderId' => $this->order->id])
        ->assertHasNoActionErrors();

    $charge = $this->charge->fresh();

    // 1.300.000 × 1,1 = 1.430.000; + 200.000 phí bốc xếp = 1.630.000; VAT 8% = 130.400
    expect($charge->delivery_notes)->toEqual([
        ['number' => 'PGH-123', 'actual_weight' => 10_000.0, 'chargeable_weight' => 7_000.0],
        ['number' => 'PGH-124', 'actual_weight' => null, 'chargeable_weight' => 3_000.0],
    ])
        ->and((float) $charge->adjusted_price)->toBe(1_430_000.0)
        ->and((float) $charge->other_fees_total)->toBe(200_000.0)
        ->and((float) $charge->vat_amount)->toBe(130_400.0)
        ->and((float) $charge->total)->toBe(1_760_400.0)
        ->and($charge->fees)->toHaveCount(1);
});

test('edit charge modal shows trip milestones and price breakdown', function () {
    ($this->page)()
        ->mountAction('editCharge', arguments: ['orderId' => $this->order->id])
        ->assertMountedActionModalSee(['Mốc thời gian', 'Đóng hàng', 'Hạ hàng', '20C04368', 'Hàng thường (GCR)', '1.300.000 đ', 'Thanh toán']);
});

test('price breakdown updates while editing the form', function () {
    ($this->page)()
        ->mountAction('editCharge', arguments: ['orderId' => $this->order->id])
        ->fillForm(['base_price' => 2_000_000, 'fuel_adjustment_percent' => 10, 'vat_percent' => 8])
        // 2.000.000 × 1,1 = 2.200.000; VAT 8% = 176.000 → 2.376.000
        ->assertMountedActionModalSee(['2.200.000 đ', '176.000 đ', '2.376.000 đ']);
});

test('changing tonnage or return trip looks up the price again on the form', function () {
    $this->rate->update(['prices' => ['5' => 1_300_000, '8' => 2_000_000]]);

    ($this->page)()
        ->mountAction('editCharge', arguments: ['orderId' => $this->order->id])
        ->fillForm(['chargeable_tonnage' => 8])
        ->assertMountedActionModalSee('2.000.000 đ')
        ->fillForm(['is_return_trip' => true])
        ->assertMountedActionModalSee('1.000.000 đ')
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $charge = $this->charge->fresh();

    expect((float) $charge->base_price)->toBe(1_000_000.0)
        ->and((float) $charge->chargeable_tonnage)->toBe(8.0)
        ->and($charge->price_code)->toEndWith('-8');
});

test('other fees list every fee type with zero and only stores non-zero ones', function () {
    $component = ($this->page)()->mountAction('editCharge', arguments: ['orderId' => $this->order->id]);

    $fees = collect($component->instance()->mountedActions[0]['data']['fees']);
    expect($fees)->toHaveCount(FeeType::where('is_active', true)->count())
        ->and($fees->sum('amount'))->toEqual(0);

    $forkliftId = FeeType::where('code', 'forklift')->value('id');
    $component
        ->fillForm(['fees' => $fees->map(fn (array $fee): array => [
            ...$fee,
            'amount' => (int) $fee['fee_type_id'] === $forkliftId ? 150_000 : 0,
        ])->all()])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $charge = $this->charge->fresh();
    expect($charge->fees)->toHaveCount(1)
        ->and($charge->fees->first()->fee_type_id)->toBe($forkliftId)
        ->and((float) $charge->other_fees_total)->toBe(150_000.0);
});

test('money inputs accept thousands separators', function () {
    ($this->page)()
        ->mountAction('editCharge', arguments: ['orderId' => $this->order->id])
        ->fillForm(['base_price' => '2,000,000', 'waiting_fee' => '150,000'])
        ->assertMountedActionModalSee(['2.000.000 đ', '2.150.000 đ'])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $charge = $this->charge->fresh();
    expect((float) $charge->base_price)->toBe(2_000_000.0)
        ->and((float) $charge->waiting_fee)->toBe(150_000.0)
        ->and((float) $charge->total)->toBe(2_150_000.0);
});

test('locked charge cannot be edited or recalculated', function () {
    $this->charge->update(['exported_at' => now()]);
    $this->rate->update(['prices' => ['5' => 9_000_000]]);

    ($this->page)()
        ->callAction('editCharge', data: ['delivery_notes' => [['number' => 'PGH-999']]], arguments: ['orderId' => $this->order->id])
        ->call('recalculateCharge', $this->order->id);

    $charge = $this->charge->fresh();

    expect($charge->delivery_notes[0]['number'])->toBeNull()
        ->and((float) $charge->base_price)->toBe(1_300_000.0);
});

test('recalculate looks up the rate again', function () {
    $this->rate->update(['prices' => ['5' => 1_500_000]]);

    ($this->page)()->call('recalculateCharge', $this->order->id);

    expect((float) $this->charge->fresh()->base_price)->toBe(1_500_000.0);
});

test('exporting excel locks the exported charges and unlock reopens them', function () {
    $component = ($this->page)();
    $component->instance()->exportExcel();

    expect($this->charge->fresh()->isLocked())->toBeTrue()
        ->and($this->charge->fresh()->exported_by)->toBe($this->user->id);

    $component->call('unlockCharge', $this->order->id);

    expect($this->charge->fresh()->isLocked())->toBeFalse();
});

test('external cargo sheet includes the charge columns', function () {
    $spreadsheet = app(TripExcelExportService::class)->buildSpreadsheet(Trip::whereKey($this->trip->id));
    $sheet = $spreadsheet->getSheet(1);

    $headers = collect($sheet->rangeToArray('A2:AZ2')[0])->filter()->values();
    $values = collect($sheet->rangeToArray('A3:AZ3')[0]);
    $column = fn (string $header) => $values[collect($sheet->rangeToArray('A2:AZ2')[0])->search($header)];

    expect($headers)->toContain('Mã giá', 'Đơn giá', 'Chi phí bốc xếp', 'Thanh toán', 'Số phiếu giao hàng')
        ->and((float) $column('Đơn giá'))->toBe(1_300_000.0)
        ->and((float) $column('Thanh toán'))->toBe(1_300_000.0);
});

test('each split delivery note becomes its own row in the external cargo sheet', function () {
    $this->charge->update(['delivery_notes' => [
        ['number' => 'PGH-1', 'actual_weight' => 10_000, 'chargeable_weight' => 7_000],
        ['number' => 'PGH-2', 'actual_weight' => null, 'chargeable_weight' => 3_000],
    ]]);

    $sheet = app(TripExcelExportService::class)->buildSpreadsheet(Trip::whereKey($this->trip->id))->getSheet(1);
    $headers = $sheet->rangeToArray('A2:AZ2')[0];
    $cell = fn (int $row, string $header) => $sheet->rangeToArray("A{$row}:AZ{$row}")[0][array_search($header, $headers, true)];

    // Dòng tách chép nguyên thông tin đơn, chỉ khác phiếu & trọng lượng; tiền chỉ ở dòng gốc.
    expect($cell(4, 'Mã đơn hàng'))->toBe('ASG-272')
        ->and($cell(3, 'Số phiếu giao hàng'))->toBe('PGH-1')
        ->and($cell(4, 'Số phiếu giao hàng'))->toBe('PGH-2')
        ->and((float) $cell(3, 'Trọng lượng tính tiền'))->toBe(7_000.0)
        ->and((float) $cell(4, 'Trọng lượng tính tiền'))->toBe(3_000.0)
        ->and((float) $cell(3, 'Thanh toán'))->toBe(1_300_000.0)
        ->and($cell(4, 'Thanh toán'))->toBeEmpty();
});

test('missing price can be added to the price table from the charge modal', function () {
    $this->rate->delete();
    $noiBai = BillingZone::where('code', 'NỘI BÀI')->firstOrFail();
    $delivery = $this->order->deliveryPoints()->first()->location;
    $delivery->update(['billing_zone_id' => null]);
    app(OrderChargeCalculator::class)->calculate($this->order->fresh());

    ($this->page)()
        ->callAction([
            TestAction::make('editCharge')->arguments(['orderId' => $this->order->id]),
            TestAction::make('addFreightRate')->schemaComponent('base_price'),
        ], data: [
            'origin_zone_id' => $this->rate->origin_zone_id,
            'destination_zone_id' => $noiBai->id,
            'tonnage' => 5,
            'price' => '1,500,000',
        ])
        ->assertHasNoActionErrors()
        ->assertMountedActionModalSee('1.500.000 đ')
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $rate = FreightRate::where('destination_zone_id', $noiBai->id)->firstOrFail();

    // Địa điểm chưa có khu vực được gán luôn, đơn giá mới áp ngay vào dòng cước
    expect($rate->priceFor(5))->toBe(1_500_000.0)
        ->and($delivery->fresh()->billing_zone_id)->toBe($noiBai->id)
        ->and((float) $this->charge->fresh()->base_price)->toBe(1_500_000.0)
        ->and($this->charge->fresh()->freight_rate_id)->toBe($rate->id);
});
