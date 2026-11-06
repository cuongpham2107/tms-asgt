<?php

use App\Enums\CargoType;
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
use App\Models\User;
use App\Models\Vehicle;
use Carbon\Carbon;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

/**
 * Sửa nhanh khách hàng trong modal cước của trang Bảng kê — không dùng Gate::before để kiểm tra quyền thật.
 */
beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    $this->customer = Customer::create(['code' => 'ALSB', 'name' => 'ALS Bắc Ninh', 'is_active' => true]);
    Customer::create(['code' => 'CLC', 'name' => 'Tiếp vận Thế Kỷ', 'is_active' => true]);

    $area = Area::create(['code' => 'NBA', 'name' => 'Nội Bài', 'is_active' => true, 'sort_order' => 1]);
    $pickup = Location::create(['code' => 'SEVT', 'name' => 'SEVT', 'loc_type' => 'pickup', 'is_active' => true]);
    $drop = Location::create(['code' => 'SEV', 'name' => 'SEV', 'loc_type' => 'delivery', 'is_active' => true]);
    $vehicle = Vehicle::create(['plate_number' => '20C09032', 'owner' => 'ASGL', 'type' => VehicleOwnerType::Company, 'vehicle_type' => VehicleType::Normal, 'is_active' => true]);
    $trip = Trip::create([
        'trip_code' => 'TRIP-QE-01',
        'vehicle_id' => $vehicle->id,
        'driver_id' => $this->user->id,
        'status' => TripStatus::Completed,
        'started_at' => Carbon::parse('2026-10-20 14:00:00'),
        'completed_at' => Carbon::parse('2026-10-20 17:00:00'),
    ]);

    $this->order = Order::create([
        'order_code' => 'ASG-QE-1',
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

    $this->editCustomer = [
        TestAction::make('editCharge')->arguments(['orderId' => $this->order->id]),
        TestAction::make('editCustomer')->schemaComponent('summary_customer_code'),
    ];

    $this->page = fn () => Livewire::test(TripStatisticsReport::class, [
        'startDate' => '2026-10-01',
        'endDate' => '2026-10-31',
        'activeTab' => 'external',
    ]);

    $this->allowCustomerUpdate = function (): void {
        Permission::firstOrCreate(['name' => 'Update:Customer', 'guard_name' => 'web']);
        $this->user->givePermissionTo('Update:Customer');
    };
});

test('user without customer update permission cannot edit the customer from the charge modal', function () {
    ($this->page)()->assertActionHidden($this->editCustomer);

    // Đối chứng: có quyền thì nút hiện.
    ($this->allowCustomerUpdate)();
    ($this->page)()->assertActionVisible($this->editCustomer);
});

test('percent fields are required so clearing them cannot break the save', function () {
    ($this->allowCustomerUpdate)();

    ($this->page)()
        ->callAction($this->editCustomer, data: [
            'name' => 'ALS Bắc Ninh',
            'code' => 'ALSB',
            'fuel_adjustment_percent' => null,
            'cancel_trip_percent' => null,
        ])
        ->assertHasActionErrors(['fuel_adjustment_percent' => 'required', 'cancel_trip_percent' => 'required']);

    expect((float) $this->customer->fresh()->fuel_adjustment_percent)->toBe(0.0);
});

test('percent fields reject values out of range', function () {
    ($this->allowCustomerUpdate)();

    ($this->page)()
        ->callAction($this->editCustomer, data: [
            'name' => 'ALS Bắc Ninh',
            'code' => 'ALSB',
            'fuel_adjustment_percent' => 150,
            'cancel_trip_percent' => -5,
        ])
        ->assertHasActionErrors(['fuel_adjustment_percent' => 'max', 'cancel_trip_percent' => 'min']);
});

test('customer code must stay unique but may keep its own value', function () {
    ($this->allowCustomerUpdate)();

    ($this->page)()
        ->callAction($this->editCustomer, data: ['name' => 'ALS Bắc Ninh', 'code' => 'CLC', 'fuel_adjustment_percent' => 0, 'cancel_trip_percent' => 0])
        ->assertHasActionErrors(['code' => 'unique']);

    ($this->page)()
        ->callAction($this->editCustomer, data: ['name' => 'ALS Bắc Ninh', 'code' => 'ALSB', 'tax_code' => '2300478023', 'fuel_adjustment_percent' => 16.14, 'cancel_trip_percent' => 35])
        ->assertHasNoActionErrors();

    expect($this->customer->fresh()->tax_code)->toBe('2300478023')
        ->and((float) $this->customer->fresh()->cancel_trip_percent)->toBe(35.0);
});

test('changing the fuel percent of the customer fills it into the open charge form', function () {
    ($this->allowCustomerUpdate)();

    ($this->page)()
        ->callAction($this->editCustomer, data: ['name' => 'ALS Bắc Ninh', 'code' => 'ALSB', 'fuel_adjustment_percent' => 5, 'cancel_trip_percent' => 30])
        ->assertHasNoActionErrors()
        ->assertSchemaStateSet(['fuel_adjustment_percent' => 5, 'cancel_trip_percent' => 30], 'mountedActionSchema0')
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect((float) $this->order->charge()->first()->fuel_adjustment_percent)->toBe(5.0);
});
