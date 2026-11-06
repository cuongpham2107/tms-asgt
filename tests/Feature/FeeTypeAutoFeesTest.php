<?php

use App\Enums\CargoType;
use App\Enums\FeeCalcMethod;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\TripStatus;
use App\Enums\VehicleOwnerType;
use App\Enums\VehicleType;
use App\Filament\Pages\TripStatisticsReport;
use App\Filament\Resources\FeeTypes\Pages\ListFeeTypes;
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
use Carbon\Carbon;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    $this->area = Area::create(['code' => 'NBA', 'name' => 'Nội Bài', 'is_active' => true, 'sort_order' => 1]);
    $this->customer = Customer::create(['code' => 'ASGS (SAFO)', 'name' => 'SAFO', 'is_active' => true]);
    $this->nba = BillingZone::create(['code' => 'NOI-BAI', 'name' => 'NỘI BÀI']);
    $quangChau = BillingZone::create(['code' => 'KCN-QUANG-CHAU', 'name' => 'KCN QUANG CHÂU']);
    $this->fukang = Location::create(['code' => 'FUKANG', 'name' => 'Fukang', 'billing_zone_id' => $quangChau->id, 'loc_type' => 'pickup', 'is_active' => true]);
    $this->ups = Location::create(['code' => 'UPS', 'name' => 'UPS', 'billing_zone_id' => $this->nba->id, 'loc_type' => 'delivery', 'is_active' => true]);
    $this->alsc = Location::create(['code' => 'ALSC', 'name' => 'ALSC', 'billing_zone_id' => $this->nba->id, 'loc_type' => 'delivery', 'is_active' => true]);
    $this->alse = Location::create(['code' => 'ALSE', 'name' => 'ALSE', 'loc_type' => 'delivery', 'is_active' => true]);

    FreightRate::create([
        'customer_id' => $this->customer->id,
        'origin_zone_id' => $quangChau->id,
        'destination_zone_id' => $this->nba->id,
        'cargo_type' => CargoType::Gcr,
        'prices' => ['5' => 1_000_000],
    ]);

    $vehicle = Vehicle::create(['plate_number' => '20C18325', 'owner' => 'ASGL', 'type' => VehicleOwnerType::Company, 'vehicle_type' => VehicleType::Normal, 'is_active' => true]);

    /**
     * Đơn đã hoàn thành đi Fukang → các điểm trả theo thứ tự.
     *
     * @param  list<Location>  $drops
     */
    $this->makeOrder = function (array $drops, array $attributes = []) use ($vehicle): OrderCharge {
        $trip = Trip::create(['trip_code' => 'T-'.fake()->unique()->numberBetween(1, 99999), 'vehicle_id' => $vehicle->id, 'status' => TripStatus::Delivered]);
        $order = Order::create(array_merge([
            'order_code' => 'ASG-'.fake()->unique()->numberBetween(1, 99999),
            'type' => OrderType::External,
            'area_id' => $this->area->id,
            'customer_id' => $this->customer->id,
            'trip_id' => $trip->id,
            'pickup_location_id' => $this->fukang->id,
            'cargo_type' => CargoType::Gcr,
            'chargeable_weight' => 5,
            'planned_loading_at' => Carbon::parse('2026-10-21 10:30:00'),
            'status' => OrderStatus::Sent,
            'created_by' => $this->user->id,
        ], $attributes));

        foreach ($drops as $index => $location) {
            OrderDeliveryPoint::create(['order_id' => $order->id, 'location_id' => $location->id, 'sequence' => $index + 1]);
        }

        $order->update(['status' => OrderStatus::Completed]);

        return OrderCharge::where('order_id', $order->id)->firstOrFail();
    };

    $this->rule = fn (array $attributes): FeeType => FeeType::create([
        'customer_id' => $this->customer->id,
        'code' => 'extra_drop',
        'sort' => 100,
        'is_active' => true,
        ...$attributes,
    ]);

    $this->autoFees = fn (OrderCharge $charge): array => $charge->fresh()->fees()->where('is_auto', true)->with('feeType')->get()
        ->mapWithKeys(fn ($fee): array => [$fee->feeType->name => (float) $fee->amount])
        ->all();
});

test('fee type calculates the amount by its method', function (FeeCalcMethod $method, float $rate, float $quantity, ?float $base, float $expected) {
    $feeType = new FeeType(['customer_id' => $this->customer->id, 'calc_method' => $method, 'amount' => $rate]);

    expect($feeType->calculate($quantity, $base)['amount'])->toBe($expected);
})->with([
    'fixed' => [FeeCalcMethod::Fixed, 300_000, 1, null, 300_000.0],
    'per package' => [FeeCalcMethod::PerPackage, 3_750, 233, null, 873_750.0],
    'per pallet' => [FeeCalcMethod::PerPallet, 30_000, 9, null, 270_000.0],
    'per extra drop' => [FeeCalcMethod::PerExtraDrop, 400_000, 2, null, 800_000.0],
    'percent of base' => [FeeCalcMethod::PercentOfBase, 50, 1, 1_000_000, 500_000.0],
]);

test('manual fee types have no calculated amount', function () {
    expect((new FeeType(['calc_method' => FeeCalcMethod::Manual, 'amount' => 1]))->calculate(1, null))->toBeNull();
});

test('suggested quantity comes from pallets, packages and extra drop points', function () {
    $charge = ($this->makeOrder)([$this->ups, $this->alsc, $this->alse], ['pallet_count' => 9, 'total_packages' => 233]);
    $order = $charge->order;

    expect((new FeeType(['calc_method' => FeeCalcMethod::PerPallet]))->suggestedQuantity($order))->toBe(9.0)
        ->and((new FeeType(['calc_method' => FeeCalcMethod::PerPackage]))->suggestedQuantity($order))->toBe(233.0)
        ->and((new FeeType(['calc_method' => FeeCalcMethod::PerExtraDrop]))->suggestedQuantity($order))->toBe(2.0)
        ->and((new FeeType(['calc_method' => FeeCalcMethod::Fixed]))->suggestedQuantity($order))->toBe(1.0);
});

test('only fees marked "every order" are added automatically when the charge is calculated', function () {
    ($this->rule)(['name' => 'Bốc xếp', 'code' => 'loading_unloading', 'calc_method' => FeeCalcMethod::PerPackage, 'amount' => 3_750, 'auto_apply' => true]);
    ($this->rule)(['name' => 'Rớt điểm ALSE', 'calc_method' => FeeCalcMethod::PerExtraDrop, 'amount' => 400_000]);
    ($this->rule)(['name' => 'Tắt', 'calc_method' => FeeCalcMethod::Fixed, 'amount' => 1, 'auto_apply' => true, 'is_active' => false]);
    FeeType::create(['code' => 'other', 'name' => 'Dùng chung', 'calc_method' => FeeCalcMethod::Fixed, 'amount' => 1, 'auto_apply' => true, 'sort' => 1]);

    $charge = ($this->makeOrder)([$this->ups, $this->alse], ['total_packages' => 233]);

    expect(($this->autoFees)($charge))->toBe(['Bốc xếp' => 873_750.0])
        ->and((float) $charge->fresh()->other_fees_total)->toBe(873_750.0);
});

test('recalculating replaces auto fees but keeps a fee the accountant chose and does not double it', function () {
    $packages = ($this->rule)(['name' => 'Bốc xếp', 'code' => 'loading_unloading', 'calc_method' => FeeCalcMethod::PerPackage, 'amount' => 3_750, 'auto_apply' => true]);
    $pallets = ($this->rule)(['name' => 'Kéo hàng', 'code' => 'hauling', 'calc_method' => FeeCalcMethod::PerPallet, 'amount' => 30_000, 'auto_apply' => true]);
    $charge = ($this->makeOrder)([$this->ups], ['total_packages' => 10, 'pallet_count' => 2]);

    // Kế toán sửa tay bốc xếp 100k → không bị cộng thêm phí tự động cùng loại.
    $charge->fees()->where('fee_type_id', $packages->id)->delete();
    $charge->fees()->create(['fee_type_id' => $packages->id, 'amount' => 100_000]);
    $charge->order->update(['pallet_count' => 5]);

    app(OrderChargeCalculator::class)->calculate($charge->order->fresh(), $charge->fresh());

    $fees = $charge->fresh()->fees()->get();
    expect($fees->where('fee_type_id', $packages->id)->pluck('amount')->map(fn ($a) => (float) $a)->all())->toBe([100_000.0])
        ->and((float) $fees->firstWhere('fee_type_id', $pallets->id)->amount)->toBe(150_000.0)
        ->and((float) $charge->fresh()->other_fees_total)->toBe(250_000.0);
});

test('entering quantity for a customer fee in the charge modal fills amount, then saves it', function () {
    Gate::before(fn () => true);
    $alseFee = ($this->rule)(['name' => 'Rớt điểm ALSE', 'calc_method' => FeeCalcMethod::PerExtraDrop, 'amount' => 400_000]);
    $charge = ($this->makeOrder)([$this->ups, $this->alse]);

    $page = Livewire::test(TripStatisticsReport::class, ['startDate' => '2026-10-01', 'endDate' => '2026-10-31', 'activeTab' => 'external'])
        ->mountAction(TestAction::make('editCharge')->arguments(['orderId' => $charge->order_id]));

    $rowKey = collect($page->instance()->mountedActions[0]['data']['fees'])->search(fn (array $row): bool => (int) $row['fee_type_id'] === $alseFee->id);
    expect($rowKey)->not->toBeFalse();

    $page->set("mountedActions.0.data.fees.{$rowKey}.quantity", 1.0)
        ->assertSet("mountedActions.0.data.fees.{$rowKey}.amount", 400_000.0)
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $fee = $charge->fresh()->fees()->where('fee_type_id', $alseFee->id)->firstOrFail();
    expect((float) $fee->amount)->toBe(400_000.0)
        ->and((float) $fee->quantity)->toBe(1.0)
        ->and($fee->is_auto)->toBeFalse();
});

test('sample command creates customer fees once and dry run writes nothing', function () {
    Customer::create(['code' => 'SDS HN', 'name' => 'SDS HN', 'is_active' => true]);

    $this->artisan('fee-types:seed-samples', ['--dry-run' => true])->assertSuccessful();
    expect(FeeType::whereNotNull('customer_id')->count())->toBe(0);

    $this->artisan('fee-types:seed-samples')->assertSuccessful();
    $this->artisan('fee-types:seed-samples')->assertSuccessful();

    expect(FeeType::where('customer_id', $this->customer->id)->count())->toBe(5)
        ->and(FeeType::whereNotNull('customer_id')->where('name', 'Chi phí bốc xếp')->firstOrFail()->auto_apply)->toBeTrue()
        ->and(FeeType::where('name', 'Rớt điểm ALSE')->firstOrFail()->calc_method)->toBe(FeeCalcMethod::PerExtraDrop)
        // CLC, SDS HCM chưa có trong DB test → bỏ qua, không lỗi.
        ->and(FeeType::whereNotNull('customer_id')->where('name', 'Chi phí kéo hàng')->exists())->toBeFalse();
});

test('admin creates a customer fee from the fee type list', function () {
    Gate::before(fn () => true);

    Livewire::test(ListFeeTypes::class)
        ->loadTable()
        ->callAction('create', data: [
            'customer_id' => $this->customer->id,
            'name' => 'Rớt điểm ALSE',
            'code' => 'extra_drop',
            'sort' => 10,
            'is_active' => true,
            'calc_method' => FeeCalcMethod::PerExtraDrop->value,
            'amount' => 400_000,
        ])
        ->assertHasNoActionErrors()
        ->filterTable('customer_id', $this->customer->id)
        ->assertSee('Theo mỗi điểm rớt')
        ->assertSee('400.000 đ / điểm');

    expect(FeeType::where('name', 'Rớt điểm ALSE')->firstOrFail()->customer_id)->toBe($this->customer->id);
});
