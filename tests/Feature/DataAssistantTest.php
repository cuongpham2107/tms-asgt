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
use App\Filament\Plugins\FilamentChatAssistantPlugin;
use App\Filament\Resources\Trips\TripResource;
use App\Models\Area;
use App\Models\Customer;
use App\Models\DriverShift;
use App\Models\DriverSwap;
use App\Models\Order;
use App\Models\OrderCharge;
use App\Models\OrderDeliveryPoint;
use App\Models\Trip;
use App\Models\TripCheckpoint;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Jason\FilamentChatAssistant\Livewire\ChatAssistant;
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

it('ẩn popup chat khi không có quyền dùng', function () {
    $this->get('/app')->assertOk()->assertDontSee('tk-assistant', false);
});

it('hiện danh sách câu hỏi gợi ý nhanh khi chưa có tin nhắn', function () {
    ($this->grant)(FilamentChatAssistantPlugin::PERMISSION);

    Livewire::test(ChatAssistant::class)
        ->set('open', true)
        ->assertSee('Câu hỏi gợi ý nhanh:')
        ->assertSee('Hôm qua có bao nhiêu chuyến, xe nào chạy nhiều nhất?')
        ->assertSee('Hàng ngoài nào tháng này chưa có cước hoặc chưa xuất bảng kê?');
});

it('hiển thị chip gợi ý hỏi tiếp sau câu trả lời của trợ lý', function () {
    ($this->grant)(FilamentChatAssistantPlugin::PERMISSION);
    DataAssistant::fake(["Tháng này có 1 đơn chưa có cước.\n\n".DataAssistant::suggestionMarker()."\n- Cước tháng này của khách VFC?\n- Xe nào chạy nhiều nhất tháng này?"]);

    Livewire::test(ChatAssistant::class)
        ->set('open', true)
        ->set('question', 'Đơn nào chưa có cước?')
        ->call('sendMessage')
        ->call('requestReply')
        ->assertSee('Tháng này có 1 đơn chưa có cước.')
        ->assertSee('Gợi ý hỏi tiếp:')
        ->assertSee('Cước tháng này của khách VFC?')
        ->assertSee('Xe nào chạy nhiều nhất tháng này?');
});

it('hiện nút chat nổi trên trang admin cho người có quyền', function () {
    ($this->grant)(FilamentChatAssistantPlugin::PERMISSION);

    $this->get('/app')->assertOk()->assertSee('tk-assistant', false)->assertSee('tk-assistant__toggle', false);
});

it('nút chat chỉ dựa vào quyền tùy chỉnh Use:DataAssistant, kể cả với super_admin', function () {
    Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    $this->user->assignRole('super_admin');

    $this->get('/app')->assertOk()->assertDontSee('tk-assistant', false);

    ($this->grant)(FilamentChatAssistantPlugin::PERMISSION);

    $this->get('/app')->assertOk()->assertSee('tk-assistant', false);
});

it('migration tạo quyền Use:DataAssistant và gán sẵn cho super_admin', function () {
    $role = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);

    (require database_path('migrations/2026_11_06_081507_add_use_data_assistant_permission.php'))->up();

    expect(Permission::where('name', FilamentChatAssistantPlugin::PERMISSION)->exists())->toBeTrue()
        ->and($role->fresh()->hasPermissionTo(FilamentChatAssistantPlugin::PERMISSION))->toBeTrue();
});

it('tách câu hỏi gợi ý và định dạng trong câu trả lời', function () {
    $raw = "Tháng này có 1 đơn chưa có cước.\n\n".DataAssistant::suggestionMarker()."\n- Cước tháng này của khách VFC?\n- Xe nào chạy nhiều nhất tháng này?";
    $html = app(AnswerRenderer::class)->render($raw);

    expect($html)
        ->toContain('Tháng này có 1 đơn chưa có cước.')
        ->toContain('Gợi ý câu hỏi tiếp theo:')
        ->not->toContain(DataAssistant::suggestionMarker());
});

it('trình bày bảng: số căn phải, trạng thái thành nhãn màu, mã trong bảng thành chip bấm được', function () {
    $html = app(AnswerRenderer::class)->render("**Hôm qua: 2 chuyến · 200 km**\n\n| Xe | Trạng thái | Km |\n| --- | --- | --- |\n| [20C09163](/app/vehicles?search=20C09163) | Hoàn thành | 1.200 |\n| [29E08245](/app/vehicles?search=29E08245) | Đã huỷ | 80 |");

    expect($html)
        ->toContain('Hôm qua: 2 chuyến · 200 km')
        ->toContain('<div class="da-table">')
        ->toContain('<a href="/app/vehicles?search=20C09163" class="da-chip-link">20C09163</a>')
        ->toContain('<span class="da-badge is-success">Hoàn thành</span>')
        ->toContain('<span class="da-badge is-danger">Đã huỷ</span>')
        ->toContain('<td class="is-num">1.200</td>')
        ->toContain('<th class="is-num">Km</th>');
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

it('thống kê bấm sai vị trí theo tài xế và thời gian từ lúc đến điểm đến lúc giao xong', function () {
    ($this->grant)('ViewAny:Trip');
    $point = OrderDeliveryPoint::create(['order_id' => $this->chargedOrder->id, 'sequence' => 1]);
    $press = fn (string $type, string $at, ?string $geo, ?int $pointId = null) => TripCheckpoint::create([
        'trip_id' => $this->trip->id,
        'order_id' => $this->chargedOrder->id,
        'delivery_point_id' => $pointId,
        'driver_id' => $this->trip->driver_id,
        'checkpoint_type' => $type,
        'occurred_at' => Carbon::parse($at),
        'location_geo_status' => $geo,
        'vehicle_geo_status' => 'valid',
    ]);
    $press('arrived_pickup', '2026-10-20 08:30', 'out_of_range');
    $press('left_pickup', '2026-10-20 09:00', 'valid');
    $press('arrived_delivery', '2026-10-20 10:00', 'valid', $point->id);
    $press('completed', '2026-10-20 10:45', 'out_of_range', $point->id);
    $range = ['date_from' => '2026-10-01', 'date_to' => '2026-10-31'];

    $wrong = (string) (new AggregateData)->handle(new Request([...$range, 'dataset' => 'checkpoints', 'group_by' => ['driver'], 'metrics' => ['checked', 'wrong_location', 'wrong_rate']]));
    $stops = (string) (new AggregateData)->handle(new Request([...$range, 'dataset' => 'stops', 'metrics' => ['count', 'avg_minutes']]));
    $pickup = (string) (new AggregateData)->handle(new Request([...$range, 'dataset' => 'trips', 'vehicle' => $this->trip->vehicle->plate_number, 'metrics' => ['avg_pickup_minutes']]));

    expect($wrong)
        ->toContain('Mốc đã kiểm tra vị trí: 4 · Bấm sai vị trí điểm: 2 · Tỉ lệ sai vị trí: 50,0%')
        ->toContain('LƯU Ý')
        ->toContain('| ['.$this->trip->driver->name.'](');
    expect($stops)->toContain('Số điểm giao: 1 · TB phút tại điểm (đến → giao xong): 45');
    expect($pickup)->toContain('TB phút lấy hàng (đến → rời điểm lấy): 30');
});

it('thống kê ca trực (bỏ km nhập sai) và đảo lái theo lý do', function () {
    ($this->grant)('ViewAny:DriverShift', 'ViewAny:Trip');
    $driver = $this->trip->driver;
    $shift = DriverShift::create(['driver_id' => $driver->id, 'shift_type' => 'full', 'start_time' => Carbon::parse('2026-10-20 06:00'), 'end_time' => Carbon::parse('2026-10-20 18:00'), 'total_km' => 150]);
    DriverShift::create(['driver_id' => $driver->id, 'shift_type' => 'full', 'start_time' => Carbon::parse('2026-10-21 06:00'), 'end_time' => Carbon::parse('2026-10-21 12:00'), 'total_km' => 416193]);
    $this->trip->update(['shift_id' => $shift->id]);
    (new DriverSwap)->forceFill(['trip_id' => $this->trip->id, 'from_driver_id' => $driver->id, 'to_driver_id' => $this->user->id, 'reason' => 'cargo_not_unloaded', 'created_by' => $this->user->id, 'created_at' => Carbon::parse('2026-10-20 12:00')])->save();
    $range = ['date_from' => '2026-10-01', 'date_to' => '2026-10-31'];

    $shifts = (string) (new AggregateData)->handle(new Request([...$range, 'dataset' => 'shifts', 'metrics' => ['count', 'hours', 'km', 'bad_km', 'completed_trips', 'no_trip']]));
    $swaps = (string) (new AggregateData)->handle(new Request([...$range, 'dataset' => 'swaps', 'group_by' => ['status'], 'metrics' => ['count']]));

    expect($shifts)->toContain('Số ca: 2 · Tổng giờ trực: 18 · Km trong ca (bỏ ca nhập km sai): 150 · Ca nhập km sai (> 2.000 km): 1 · Chuyến hoàn thành: 1 · Ca không chạy chuyến: 1');
    expect($swaps)->toContain('| Lý do | Số lần đảo lái |')->toContain('| Hàng chưa hạ được | 1 |');
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
        ->toContain('<figure class="da-chart"><figcaption><span>Cước theo khách</span>')
        ->toContain('Tổng 18,8 tr')
        ->toContain('style="width: 100%"')
        ->toContain('style="width: 50%"')
        ->toContain('12,5 tr')
        ->toContain('&lt;b&gt;ALSB&lt;/b&gt;')
        ->not->toContain('<b>ALSB</b>');
    expect($line)
        ->toContain('<svg class="da-plot"')
        ->toContain('class="da-col is-peak"')
        ->toContain('03/10: 5 chuyến');
    expect($broken)->not->toContain('da-chart')->not->toContain('DACHART');
});
