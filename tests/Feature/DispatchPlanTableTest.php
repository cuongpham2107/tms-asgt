<?php

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Filament\Pages\VehicleDispatchBoard;
use App\Filament\Widgets\DispatchPlanTable;
use App\Models\Area;
use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Gate::before(fn () => true);
    $this->travelTo(today()->setTime(9, 0));
    Role::create(['name' => 'driver', 'guard_name' => 'web']);
    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    $this->area = Area::create(['type' => 'HHHK', 'code' => 'NBA', 'name' => 'Nội Bài', 'is_active' => true, 'sort_order' => 1]);
    $this->customer = Customer::create(['code' => 'ALSB', 'name' => 'ALS Bắc Ninh', 'is_active' => true]);
});

function planOrder(string $code, OrderStatus $status, mixed $plannedLoadingAt): Order
{
    return Order::create([
        'order_code' => $code,
        'type' => OrderType::Hhhk,
        'area_id' => test()->area->id,
        'customer_id' => test()->customer->id,
        'status' => $status,
        'planned_loading_at' => $plannedLoadingAt,
        'created_by' => test()->user->id,
    ]);
}

test('tab kế hoạch hiện mọi đơn nháp, đơn đã hẹn giờ lên trước theo giờ đóng', function () {
    planOrder('ASG-LATER', OrderStatus::Draft, now()->addDays(2));
    planOrder('ASG-SOON', OrderStatus::Draft, now()->addHours(3));
    planOrder('ASG-ASSIGNED', OrderStatus::Sent, now()->addHours(5));
    planOrder('ASG-AUTO', OrderStatus::Draft, now()->subMinute());
    $late = planOrder('ASG-LATE', OrderStatus::Draft, now()->subDay());
    $late->forceFill(['created_at' => now()->subDays(3)])->saveQuietly();

    $rows = (new DispatchPlanTable)->rows();

    expect(array_column($rows, 'orderCode'))->toBe(['ASG-LATE', 'ASG-SOON', 'ASG-LATER', 'ASG-AUTO'])
        ->and(array_column($rows, 'hasPlannedTime'))->toBe([true, true, true, false])
        ->and(array_column($rows, 'isPastDay'))->toBe([true, false, false, false])
        ->and($rows[0]['minutesLeft'])->toBeLessThan(0)
        ->and($rows[1]['areaCode'])->toBe('NBA')
        ->and($rows[3]['time'])->toBeNull()
        ->and($rows[3]['minutesLeft'])->toBeNull();
});

test('cấu hình chỉ gửi đơn khi React đã sẵn sàng', function () {
    planOrder('ASG-SOON', OrderStatus::Draft, now()->addHours(3));

    $widget = new DispatchPlanTable;
    expect($widget->getRendererConfiguration()['rows'])->toBe([]);

    $widget->isReady = true;
    expect($widget->getRendererConfiguration()['rows'])->toHaveCount(1)
        ->and(array_column($widget->getRendererConfiguration()['areas'], 'code'))->toBe(['NBA'])
        ->and($widget->getRendererConfiguration()['currentUserId'])->toBe($this->user->id)
        ->and($widget->getRendererConfiguration()['rows'][0]['createdById'])->toBe($this->user->id);
});

test('bấm Gán lái, xe trên tab kế hoạch mở modal gán xe của đơn', function () {
    $draft = planOrder('ASG-DRAFT', OrderStatus::Draft, now()->addHours(3));

    Livewire::test(VehicleDispatchBoard::class, ['activeTab' => 'plan'])
        ->call('openAssignTransport', $draft->id)
        ->assertActionMounted(TestAction::make('assign_transport')->arguments(['order' => $draft->id]));
});
