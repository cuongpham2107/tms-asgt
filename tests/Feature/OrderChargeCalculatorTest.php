<?php

use App\Enums\CargoType;
use App\Enums\CheckpointType;
use App\Enums\FreightPricingMethod;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\TripStatus;
use App\Enums\VehicleOwnerType;
use App\Enums\VehicleType;
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
use App\Models\WaitingFeeRate;
use App\Services\Billing\OrderChargeCalculator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    $this->area = Area::create(['code' => 'NBA', 'name' => 'Nội Bài', 'is_active' => true, 'sort_order' => 1]);
    $this->customer = Customer::create(['code' => 'ASGS (SAFO)', 'name' => 'SAFO', 'is_active' => true]);
    $this->pickup = Location::create(['code' => 'FUKANG', 'billing_zone_id' => BillingZone::firstOrCreate(['code' => 'KCN QUANG CHÂU'], ['name' => 'Kcn Quang Châu'])->id, 'name' => 'Fukang', 'loc_type' => 'pickup', 'is_active' => true]);
    $this->delivery = Location::create(['code' => 'ALSC', 'billing_zone_id' => BillingZone::firstOrCreate(['code' => 'NỘI BÀI'], ['name' => 'Nội Bài'])->id, 'name' => 'Nhà ga ALSC', 'loc_type' => 'delivery', 'is_active' => true]);

    $this->vehicle = Vehicle::create([
        'plate_number' => '20C04368',
        'owner' => 'ASGL',
        'type' => VehicleOwnerType::Company,
        'vehicle_type' => VehicleType::Normal,
        'is_active' => true,
    ]);

    $this->trip = Trip::create([
        'trip_code' => 'TRIP-01',
        'vehicle_id' => $this->vehicle->id,
        'status' => TripStatus::Delivered,
    ]);

    $this->makeOrder = function (array $attributes = []): Order {
        $order = Order::create(array_merge([
            'order_code' => 'ASG-'.fake()->unique()->numberBetween(1, 99999),
            'type' => OrderType::External,
            'area_id' => $this->area->id,
            'customer_id' => $this->customer->id,
            // Mỗi chuyến 1 đơn: đơn đầu dùng $this->trip, đơn sau có chuyến riêng.
            'trip_id' => $this->trip->orders()->exists()
                ? Trip::create(['trip_code' => 'TRIP-'.fake()->unique()->numberBetween(2, 99999), 'vehicle_id' => $this->vehicle->id, 'status' => TripStatus::Delivered])->id
                : $this->trip->id,
            'pickup_location_id' => $this->pickup->id,
            'cargo_type' => CargoType::Gcr,
            'total_weight' => 1200,
            'chargeable_weight' => 5,
            'planned_loading_at' => Carbon::parse('2026-10-21 10:30:00'),
            'status' => OrderStatus::Sent,
            'created_by' => $this->user->id,
        ], $attributes));

        OrderDeliveryPoint::create([
            'order_id' => $order->id,
            'location_id' => $this->delivery->id,
            'sequence' => 1,
        ]);

        return $order;
    };

    $this->rate = FreightRate::create([
        'customer_id' => $this->customer->id,
        'origin_zone_id' => BillingZone::firstOrCreate(['code' => 'KCN QUANG CHÂU'], ['name' => 'Kcn Quang Châu'])->id,
        'destination_zone_id' => BillingZone::firstOrCreate(['code' => 'NỘI BÀI'], ['name' => 'Nội Bài'])->id,
        'cargo_type' => CargoType::Gcr,
        'prices' => ['1.25' => 750_000, '5' => 1_275_000],
    ]);
});

test('completing an external order calculates its charge automatically', function () {
    $order = ($this->makeOrder)();
    $order->update(['status' => OrderStatus::Completed]);

    $charge = OrderCharge::where('order_id', $order->id)->firstOrFail();

    expect($charge->freight_rate_id)->toBe($this->rate->id)
        ->and($charge->trip_id)->toBe($this->trip->id)
        ->and((float) $charge->chargeable_tonnage)->toBe(5.0)
        ->and($charge->delivery_notes)->toEqual([['number' => null, 'actual_weight' => 1200.0, 'chargeable_weight' => 1200.0]])
        ->and((float) $charge->base_price)->toBe(1_275_000.0)
        // % dầu nhập tay sau, mặc định 0
        ->and((float) $charge->fuel_adjustment_percent)->toBe(0.0)
        ->and((float) $charge->adjusted_price)->toBe(1_275_000.0)
        ->and((float) $charge->vat_percent)->toBe(0.0)
        ->and((float) $charge->total)->toBe(1_275_000.0)
        ->and($charge->price_code)->not->toBeEmpty();
});

test('rate type is taken from the order and picks the matching rate', function () {
    $coldRate = FreightRate::create([
        'customer_id' => $this->customer->id,
        'origin_zone_id' => BillingZone::firstOrCreate(['code' => 'KCN QUANG CHÂU'], ['name' => 'Kcn Quang Châu'])->id,
        'destination_zone_id' => BillingZone::firstOrCreate(['code' => 'NỘI BÀI'], ['name' => 'Nội Bài'])->id,
        'cargo_type' => CargoType::Gcr,
        'rate_type' => 'Hàng lạnh',
        'prices' => ['5' => 2_000_000],
    ]);

    $order = ($this->makeOrder)(['rate_type' => 'Hàng lạnh']);
    $order->update(['status' => OrderStatus::Completed]);

    $charge = OrderCharge::where('order_id', $order->id)->firstOrFail();

    expect($charge->rate_type)->toBe('Hàng lạnh')
        ->and($charge->freight_rate_id)->toBe($coldRate->id)
        ->and((float) $charge->base_price)->toBe(2_000_000.0);
});

test('any location in the same billing zone uses the zone rate', function () {
    // SEV, ALSB… cùng khu vực "KCN QUANG CHÂU" dùng chung đơn giá của khu vực
    $otherPickup = Location::create(['code' => 'SEV', 'billing_zone_id' => BillingZone::firstOrCreate(['code' => 'KCN QUANG CHÂU'], ['name' => 'Kcn Quang Châu'])->id, 'name' => 'SEV', 'loc_type' => 'pickup', 'is_active' => true]);
    $order = ($this->makeOrder)(['pickup_location_id' => $otherPickup->id]);
    $order->update(['status' => OrderStatus::Completed]);

    $charge = OrderCharge::where('order_id', $order->id)->firstOrFail();

    expect($charge->freight_rate_id)->toBe($this->rate->id)
        ->and((float) $charge->base_price)->toBe(1_275_000.0)
        ->and($charge->price_code)->toContain('KCN QUANG CHÂUNỘI BÀI');
});

test('location without a billing zone gets no price', function () {
    $this->pickup->update(['billing_zone_id' => null]);
    $order = ($this->makeOrder)();
    $order->update(['status' => OrderStatus::Completed]);
    $charge = OrderCharge::where('order_id', $order->id)->firstOrFail();

    expect($charge->base_price)->toBeNull()
        ->and(app(OrderChargeCalculator::class)->explainPrice($order->fresh(), $charge, null))
        ->toBe('Điểm lấy hàng FUKANG chưa thuộc khu vực tính cước');
});

test('hhhk orders are not charged', function () {
    $order = ($this->makeOrder)(['type' => OrderType::Hhhk]);
    $order->update(['status' => OrderStatus::Completed]);

    expect(OrderCharge::where('order_id', $order->id)->exists())->toBeFalse();
});

test('return trip is charged at 50 percent by default', function () {
    $order = ($this->makeOrder)(['is_return_trip' => true]);
    $order->update(['status' => OrderStatus::Completed]);

    $charge = OrderCharge::where('order_id', $order->id)->firstOrFail();

    expect((float) $charge->return_trip_percent)->toBe(50.0)
        ->and((float) $charge->base_price)->toBe(637_500.0)
        ->and((float) $charge->adjusted_price)->toBe(638_000.0);
});

test('rate is found when the route is stored in the opposite direction', function () {
    $this->rate->update([
        'origin_zone_id' => BillingZone::firstOrCreate(['code' => 'NỘI BÀI'], ['name' => 'Nội Bài'])->id,
        'destination_zone_id' => BillingZone::firstOrCreate(['code' => 'KCN QUANG CHÂU'], ['name' => 'Kcn Quang Châu'])->id,
    ]);

    $order = ($this->makeOrder)();
    $charge = app(OrderChargeCalculator::class)->calculate($order);

    expect($charge->freight_rate_id)->toBe($this->rate->id);
});

test('order without a matching rate still gets a charge row with no price', function () {
    $order = ($this->makeOrder)(['chargeable_weight' => 8]);
    $charge = app(OrderChargeCalculator::class)->calculate($order);

    expect($charge->exists)->toBeTrue()
        ->and($charge->freight_rate_id)->toBeNull()
        ->and($charge->base_price)->toBeNull();
});

test('rate outside its effective period is ignored', function () {
    $this->rate->update(['effective_to' => '2025-12-31']);

    $order = ($this->makeOrder)();
    $charge = app(OrderChargeCalculator::class)->calculate($order);

    expect($charge->freight_rate_id)->toBeNull();
});

test('recalculating keeps manual inputs and skips exported charges', function () {
    $order = ($this->makeOrder)();
    $calculator = app(OrderChargeCalculator::class);

    $charge = $calculator->calculate($order);
    $charge->update(['delivery_notes' => [['number' => 'PGH-01', 'actual_weight' => 1200, 'chargeable_weight' => 1000]], 'vat_percent' => 8]);

    $charge = $calculator->calculate($order);
    expect($charge->delivery_notes[0]['number'])->toBe('PGH-01')
        ->and((float) $charge->vat_amount)->toBe(102_000.0)
        ->and((float) $charge->total)->toBe(1_377_000.0);

    $charge->update(['exported_at' => now()]);
    $this->rate->update(['prices' => ['5' => 2_000_000]]);

    expect((float) $calculator->calculate($order)->base_price)->toBe(1_275_000.0);
});

test('waiting fee charges full blocks plus one more when leftover exceeds grace', function (int $billableMinutes, int $expectedFee) {
    expect(OrderChargeCalculator::waitingFee($billableMinutes, fee: 50_000, blockMinutes: 60, graceMinutes: 29))->toBe($expectedFee);
})->with([
    'không chờ' => [0, 0],
    'lẻ dưới thời gian miễn thêm' => [60 + 29, 50_000],
    'lẻ vượt thời gian miễn thêm' => [60 + 30, 100_000],
]);

test('waiting minutes come from loading and unloading checkpoints', function (array $rateAttributes) {
    WaitingFeeRate::create([
        ...$rateAttributes,
        'customer_id' => $this->customer->id,
        'tiers' => [
            ['tonnage' => 1.25, 'fee' => 30_000, 'free_minutes' => 30],
            ['tonnage' => 5, 'fee' => 50_000, 'free_minutes' => 60],
        ],
        'fee_block_minutes' => 60,
        'grace_minutes' => 19,
    ]);

    $order = ($this->makeOrder)();

    foreach ([
        [CheckpointType::ArrivedPickup, '2026-10-21 10:00'],
        [CheckpointType::LeftPickup, '2026-10-21 12:30'],
        [CheckpointType::ArrivedDelivery, '2026-10-21 14:00'],
        [CheckpointType::Completed, '2026-10-21 14:30'],
    ] as [$type, $at]) {
        TripCheckpoint::create([
            'trip_id' => $this->trip->id,
            'order_id' => $order->id,
            'checkpoint_type' => $type,
            'occurred_at' => Carbon::parse($at),
        ]);
    }

    $charge = app(OrderChargeCalculator::class)->calculate($order);

    // Thời gian chờ tính tiền: đóng 150' − 60' miễn = 90'; hạ 30' < 60' → 0' ⇒ 90' = 1 giờ + 30' (> 19') ⇒ 2 giờ
    expect($charge->waiting_minutes)->toBe(90)
        ->and((float) $charge->waiting_fee)->toBe(100_000.0)
        ->and(app(OrderChargeCalculator::class)->explainWaiting($order, $charge))
        ->toContain("Đóng hàng 150' + hạ hàng 30' · miễn 60'/chặng → tính 90' = 2 × 50.000 đ");
})->with([
    'dòng phí thường' => [[]],
    // Chưa phân biệt kiểu chờ: khách chỉ có dòng "nổ máy" vẫn dùng dòng đó
    'chỉ có dòng nổ máy' => [['waiting_mode' => WaitingFeeRate::MODE_ENGINE_ON]],
]);

test('multi-point order deducts free minutes per delivery point for waiting time', function () {
    WaitingFeeRate::create([
        'customer_id' => $this->customer->id,
        'surcharge_type' => '',
        'fee_block_minutes' => 60,
        'grace_minutes' => 19,
        'tiers' => [
            ['tonnage' => 1.25, 'fee' => 50_000, 'free_minutes' => 60],
            ['tonnage' => 5, 'fee' => 50_000, 'free_minutes' => 60],
        ],
    ]);

    $order = ($this->makeOrder)(['chargeable_weight' => 5]);

    // Tạo thêm 2 điểm trả (tổng 3 điểm trả)
    $delivery2 = Location::create(['code' => 'DELIV2', 'billing_zone_id' => $this->delivery->billing_zone_id, 'name' => 'Điểm 2', 'loc_type' => 'delivery', 'is_active' => true]);
    $delivery3 = Location::create(['code' => 'DELIV3', 'billing_zone_id' => $this->delivery->billing_zone_id, 'name' => 'Điểm 3', 'loc_type' => 'delivery', 'is_active' => true]);

    $dp1 = $order->deliveryPoints()->first();
    $dp2 = OrderDeliveryPoint::create(['order_id' => $order->id, 'location_id' => $delivery2->id, 'sequence' => 2]);
    $dp3 = OrderDeliveryPoint::create(['order_id' => $order->id, 'location_id' => $delivery3->id, 'sequence' => 3]);

    // Đóng hàng: 185' (10:00 -> 13:05) -> tính 185 - 60 = 125'
    TripCheckpoint::create(['trip_id' => $this->trip->id, 'order_id' => $order->id, 'checkpoint_type' => CheckpointType::ArrivedPickup, 'occurred_at' => Carbon::parse('2026-10-21 10:00')]);
    TripCheckpoint::create(['trip_id' => $this->trip->id, 'order_id' => $order->id, 'checkpoint_type' => CheckpointType::LeftPickup, 'occurred_at' => Carbon::parse('2026-10-21 13:05')]);

    // Điểm 1: 116' (14:00 -> 15:56) -> tính 116 - 60 = 56'
    TripCheckpoint::create(['trip_id' => $this->trip->id, 'order_id' => $order->id, 'delivery_point_id' => $dp1->id, 'checkpoint_type' => CheckpointType::ArrivedDelivery, 'occurred_at' => Carbon::parse('2026-10-21 14:00')]);
    TripCheckpoint::create(['trip_id' => $this->trip->id, 'order_id' => $order->id, 'delivery_point_id' => $dp1->id, 'checkpoint_type' => CheckpointType::Completed, 'occurred_at' => Carbon::parse('2026-10-21 15:56')]);

    // Điểm 2: 30' (16:30 -> 17:00) -> tính 0'
    TripCheckpoint::create(['trip_id' => $this->trip->id, 'order_id' => $order->id, 'delivery_point_id' => $dp2->id, 'checkpoint_type' => CheckpointType::ArrivedDelivery, 'occurred_at' => Carbon::parse('2026-10-21 16:30')]);
    TripCheckpoint::create(['trip_id' => $this->trip->id, 'order_id' => $order->id, 'delivery_point_id' => $dp2->id, 'checkpoint_type' => CheckpointType::Completed, 'occurred_at' => Carbon::parse('2026-10-21 17:00')]);

    // Điểm 3: 46' (17:30 -> 18:16) -> tính 0'
    TripCheckpoint::create(['trip_id' => $this->trip->id, 'order_id' => $order->id, 'delivery_point_id' => $dp3->id, 'checkpoint_type' => CheckpointType::ArrivedDelivery, 'occurred_at' => Carbon::parse('2026-10-21 17:30')]);
    TripCheckpoint::create(['trip_id' => $this->trip->id, 'order_id' => $order->id, 'delivery_point_id' => $dp3->id, 'checkpoint_type' => CheckpointType::Completed, 'occurred_at' => Carbon::parse('2026-10-21 18:16')]);

    $charge = app(OrderChargeCalculator::class)->calculate($order);

    // Tổng thời gian chờ tính tiền: 125' (đóng) + 56' (điểm 1) + 0' (điểm 2) + 0' (điểm 3) = 181'
    // 181' = 3 block 60' lẻ 1' (<= 19') => 3 block * 50.000 = 150.000 đ
    expect($charge->waiting_minutes)->toBe(181)
        ->and((float) $charge->waiting_fee)->toBe(150_000.0)
        ->and(app(OrderChargeCalculator::class)->explainWaiting($order, $charge))
        ->toContain("Đóng hàng 185' (tính 125') + Điểm 1: 116' (tính 56') + Điểm 2: 30' (tính 0') + Điểm 3: 46' (tính 0')")
        ->toContain("miễn 60'/chặng → tính 181' = 3 × 50.000 đ");
});

test('freight rate matches reverse route and handles standard rate type correctly', function () {
    // Chỉ có bảng giá chiều ngược lại: NỘI BÀI -> KCN QUANG CHÂU
    $this->rate->update([
        'origin_zone_id' => $this->delivery->billing_zone_id,
        'destination_zone_id' => $this->pickup->billing_zone_id,
        'rate_type' => '',
        'prices' => ['5' => 1_400_000],
    ]);

    // Đơn có điểm đi KCN QUANG CHÂU -> điểm trả NỘI BÀI (chiều ngược lại của bảng giá)
    $order = ($this->makeOrder)(['chargeable_weight' => 5, 'rate_type' => 'Chuẩn']);
    $charge = app(OrderChargeCalculator::class)->calculate($order);

    expect((float) $charge->base_price)->toBe(1_400_000.0)
        ->and(app(OrderChargeCalculator::class)->explainPrice($order, $charge, $charge->freight_rate_id))
        ->toContain('(chiều đối ứng)');
});

test('backfill command prices completed orders that had no rate yet and leaves the rest alone', function () {
    $prices = $this->rate->prices;
    $this->rate->delete();

    // Đơn hoàn thành lúc chưa có bảng giá → dòng cước không có giá
    $missing = ($this->makeOrder)();
    $missing->update(['status' => OrderStatus::Completed]);
    // Đơn chưa từng có dòng cước (hoàn thành trước khi có tính năng)
    $noCharge = ($this->makeOrder)();
    Order::withoutEvents(fn () => $noCharge->update(['status' => OrderStatus::Completed]));
    // Dòng cước đã sửa tay và dòng đã xuất bảng kê không bị đụng tới
    $manual = ($this->makeOrder)();
    $manual->update(['status' => OrderStatus::Completed]);
    $manual->charge->update(['base_price' => 999_000]);
    $exported = ($this->makeOrder)();
    $exported->update(['status' => OrderStatus::Completed]);
    $exported->charge->update(['exported_at' => now()]);

    FreightRate::create([...$this->rate->only(['customer_id', 'origin_zone_id', 'destination_zone_id', 'cargo_type']), 'prices' => $prices]);

    $this->artisan('order-charges:calculate')->assertSuccessful();

    expect((float) $missing->charge()->first()->base_price)->toBe(1_275_000.0)
        ->and((float) $noCharge->charge()->first()->base_price)->toBe(1_275_000.0)
        ->and((float) $manual->charge()->first()->base_price)->toBe(999_000.0)
        ->and($exported->charge()->first()->base_price)->toBeNull();
});

test('it calculates multi-note freight charges for weight range pricing method', function () {
    $vfc = Customer::create(['code' => 'ASGS(VFC)', 'name' => 'VFC', 'is_active' => true]);
    $ypZone = BillingZone::firstOrCreate(['code' => 'KCN YÊN PHONG'], ['name' => 'KCN Yên Phong, Bắc Ninh']);
    $hpZone = BillingZone::firstOrCreate(['code' => 'HẢI PHÒNG'], ['name' => 'Các KCN Hải Phòng']);

    $locYP = Location::create(['code' => 'YP', 'billing_zone_id' => $ypZone->id, 'name' => 'KCN Yên Phong', 'loc_type' => 'pickup', 'is_active' => true]);
    $locHP = Location::create(['code' => 'HP', 'billing_zone_id' => $hpZone->id, 'name' => 'KCN Hải Phòng', 'loc_type' => 'delivery', 'is_active' => true]);

    $vfcRate = FreightRate::create([
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

    $order = Order::create([
        'order_code' => 'ORD-VFC-01',
        'type' => OrderType::External,
        'area_id' => $this->area->id,
        'customer_id' => $vfc->id,
        'trip_id' => $this->trip->id,
        'pickup_location_id' => $locYP->id,
        'cargo_type' => CargoType::Gcr,
        'total_weight' => 10_000,
        'chargeable_weight' => 10,
        'status' => OrderStatus::Sent,
        'created_by' => $this->user->id,
    ]);

    OrderDeliveryPoint::create(['order_id' => $order->id, 'location_id' => $locHP->id, 'sequence' => 1]);

    $charge = OrderCharge::create([
        'order_id' => $order->id,
        'customer_id' => $vfc->id,
        'cargo_type' => CargoType::Gcr,
        'chargeable_tonnage' => 10,
        'delivery_notes' => [
            ['number' => 'DN01', 'chargeable_weight' => 7000],
            ['number' => 'DN02', 'chargeable_weight' => '3000'],
        ],
    ]);

    $calculator = app(OrderChargeCalculator::class);
    $calculated = $calculator->calculate($order);

    // 7T rơi vào "Từ 4T đến 7T" (2.682.969) + 3T rơi vào "Từ 3T đến 4T" (2.062.898) = 4.745.867 đ
    expect((float) $calculated->base_price)->toBe(4_745_867.0);

    $explanation = $calculator->explainPrice($order, $calculated, $vfcRate->id);
    expect($explanation)->toContain('Phiếu DN01 (7.000 kg · Từ 4T đến 7T: 2.682.969 đ)')
        ->and($explanation)->toContain('Phiếu DN02 (3.000 kg · Từ 3T đến 4T: 2.062.898 đ)')
        ->and($explanation)->toContain('4.745.867 đ');
});

test('it handles decimal weight strings with comma and dot for weight range tiers', function () {
    $vfc = Customer::firstOrCreate(['code' => 'ASGS(VFC)'], ['name' => 'VFC', 'is_active' => true]);
    $ypZone = BillingZone::firstOrCreate(['code' => 'KCN YÊN PHONG'], ['name' => 'KCN Yên Phong, Bắc Ninh']);
    $hpZone = BillingZone::firstOrCreate(['code' => 'HẢI PHÒNG'], ['name' => 'Các KCN Hải Phòng']);

    $locYP = Location::firstOrCreate(['code' => 'YP'], ['billing_zone_id' => $ypZone->id, 'name' => 'KCN Yên Phong', 'loc_type' => 'pickup', 'is_active' => true]);
    $locHP = Location::firstOrCreate(['code' => 'HP'], ['billing_zone_id' => $hpZone->id, 'name' => 'KCN Hải Phòng', 'loc_type' => 'delivery', 'is_active' => true]);

    $rate = FreightRate::firstOrCreate([
        'customer_id' => $vfc->id,
        'origin_zone_id' => $ypZone->id,
        'destination_zone_id' => $hpZone->id,
    ], [
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

    // 4123.232 kg = 4.123232 T -> rơi vào Từ 4T đến 7T (2.682.969)
    expect($rate->priceFor(OrderChargeCalculator::parseKgToTons('4123.232')))->toBe(2_682_969.0);
    expect($rate->priceFor(OrderChargeCalculator::parseKgToTons('4123,232')))->toBe(2_682_969.0);
    // 3000 kg = 3T -> Từ 3T đến 4T
    expect($rate->priceFor(OrderChargeCalculator::parseKgToTons('3000')))->toBe(2_062_898.0);
    // 7000 kg = 7T -> Từ 4T đến 7T
    expect($rate->priceFor(OrderChargeCalculator::parseKgToTons('7000')))->toBe(2_682_969.0);
    // 1999 kg = 1.999T -> Dưới 2T
    expect($rate->priceFor(OrderChargeCalculator::parseKgToTons('1999')))->toBe(1_271_768.0);
    // 2000 kg = 2T -> Từ 2T đến 3T
    expect($rate->priceFor(OrderChargeCalculator::parseKgToTons('2000')))->toBe(1_447_740.0);
});
