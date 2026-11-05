<?php

use App\Ai\Agents\DataAssistant;
use App\Ai\AnswerRenderer;
use App\Ai\Tools\AggregateData;
use App\Ai\Tools\ChargeSummary;
use App\Ai\Tools\SearchOrders;
use App\Ai\Tools\SearchTrips;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\TripStatus;
use App\Filament\Resources\Trips\TripResource;
use App\Livewire\DataAssistantChat;
use App\Models\Area;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderCharge;
use App\Models\Trip;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Tools\Request;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Trợ lý dữ liệu: công cụ chỉ đọc tôn trọng quyền, trả link mở bản ghi; popup chat giới hạn quyền và số câu/ngày.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-25 10:00:00');

    $this->user = User::factory()->create(['name' => 'Quản lý A']);
    $this->actingAs($this->user);

    $this->grant = function (string ...$permissions): void {
        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        $this->user->givePermissionTo($permissions);
    };

    $area = Area::create(['code' => 'NBA', 'name' => 'Nội Bài', 'is_active' => true, 'sort_order' => 1]);
    $this->customer = Customer::create(['code' => 'VFC', 'name' => 'Vận tải VFC', 'is_active' => true]);
    $this->trip = Trip::factory()->create([
        'trip_code' => 'TRIP-AI-01',
        'status' => TripStatus::Completed,
        'started_at' => Carbon::parse('2026-10-20 08:00:00'),
        'total_km' => 120,
    ]);

    $makeOrder = fn (string $code, Trip $trip): Order => Order::create([
        'order_code' => $code,
        'type' => OrderType::External,
        'area_id' => $area->id,
        'customer_id' => $this->customer->id,
        'trip_id' => $trip->id,
        'status' => OrderStatus::Completed,
        'planned_loading_at' => Carbon::parse('2026-10-20 08:00:00'),
        'created_by' => $this->user->id,
    ]);

    $this->chargedOrder = $makeOrder('ASG-CO-CUOC', $this->trip);
    $this->unchargedOrder = $makeOrder('ASG-CHUA-CUOC', Trip::factory()->create([
        'trip_code' => 'TRIP-AI-02',
        'status' => TripStatus::Completed,
        'started_at' => Carbon::parse('2026-10-21 08:00:00'),
        'total_km' => 80,
    ]));

    OrderCharge::create([
        'order_id' => $this->chargedOrder->id,
        'customer_id' => $this->customer->id,
        'subtotal' => 1_000_000,
        'vat_amount' => 80_000,
        'total' => 1_080_000,
    ]);
});

it('liệt kê đơn hoàn thành chưa có cước kèm link mở đơn', function () {
    ($this->grant)('ViewAny:Order');

    $result = (string) (new SearchOrders)->handle(new Request([
        'date_from' => '2026-10-01',
        'date_to' => '2026-10-31',
        'missing_charge' => true,
    ]));

    expect($result)
        ->toContain('Tổng số đơn khớp điều kiện: 1')
        ->toContain('[ASG-CHUA-CUOC](')
        ->toContain('orderSearch=ASG-CHUA-CUOC&activeOrderTypeFilter=all&activeStatusFilter=completed&activePlaceFilter=all&showMineOnly=0')
        ->toContain('[Bảng kê](')
        ->toContain('startDate=2026-10-21&endDate=2026-10-21&activeTab=external&serviceType=external&search=ASG-CHUA-CUOC')
        ->not->toContain('ASG-CO-CUOC');
});

it('từ chối khi người dùng không có quyền xem dữ liệu', function () {
    $result = (string) (new SearchOrders)->handle(new Request(['missing_charge' => true]));

    expect($result)
        ->toContain('không có quyền')
        ->not->toContain('ASG-CHUA-CUOC');
});

it('tổng hợp cước theo khách và đếm đơn chưa có cước', function () {
    ($this->grant)('ViewAny:Order');

    $result = (string) (new ChargeSummary)->handle(new Request([
        'date_from' => '2026-10-01',
        'date_to' => '2026-10-31',
        'customer' => 'VFC',
    ]));

    expect($result)
        ->toContain('1 đơn có cước')
        ->toContain('tổng 1.080.000 đ')
        ->toContain('1 đơn hoàn thành chưa có cước')
        ->toContain('[VFC](')
        ->toContain('customerId='.$this->customer->id);
});

it('trả chuyến kèm link mở dòng thời gian của chuyến', function () {
    ($this->grant)('ViewAny:Trip');

    $result = (string) (new SearchTrips)->handle(new Request(['date_from' => '2026-10-01', 'date_to' => '2026-10-31']));

    expect($result)
        ->toContain('Tổng số chuyến: 2, tổng km: 200')
        ->toContain('[TRIP-AI-01]('.TripResource::getUrl('timeline', ['record' => $this->trip]).')');
});

it('ẩn popup và chặn gọi trợ lý khi không có quyền dùng', function () {
    Livewire::test(DataAssistantChat::class)
        ->assertDontSee('Trợ lý dữ liệu')
        ->call('ask', 'Tổng cước tháng này?')
        ->assertForbidden();

    DataAssistant::assertNeverPrompted();
});

it('hỏi trợ lý, lưu hội thoại và hiển thị câu trả lời có link bấm được', function () {
    ($this->grant)(DataAssistantChat::PERMISSION);
    DataAssistant::fake(['Có 1 đơn chưa có cước: [ASG-CHUA-CUOC](/app/orders?search=ASG-CHUA-CUOC) <script>alert(1)</script>']);

    Livewire::test(DataAssistantChat::class)
        ->assertSee('Trợ lý dữ liệu')
        ->call('ask', 'Đơn nào chưa có cước?')
        ->assertSet('error', null)
        ->assertSeeHtml('<a href="/app/orders?search=ASG-CHUA-CUOC" class="da-link">ASG-CHUA-CUOC</a>')
        ->assertDontSeeHtml('<script>alert(1)</script>')
        ->assertSee('Đơn nào chưa có cước?');

    DataAssistant::assertPrompted('Đơn nào chưa có cước?');
    expect(Conversation::where('participant_id', $this->user->id)->count())->toBe(1);
});

it('dừng khi vượt số câu hỏi trong ngày', function () {
    ($this->grant)(DataAssistantChat::PERMISSION);
    config(['ai.assistant.daily_limit' => 1]);
    RateLimiter::clear('data-assistant:'.$this->user->id);
    DataAssistant::fake(['Trả lời 1', 'Trả lời 2']);

    Livewire::test(DataAssistantChat::class)
        ->call('ask', 'Câu 1')
        ->call('ask', 'Câu 2')
        ->assertSet('error', 'Bạn đã hỏi đủ 1 câu hôm nay. Vui lòng quay lại vào ngày mai.');

    DataAssistant::assertPrompted('Câu 1');
    DataAssistant::assertNotPrompted('Câu 2');
});

it('không cho mở hội thoại của người khác', function () {
    ($this->grant)(DataAssistantChat::PERMISSION);
    $other = User::factory()->create();
    $conversation = Conversation::create([
        'id' => (string) str()->uuid(),
        'participant_type' => $other->getMorphClass(),
        'participant_id' => $other->id,
        'title' => 'Hội thoại riêng',
    ]);

    Livewire::test(DataAssistantChat::class)
        ->call('openConversation', $conversation->id)
        ->assertSet('conversationId', null);
});

it('hiện nút chat nổi trên trang admin cho người có quyền', function () {
    ($this->grant)(DataAssistantChat::PERMISSION);

    $this->get('/app')->assertOk()->assertSee('da-fab', false)->assertSee('Hỏi dữ liệu');
});

it('super_admin thấy nút chat dù chưa được gán quyền riêng', function () {
    Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    $this->user->assignRole('super_admin');

    $this->get('/app')->assertOk()->assertSee('da-fab', false);
});

it('tách câu hỏi gợi ý cuối câu trả lời thành nút hỏi tiếp', function () {
    ($this->grant)(DataAssistantChat::PERMISSION);
    DataAssistant::fake(["Tháng này có 1 đơn chưa có cước.\n\n".DataAssistant::suggestionMarker()."\n- Cước tháng này của khách VFC?\n- Xe nào chạy nhiều nhất tháng này?"]);

    Livewire::test(DataAssistantChat::class)
        ->call('ask', 'Đơn nào chưa có cước?')
        ->assertSee('Tháng này có 1 đơn chưa có cước.')
        ->assertSee('Hỏi tiếp')
        ->assertSeeHtml('class="da-chip"')
        ->assertSee('Cước tháng này của khách VFC?')
        ->assertDontSee(DataAssistant::suggestionMarker());
});

it('trình bày bảng: số căn phải, trạng thái thành nhãn màu, mã trong bảng thành chip bấm được', function () {
    ($this->grant)(DataAssistantChat::PERMISSION);
    DataAssistant::fake(["**Hôm qua: 2 chuyến · 200 km**\n\n| Xe | Trạng thái | Km |\n| --- | --- | --- |\n| [20C09163](/app/vehicles?search=20C09163) | Hoàn thành | 1.200 |\n| [29E08245](/app/vehicles?search=29E08245) | Đã huỷ | 80 |"]);

    Livewire::test(DataAssistantChat::class)
        ->call('ask', 'Hôm qua có bao nhiêu chuyến?')
        ->assertSee('Hôm qua: 2 chuyến · 200 km')
        ->assertSeeHtml('<div class="da-table">')
        ->assertSeeHtml('<a href="/app/vehicles?search=20C09163" class="da-chip-link">20C09163</a>')
        ->assertSeeHtml('<span class="da-badge is-success">Hoàn thành</span>')
        ->assertSeeHtml('<span class="da-badge is-danger">Đã huỷ</span>')
        ->assertSeeHtml('<td class="is-num">1.200</td>')
        ->assertSeeHtml('<th class="is-num">Km</th>');
});

it('thống kê linh hoạt: gom theo xe, theo ngày, doanh thu theo khách và đơn hàng ngoài chưa có cước', function () {
    ($this->grant)('ViewAny:Trip', 'ViewAny:Order');
    $range = ['date_from' => '2026-10-01', 'date_to' => '2026-10-31'];
    $plate = $this->trip->vehicle->plate_number;

    $byVehicle = (string) (new AggregateData)->handle(new Request([...$range, 'dataset' => 'trips', 'group_by' => ['vehicle'], 'metrics' => ['count', 'km']]));
    $byDay = (string) (new AggregateData)->handle(new Request([...$range, 'dataset' => 'trips', 'group_by' => ['day'], 'metrics' => ['count']]));
    $revenue = (string) (new AggregateData)->handle(new Request([...$range, 'dataset' => 'charges', 'group_by' => ['customer'], 'metrics' => ['revenue']]));
    $external = (string) (new AggregateData)->handle(new Request([...$range, 'dataset' => 'orders', 'order_type' => 'external', 'metrics' => ['count', 'missing_charge', 'not_exported']]));

    expect($byVehicle)
        ->toContain('TỔNG CỘNG — Số chuyến: 2 · Tổng km: 200')
        ->toContain('Số nhóm: 2')
        ->toContain('| ['.$plate.'](')
        ->toContain('| 1 | 120 |');
    expect($byDay)->toContain('| 2026-10-20 | 1 |')->toContain('| 2026-10-21 | 1 |');
    expect($revenue)->toContain('TỔNG CỘNG — Tổng tiền: 1.080.000')->toContain('| [VFC](');
    expect($external)->toContain('loại đơn = external')->toContain('Số đơn: 2 · Đơn xong chưa có cước: 1 · Có cước chưa xuất bảng kê: 1');
});

it('thống kê linh hoạt từ chối khi không có quyền', function () {
    expect((string) (new AggregateData)->handle(new Request(['dataset' => 'charges', 'metrics' => ['revenue']])))
        ->toContain('không có quyền')
        ->not->toContain('TỔNG CỘNG');
});

it('vẽ biểu đồ cột và đường từ khối chart, bỏ qua JSON hỏng và không để lọt HTML', function () {
    $renderer = app(AnswerRenderer::class);

    $bar = $renderer->render("**Top khách**\n\n```chart\n{\"type\":\"bar\",\"title\":\"Cước theo khách\",\"unit\":\"đ\",\"labels\":[\"VFC\",\"<b>ALSB</b>\"],\"values\":[12500000,6250000]}\n```");
    $line = $renderer->render("```chart\n{\"type\":\"line\",\"unit\":\"chuyến\",\"labels\":[\"01/10\",\"02/10\",\"03/10\"],\"values\":[3,9,5]}\n```");
    $broken = $renderer->render("```chart\n{khong phai json}\n```");

    expect($bar)
        ->toContain('<figure class="da-chart"><figcaption>Cước theo khách</figcaption>')
        ->toContain('style="width: 100%"')
        ->toContain('style="width: 50%"')
        ->toContain('12,5 tr')
        ->toContain('&lt;b&gt;ALSB&lt;/b&gt;')
        ->not->toContain('<b>ALSB</b>');
    expect($line)
        ->toContain('<svg class="da-line"')
        ->toContain('<polyline')
        ->toContain('9 chuyến');
    expect($broken)->not->toContain('da-chart')->not->toContain('DACHART');
});
