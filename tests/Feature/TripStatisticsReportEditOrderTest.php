<?php

use App\Enums\CargoType;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\TripStatus;
use App\Enums\VehicleOwnerType;
use App\Enums\VehicleType;
use App\Filament\Pages\TripStatisticsReport;
use App\Filament\Resources\Orders\Pages\CreateOrder;
use App\Filament\Resources\Orders\Pages\EditOrder;
use App\Models\Area;
use App\Models\BillingZone;
use App\Models\Customer;
use App\Models\FeeType;
use App\Models\FreightRate;
use App\Models\Location;
use App\Models\Order;
use App\Models\OrderCharge;
use App\Models\OrderChargeFee;
use App\Models\OrderDeliveryPoint;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use Carbon\Carbon;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Gate::before(fn () => true);

    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    $this->area = Area::create([
        'code' => 'NBA',
        'name' => 'Nội Bài',
        'is_active' => true,
        'sort_order' => 1,
    ]);

    $this->customer = Customer::create([
        'code' => 'SAFO',
        'name' => 'SAFO LOGISTICS',
        'is_active' => true,
    ]);

    $originZone = BillingZone::firstOrCreate(['code' => 'KCN QUANG CHÂU'], ['name' => 'Kcn Quang Châu']);
    $destinationZone = BillingZone::firstOrCreate(['code' => 'NỘI BÀI'], ['name' => 'Nội Bài']);

    $this->pickupLocation = Location::create([
        'code' => 'FUKANG',
        'billing_zone_id' => $originZone->id,
        'name' => 'Fukang',
        'loc_type' => 'pickup',
        'is_active' => true,
    ]);

    $this->deliveryLocation = Location::create([
        'code' => 'ALSC',
        'billing_zone_id' => $destinationZone->id,
        'name' => 'Nhà ga ALSC',
        'loc_type' => 'delivery',
        'is_active' => true,
    ]);

    $this->vehicle = Vehicle::create([
        'plate_number' => '20C04368',
        'owner' => 'ASGL',
        'type' => VehicleOwnerType::Company,
        'vehicle_type' => VehicleType::Normal,
        'is_active' => true,
    ]);

    $this->trip = Trip::create([
        'trip_code' => 'TRIP-EDIT-TYPE-01',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->user->id,
        'status' => TripStatus::Completed,
        'started_at' => Carbon::parse('2026-10-21 10:00:00'),
        'completed_at' => Carbon::parse('2026-10-21 13:00:00'),
    ]);

    $this->rate = FreightRate::create([
        'customer_id' => $this->customer->id,
        'origin_zone_id' => $originZone->id,
        'destination_zone_id' => $destinationZone->id,
        'cargo_type' => CargoType::Gcr,
        'prices' => ['5' => 1_300_000],
    ]);
});

test('hhhk order can be switched to external via editOrder inside trip statistics report while preserving delivery points and resolving charge', function () {
    $order = Order::create([
        'order_code' => 'ASG-HHHK-01',
        'type' => OrderType::Hhhk,
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'trip_id' => $this->trip->id,
        'pickup_location_id' => $this->pickupLocation->id,
        'cargo_name' => 'Hàng HHK test chuyển hàng ngoài',
        'total_packages' => 10,
        'total_weight' => 900,
        'planned_loading_at' => Carbon::parse('2026-10-21 10:30:00'),
        'status' => OrderStatus::Completed,
        'created_by' => $this->user->id,
    ]);

    $deliveryPoint = OrderDeliveryPoint::create([
        'order_id' => $order->id,
        'location_id' => $this->deliveryLocation->id,
        'sequence' => 1,
    ]);

    Livewire::test(TripStatisticsReport::class, [
        'startDate' => '2026-10-01',
        'endDate' => '2026-10-31',
        'activeTab' => 'hhhk',
    ])
        ->callAction([
            TestAction::make('editCharge')->arguments(['orderId' => $order->id]),
            TestAction::make('editOrder')->schemaComponent('summary_order_code'),
        ], data: [
            'type' => OrderType::External->value,
            'area_id' => $this->area->id,
            'customer_id' => $this->customer->id,
            'pickup_location_id' => $this->pickupLocation->id,
            'cargo_type' => CargoType::Gcr->value,
            'rate_type' => '',
            'chargeable_weight' => 5,
            'total_weight' => 900,
            'total_packages' => 10,
        ])
        ->assertHasNoActionErrors()
        ->assertMountedActionModalSee('Cước đơn '.$order->order_code);

    $order->refresh();
    $charge = OrderCharge::where('order_id', $order->id)->first();

    expect($order->type)->toBe(OrderType::External)
        ->and($order->area_id)->toBe($this->area->id)
        ->and((float) $order->chargeable_weight)->toBe(5.0)
        ->and($order->cargo_type)->toBe(CargoType::Gcr)
        ->and($order->deliveryPoints()->count())->toBe(1)
        ->and($order->deliveryPoints()->first()->id)->toBe($deliveryPoint->id)
        ->and($charge)->not->toBeNull()
        ->and((float) $charge->base_price)->toBe(1_300_000.0);
});

test('unlocked external order can be switched back to hhhk and deletes its unlocked order charge', function () {
    $order = Order::create([
        'order_code' => 'ASG-EXT-UNLOCKED',
        'type' => OrderType::External,
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'trip_id' => $this->trip->id,
        'pickup_location_id' => $this->pickupLocation->id,
        'cargo_type' => CargoType::Gcr,
        'chargeable_weight' => 5,
        'total_weight' => 900,
        'planned_loading_at' => Carbon::parse('2026-10-21 10:30:00'),
        'status' => OrderStatus::Sent,
        'created_by' => $this->user->id,
    ]);

    OrderDeliveryPoint::create([
        'order_id' => $order->id,
        'location_id' => $this->deliveryLocation->id,
        'sequence' => 1,
    ]);

    $order->update(['status' => OrderStatus::Completed]);

    $charge = OrderCharge::where('order_id', $order->id)->firstOrFail();
    $dropFee = FeeType::firstOrCreate(
        ['code' => 'extra_drop'],
        [
            'name' => 'Rớt điểm',
            'sort' => 1,
            'is_active' => true,
            'calc_method' => 'manual',
        ],
    );
    OrderChargeFee::create([
        'order_charge_id' => $charge->id,
        'fee_type_id' => $dropFee->id,
        'amount' => 100_000,
    ]);

    Livewire::test(TripStatisticsReport::class, [
        'startDate' => '2026-10-01',
        'endDate' => '2026-10-31',
        'activeTab' => 'external',
    ])
        ->callAction([
            TestAction::make('editCharge')->arguments(['orderId' => $order->id]),
            TestAction::make('editOrder')->schemaComponent('summary_order_code'),
        ], data: [
            'type' => OrderType::Hhhk->value,
            'area_id' => $this->area->id,
            'customer_id' => $this->customer->id,
            'pickup_location_id' => $this->pickupLocation->id,
            'total_weight' => 900,
            'total_packages' => 10,
        ])
        ->assertHasNoActionErrors()
        ->assertMountedActionModalSee('Mốc thời gian đơn '.$order->order_code);

    $order->refresh();

    expect($order->type)->toBe(OrderType::Hhhk)
        ->and($order->deliveryPoints()->count())->toBe(1)
        ->and(OrderCharge::where('order_id', $order->id)->exists())->toBeFalse()
        ->and(OrderChargeFee::where('order_charge_id', $charge->id)->exists())->toBeFalse();
});

test('order type cannot be changed when order charge is locked in editOrderAction and EditOrder', function () {
    $order = Order::create([
        'order_code' => 'ASG-EXT-LOCKED',
        'type' => OrderType::External,
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'trip_id' => $this->trip->id,
        'pickup_location_id' => $this->pickupLocation->id,
        'cargo_type' => CargoType::Gcr,
        'chargeable_weight' => 5,
        'total_weight' => 900,
        'planned_loading_at' => Carbon::parse('2026-10-21 10:30:00'),
        'status' => OrderStatus::Sent,
        'created_by' => $this->user->id,
    ]);

    OrderDeliveryPoint::create([
        'order_id' => $order->id,
        'location_id' => $this->deliveryLocation->id,
        'sequence' => 1,
    ]);

    $order->update(['status' => OrderStatus::Completed]);

    $charge = OrderCharge::where('order_id', $order->id)->firstOrFail();
    $charge->update(['exported_at' => now()]);

    Livewire::test(TripStatisticsReport::class, [
        'startDate' => '2026-10-01',
        'endDate' => '2026-10-31',
        'activeTab' => 'external',
    ])
        ->mountAction([
            TestAction::make('editCharge')->arguments(['orderId' => $order->id]),
            TestAction::make('editOrder')->schemaComponent('summary_order_code'),
        ])
        ->assertActionDataSet([
            'type' => OrderType::External,
        ])
        ->assertFormFieldIsDisabled('type');

    $editPage = Livewire::test(EditOrder::class, ['record' => $order->getRouteKey()])
        ->assertFormFieldIsDisabled('type');

    // Guard server-side trong EditOrder::mutateFormDataBeforeSave vẫn chặn đổi type nếu client gửi lên
    $method = new ReflectionMethod(EditOrder::class, 'mutateFormDataBeforeSave');
    $method->setAccessible(true);
    $filtered = $method->invoke($editPage->instance(), [
        'type' => OrderType::Hhhk->value,
        'notes' => 'Vẫn giữ type external',
    ]);

    expect($filtered)->not->toHaveKey('type')
        ->and($filtered['notes'])->toBe('Vẫn giữ type external')
        ->and($order->fresh()->type)->toBe(OrderType::External);
});

test('external order chargeable_weight 5.00 is normalized to 5 when editing and saves without validation error', function () {
    $order = Order::create([
        'order_code' => 'ASG-EXT-5TON',
        'type' => OrderType::External,
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'trip_id' => $this->trip->id,
        'pickup_location_id' => $this->pickupLocation->id,
        'cargo_type' => CargoType::Gcr,
        'chargeable_weight' => 5,
        'total_weight' => 900,
        'planned_loading_at' => Carbon::parse('2026-10-21 10:30:00'),
        'status' => OrderStatus::Sent,
        'created_by' => $this->user->id,
    ]);

    OrderDeliveryPoint::create([
        'order_id' => $order->id,
        'location_id' => $this->deliveryLocation->id,
        'sequence' => 1,
    ]);

    $order->update(['status' => OrderStatus::Completed]);

    Livewire::test(EditOrder::class, ['record' => $order->getRouteKey()])
        ->assertFormSet([
            'chargeable_weight' => '5',
        ])
        ->fillForm([
            'notes' => 'Lưu bình thường với 5 tấn',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($order->fresh()->notes)->toBe('Lưu bình thường với 5 tấn')
        ->and($order->fresh()->chargeable_weight)->toBe('5.00');

    Livewire::test(TripStatisticsReport::class, [
        'startDate' => '2026-10-01',
        'endDate' => '2026-10-31',
        'activeTab' => 'external',
    ])
        ->callAction([
            TestAction::make('editCharge')->arguments(['orderId' => $order->id]),
            TestAction::make('editOrder')->schemaComponent('summary_order_code'),
        ], data: [
            'notes' => 'Sửa từ bảng kê vẫn hợp lệ',
        ])
        ->assertHasNoActionErrors();

    expect($order->fresh()->notes)->toBe('Sửa từ bảng kê vẫn hợp lệ');
});

test('changing the customer while editing an order keeps the pickup location, creating still suggests it', function () {
    Role::firstOrCreate(['name' => 'driver', 'guard_name' => 'web']);
    $alsb = Location::create(['code' => 'ALSB', 'name' => 'ALS Bắc Ninh', 'loc_type' => 'pickup', 'is_active' => true]);
    $alsbCustomer = Customer::create(['code' => 'ALSB', 'name' => 'ALS Bắc Ninh', 'is_active' => true, 'location_id' => $alsb->id]);

    $order = Order::create([
        'order_code' => 'ASG-PICKUP-01',
        'type' => OrderType::Hhhk,
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'trip_id' => $this->trip->id,
        'pickup_location_id' => $this->pickupLocation->id,
        'planned_loading_at' => Carbon::parse('2026-10-21 10:30:00'),
        'status' => OrderStatus::Completed,
        'created_by' => $this->user->id,
    ]);

    // Sửa đơn trong Bảng thống kê: đổi khách hàng không được đổi điểm đóng
    Livewire::test(TripStatisticsReport::class, ['startDate' => '2026-10-01', 'endDate' => '2026-10-31'])
        ->mountAction([
            TestAction::make('editCharge')->arguments(['orderId' => $order->id]),
            TestAction::make('editOrder')->schemaComponent('summary_order_code'),
        ])
        ->fillForm(['customer_id' => $alsbCustomer->id])
        ->assertSchemaStateSet(['pickup_location_id' => $this->pickupLocation->id])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect($order->refresh())
        ->customer_id->toBe($alsbCustomer->id)
        ->pickup_location_id->toBe($this->pickupLocation->id);

    // Sửa đơn ở màn Đơn hàng: cũng giữ nguyên
    Livewire::test(EditOrder::class, ['record' => $order->getRouteKey()])
        ->fillForm(['customer_id' => $this->customer->id])
        ->assertSchemaStateSet(['pickup_location_id' => $this->pickupLocation->id]);

    // Tạo đơn mới: vẫn gợi ý điểm đóng theo khách hàng
    Livewire::test(CreateOrder::class)
        ->fillForm(['customer_id' => $alsbCustomer->id])
        ->assertSchemaStateSet(['pickup_location_id' => $alsb->id]);
});
