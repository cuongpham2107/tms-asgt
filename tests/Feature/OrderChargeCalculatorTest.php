<?php

use App\Enums\CargoType;
use App\Enums\CheckpointType;
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
            'trip_id' => $this->trip->id,
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

    expect(OrderCharge::where('order_id', $order->id)->firstOrFail()->base_price)->toBeNull();
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

test('waiting minutes come from loading and unloading checkpoints', function () {
    WaitingFeeRate::create([
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

    // Thời gian chờ = đóng hàng 150' + hạ hàng 30' = 180'.
    // Tính phí: đóng 150' − 60' miễn = 90'; hạ 30' < 60' → 0' ⇒ 90' = 1 giờ + 30' (> 19') ⇒ 2 giờ
    expect($charge->waiting_minutes)->toBe(180)
        ->and((float) $charge->waiting_fee)->toBe(100_000.0);
});
