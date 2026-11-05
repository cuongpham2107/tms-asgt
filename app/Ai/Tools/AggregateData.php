<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Concerns\ReadsAppData;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\TripStatus;
use App\Enums\VehicleOwnerType;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Resources\Vehicles\VehicleResource;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Truy vấn thống kê linh hoạt: AI chọn tập dữ liệu, bộ lọc, chiều gom nhóm và chỉ số theo câu hỏi;
 * công cụ dựng câu truy vấn từ danh sách trắng (không có SQL tự do).
 */
class AggregateData implements Tool
{
    use ReadsAppData;

    private const MAX_GROUPS = 50;

    /** @var array<string, array{permission: string, date: string, status: string, metrics: array<string, array{0: string, 1: string}>}> */
    private const DATASETS = [
        'trips' => [
            'permission' => 'ViewAny:Trip',
            'date' => 'COALESCE(trips.started_at, trips.created_at)',
            'status' => 'trips.status',
            'metrics' => [
                'count' => ['COUNT(DISTINCT trips.id)', 'Số chuyến'],
                'km' => ['SUM(trips.total_km)', 'Tổng km'],
                'km_loaded' => ['SUM(trips.total_km_loaded)', 'Km có hàng'],
                'km_empty' => ['SUM(trips.total_km_empty)', 'Km rỗng'],
                'vehicles' => ['COUNT(DISTINCT trips.vehicle_id)', 'Số xe'],
                'drivers' => ['COUNT(DISTINCT trips.driver_id)', 'Số tài xế'],
            ],
        ],
        'orders' => [
            'permission' => 'ViewAny:Order',
            'date' => 'COALESCE(orders.planned_loading_at, orders.created_at)',
            'status' => 'orders.status',
            'metrics' => [
                'count' => ['COUNT(DISTINCT orders.id)', 'Số đơn'],
                'weight' => ['SUM(orders.chargeable_weight)', 'Trọng lượng tính cước'],
                'missing_charge' => ["SUM(CASE WHEN orders.status = 'completed' AND order_charges.id IS NULL THEN 1 ELSE 0 END)", 'Đơn xong chưa có cước'],
                'not_exported' => ['SUM(CASE WHEN order_charges.id IS NOT NULL AND order_charges.exported_at IS NULL THEN 1 ELSE 0 END)', 'Có cước chưa xuất bảng kê'],
                'revenue' => ['SUM(order_charges.total)', 'Tổng tiền cước'],
            ],
        ],
        'charges' => [
            'permission' => 'ViewAny:Order',
            'date' => ChargeSummary::CHARGE_DATE,
            'status' => 'orders.status',
            'metrics' => [
                'count' => ['COUNT(order_charges.id)', 'Số đơn có cước'],
                'revenue' => ['SUM(order_charges.total)', 'Tổng tiền'],
                'subtotal' => ['SUM(order_charges.subtotal)', 'Trước VAT'],
                'vat' => ['SUM(order_charges.vat_amount)', 'VAT'],
                'not_exported' => ['SUM(CASE WHEN order_charges.exported_at IS NULL THEN 1 ELSE 0 END)', 'Chưa xuất bảng kê'],
            ],
        ],
    ];

    /** @var array<string, string> */
    private const DIMENSIONS = [
        'vehicle' => 'Xe',
        'driver' => 'Tài xế',
        'customer' => 'Khách',
        'status' => 'Trạng thái',
        'vehicle_owner' => 'Loại xe',
        'order_type' => 'Loại đơn',
        'day' => 'Ngày',
        'week' => 'Tuần',
        'month' => 'Tháng',
    ];

    /** @var array<string, string> ponytail: strftime chỉ chạy trên SQLite (DB hiện tại), đổi sang DATE_FORMAT nếu chuyển MySQL. */
    private const TIME_FORMATS = ['day' => '%Y-%m-%d', 'week' => '%Y-W%W', 'month' => '%Y-%m'];

    public function description(): Stringable|string
    {
        return 'Công cụ thống kê linh hoạt — dùng cho MỌI câu hỏi đếm, tổng, xếp hạng, so sánh, xu hướng. '
            .'Chọn dataset (trips = chuyến, orders = đơn hàng, charges = cước đã tính), khoảng ngày, bộ lọc, '
            .'group_by (tối đa 2 chiều: vehicle, driver, customer, status, vehicle_owner, order_type, day, week, month) và metrics. '
            .'Ví dụ "xe nào chạy nhiều nhất hôm qua" = dataset trips, ngày hôm qua, group_by [vehicle], metrics [count, km]. '
            .'Kết quả có dòng tổng cộng chính xác và link mở từng xe/khách/tài xế.';
    }

    public function handle(Request $request): Stringable|string
    {
        $datasetKey = array_key_exists($request->string('dataset')->toString(), self::DATASETS) ? $request->string('dataset')->toString() : 'trips';
        $dataset = self::DATASETS[$datasetKey];

        if ($denied = $this->denyUnless($dataset['permission'])) {
            return $denied;
        }

        [$from, $to] = $this->dateRange($request);
        $dimensions = array_slice(array_values(array_intersect((array) $request->array('group_by'), array_keys(self::DIMENSIONS))), 0, 2);
        $metrics = array_values(array_intersect((array) $request->array('metrics'), array_keys($dataset['metrics']))) ?: ['count'];

        $base = $this->baseQuery($datasetKey, $dataset, $from, $to, $request);
        $totals = (clone $base)->selectRaw($this->metricSelect($dataset, $metrics))->first();

        $rows = collect();
        $groupCount = 0;

        if ($dimensions !== []) {
            $grouped = $this->groupedRows(clone $base, $dataset, $dimensions, $metrics, $request);
            $groupCount = $grouped->count();
            $rows = $grouped->take(max(1, min(self::MAX_GROUPS, $request->integer('limit', 20))));
        }

        return $this->format($datasetKey, $dataset, $from, $to, $dimensions, $metrics, $rows, $groupCount, $totals, $request);
    }

    /**
     * @param  array{permission: string, date: string, status: string, metrics: array<string, array{0: string, 1: string}>}  $dataset
     */
    private function baseQuery(string $key, array $dataset, CarbonImmutable $from, CarbonImmutable $to, Request $request): Builder
    {
        $query = match ($key) {
            'trips' => DB::table('trips')->leftJoin('orders', 'orders.trip_id', '=', 'trips.id'),
            'orders' => DB::table('orders')
                ->leftJoin('trips', 'trips.id', '=', 'orders.trip_id')
                ->leftJoin('order_charges', 'order_charges.order_id', '=', 'orders.id'),
            default => DB::table('order_charges')
                ->join('orders', 'orders.id', '=', 'order_charges.order_id')
                ->leftJoin('trips', 'trips.id', '=', 'orders.trip_id'),
        };

        $customer = trim($request->string('customer')->toString());
        $vehicle = trim($request->string('vehicle')->toString());
        $driver = trim($request->string('driver')->toString());
        $status = $request->string('status')->toString();
        $owner = VehicleOwnerType::tryFrom($request->string('vehicle_owner')->toString());
        $orderType = OrderType::tryFrom($request->string('order_type')->toString());

        return $query
            ->leftJoin('vehicles', 'vehicles.id', '=', 'trips.vehicle_id')
            ->leftJoin('users as drivers', 'drivers.id', '=', 'trips.driver_id')
            ->leftJoin('customers', 'customers.id', '=', 'orders.customer_id')
            ->whereRaw("{$dataset['date']} BETWEEN ? AND ?", [$from->toDateTimeString(), $to->toDateTimeString()])
            ->when($customer !== '', fn (Builder $q) => $q->where(fn (Builder $s) => $s->where('customers.code', 'like', "%{$customer}%")->orWhere('customers.name', 'like', "%{$customer}%")))
            ->when($vehicle !== '', fn (Builder $q) => $q->where('vehicles.plate_number', 'like', "%{$vehicle}%"))
            ->when($driver !== '', fn (Builder $q) => $q->where('drivers.name', 'like', "%{$driver}%"))
            ->when($owner, fn (Builder $q) => $q->where('vehicles.type', $owner->value))
            ->when($orderType, fn (Builder $q) => $q->where('orders.type', $orderType->value))
            ->when($status !== '', fn (Builder $q) => $q->where($dataset['status'], $status))
            ->when($status === '' && $request->boolean('exclude_cancelled', true), fn (Builder $q) => $q->where(fn (Builder $s) => $s->whereNull($dataset['status'])->orWhere($dataset['status'], '!=', 'cancelled')));
    }

    /**
     * @param  array{metrics: array<string, array{0: string, 1: string}>}  $dataset
     * @param  list<string>  $metrics
     */
    private function metricSelect(array $dataset, array $metrics): string
    {
        return collect($metrics)->map(fn (string $m): string => "{$dataset['metrics'][$m][0]} as m_{$m}")->implode(', ');
    }

    /**
     * @param  array{date: string, status: string, metrics: array<string, array{0: string, 1: string}>}  $dataset
     * @param  list<string>  $dimensions
     * @param  list<string>  $metrics
     * @return Collection<int, object>
     */
    private function groupedRows(Builder $query, array $dataset, array $dimensions, array $metrics, Request $request): Collection
    {
        $bindings = [];
        $selects = [];

        foreach ($dimensions as $i => $dimension) {
            if (isset(self::TIME_FORMATS[$dimension])) {
                $selects[] = "strftime(?, {$dataset['date']}) as d{$i}";
                $bindings[] = self::TIME_FORMATS[$dimension];
            } else {
                $selects[] = [
                    'vehicle' => 'vehicles.plate_number',
                    'driver' => 'drivers.name',
                    'customer' => 'customers.code',
                    'status' => $dataset['status'],
                    'vehicle_owner' => 'vehicles.type',
                    'order_type' => 'orders.type',
                ][$dimension]." as d{$i}";
            }
            $query->groupBy("d{$i}");
        }

        $query->selectRaw(implode(', ', $selects).', '.$this->metricSelect($dataset, $metrics), $bindings);

        $sortBy = $request->string('sort_by')->toString();
        $isTimeSeries = isset(self::TIME_FORMATS[$dimensions[0]]);

        if (in_array($sortBy, $metrics, true) || ! $isTimeSeries) {
            $query->orderByDesc('m_'.(in_array($sortBy, $metrics, true) ? $sortBy : $metrics[0]));
        } else {
            $query->orderBy('d0');
        }

        return $query->limit(500)->get();
    }

    /**
     * @param  array{metrics: array<string, array{0: string, 1: string}>}  $dataset
     * @param  list<string>  $dimensions
     * @param  list<string>  $metrics
     * @param  Collection<int, object>  $rows
     */
    private function format(string $key, array $dataset, CarbonImmutable $from, CarbonImmutable $to, array $dimensions, array $metrics, Collection $rows, int $groupCount, ?object $totals, Request $request): string
    {
        $filters = collect(['customer' => 'khách', 'vehicle' => 'xe', 'driver' => 'tài xế', 'status' => 'trạng thái', 'vehicle_owner' => 'loại xe', 'order_type' => 'loại đơn'])
            ->filter(fn (string $label, string $field): bool => $request->filled($field))
            ->map(fn (string $label, string $field): string => $label.' = '.$request->string($field))
            ->implode(', ');

        $totalLine = collect($metrics)
            ->map(fn (string $m): string => $dataset['metrics'][$m][1].': '.$this->number($totals?->{'m_'.$m}))
            ->implode(' · ');

        $output = 'Dữ liệu: '.['trips' => 'chuyến', 'orders' => 'đơn hàng', 'charges' => 'cước'][$key].', '.$this->rangeLabel($from, $to)
            .($filters !== '' ? ", lọc: {$filters}" : '')
            .($request->string('status')->toString() === '' && $request->boolean('exclude_cancelled', true) ? ' (đã bỏ bản ghi huỷ)' : '')
            ."\nTỔNG CỘNG — {$totalLine}";

        if ($dimensions === []) {
            return $output;
        }

        $table = $rows->map(function (object $row) use ($dimensions, $metrics, $key, $from, $to, $request): array {
            $cells = [];
            foreach ($dimensions as $i => $dimension) {
                $cells[] = $this->dimensionCell($dimension, $row->{'d'.$i}, $key, $from, $to, OrderType::tryFrom($request->string('order_type')->toString()));
            }
            foreach ($metrics as $metric) {
                $cells[] = $this->number($row->{'m_'.$metric});
            }

            return $cells;
        })->all();

        $headers = [
            ...array_map(fn (string $d): string => self::DIMENSIONS[$d], $dimensions),
            ...array_map(fn (string $m): string => $dataset['metrics'][$m][1], $metrics),
        ];

        return $output."\nSố nhóm: {$groupCount}".($groupCount > count($table) ? ' (hiển thị '.count($table).')' : '')
            ."\n".$this->table($headers, $table);
    }

    private function dimensionCell(string $dimension, mixed $value, string $dataset, CarbonImmutable $from, CarbonImmutable $to, ?OrderType $orderType): string
    {
        if ($value === null || $value === '') {
            return '(không có)';
        }

        return match ($dimension) {
            'vehicle' => $this->link((string) $value, VehicleResource::getUrl('index', ['search' => $value])),
            'driver' => $this->link((string) $value, UserResource::getUrl('index', ['search' => $value])),
            'customer' => $this->link((string) $value, $this->billingUrl($from, $to, (string) $value, $orderType)),
            'status' => ($dataset === 'trips' ? TripStatus::tryFrom((string) $value)?->getLabel() : OrderStatus::tryFrom((string) $value)?->getLabel()) ?? (string) $value,
            'vehicle_owner' => VehicleOwnerType::tryFrom((string) $value)?->getLabel() ?? (string) $value,
            'order_type' => OrderType::tryFrom((string) $value)?->getLabel() ?? (string) $value,
            default => (string) $value,
        };
    }

    private function number(mixed $value): string
    {
        return number_format((float) $value, 0, ',', '.');
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'dataset' => $schema->string()->enum(array_keys(self::DATASETS))->description('trips = chuyến xe, orders = đơn hàng, charges = cước đã tính (doanh thu).')->required(),
            'date_from' => $schema->string()->description('Ngày bắt đầu YYYY-MM-DD. Bỏ trống = đầu tháng này.'),
            'date_to' => $schema->string()->description('Ngày kết thúc YYYY-MM-DD. Bỏ trống = hôm nay.'),
            'group_by' => $schema->array()->items($schema->string()->enum(array_keys(self::DIMENSIONS)))->description('Tối đa 2 chiều gom nhóm. Bỏ trống = chỉ lấy tổng.'),
            'metrics' => $schema->array()->items($schema->string()->enum(['count', 'km', 'km_loaded', 'km_empty', 'vehicles', 'drivers', 'weight', 'missing_charge', 'not_exported', 'revenue', 'subtotal', 'vat']))
                ->description('trips: count, km, km_loaded, km_empty, vehicles, drivers. orders: count, weight, missing_charge, not_exported, revenue. charges: count, revenue, subtotal, vat, not_exported.'),
            'customer' => $schema->string()->description('Lọc theo mã hoặc tên khách.'),
            'vehicle' => $schema->string()->description('Lọc theo biển số.'),
            'driver' => $schema->string()->description('Lọc theo tên tài xế.'),
            'status' => $schema->string()->description('Lọc đúng trạng thái. trips: '.implode(', ', array_column(TripStatus::cases(), 'value')).'. orders/charges: '.implode(', ', array_column(OrderStatus::cases(), 'value')).'.'),
            'vehicle_owner' => $schema->string()->enum(array_column(VehicleOwnerType::cases(), 'value'))->description('company = xe công ty, rent = xe thuê ngoài.'),
            'order_type' => $schema->string()->enum(array_column(OrderType::cases(), 'value'))->description('HHHK = hàng hoá hàng không, external = hàng ngoài.'),
            'exclude_cancelled' => $schema->boolean()->description('Mặc định true: bỏ bản ghi đã huỷ khi không lọc trạng thái.'),
            'sort_by' => $schema->string()->description('Tên metric để xếp giảm dần. Chiều thời gian mặc định xếp theo thời gian tăng dần.'),
            'limit' => $schema->integer()->min(1)->max(self::MAX_GROUPS)->description('Số nhóm hiển thị, mặc định 20.'),
        ];
    }
}
