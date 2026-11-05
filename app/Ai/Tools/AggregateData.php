<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Concerns\ReadsAppData;
use App\Enums\CheckpointType;
use App\Enums\DriverSwapReason;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\ShiftType;
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

    private const MIN_RATE_SAMPLE = 5;

    /** Km 1 ca vượt mức này coi là nhập sai (vd km đầu ca = 0) và không cộng vào tổng. */
    private const MAX_SHIFT_KM = 2000;

    /** @var array<string, array{permission: string, date: string, status: string, metrics: array<string, array{0: string, 1: string}>}> */
    private const DATASETS = [
        'trips' => [
            'permission' => 'ViewAny:Trip',
            'date' => 'COALESCE(trips.started_at, trips.created_at)',
            'status' => 'trips.status',
            'driver' => 'trips.driver_id',
            'metrics' => [
                'count' => ['COUNT(DISTINCT trips.id)', 'Số chuyến'],
                'km' => ['SUM(trips.total_km)', 'Tổng km'],
                'km_loaded' => ['SUM(trips.total_km_loaded)', 'Km có hàng'],
                'km_empty' => ['SUM(trips.total_km_empty)', 'Km rỗng'],
                'vehicles' => ['COUNT(DISTINCT trips.vehicle_id)', 'Số xe'],
                'drivers' => ['COUNT(DISTINCT trips.driver_id)', 'Số tài xế'],
                'avg_trip_minutes' => ['AVG(CASE WHEN trips.completed_at IS NOT NULL AND trips.started_at IS NOT NULL THEN (julianday(trips.completed_at) - julianday(trips.started_at)) * 1440 END)', 'TB phút/chuyến (bắt đầu → hoàn thành)'],
                'avg_pickup_minutes' => ["AVG((SELECT (julianday(MIN(CASE WHEN x.checkpoint_type = 'left_pickup' THEN x.occurred_at END)) - julianday(MIN(CASE WHEN x.checkpoint_type = 'arrived_pickup' THEN x.occurred_at END))) * 1440 FROM trip_checkpoints x WHERE x.trip_id = trips.id))", 'TB phút lấy hàng (đến → rời điểm lấy)'],
            ],
        ],
        'orders' => [
            'permission' => 'ViewAny:Order',
            'date' => 'COALESCE(orders.planned_loading_at, orders.created_at)',
            'status' => 'orders.status',
            'driver' => 'trips.driver_id',
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
            'driver' => 'trips.driver_id',
            'metrics' => [
                'count' => ['COUNT(order_charges.id)', 'Số đơn có cước'],
                'revenue' => ['SUM(order_charges.total)', 'Tổng tiền'],
                'subtotal' => ['SUM(order_charges.subtotal)', 'Trước VAT'],
                'vat' => ['SUM(order_charges.vat_amount)', 'VAT'],
                'not_exported' => ['SUM(CASE WHEN order_charges.exported_at IS NULL THEN 1 ELSE 0 END)', 'Chưa xuất bảng kê'],
            ],
        ],
        'checkpoints' => [
            'permission' => 'ViewAny:Trip',
            'date' => 'trip_checkpoints.occurred_at',
            'status' => 'trip_checkpoints.checkpoint_type',
            'driver' => 'COALESCE(trip_checkpoints.driver_id, trips.driver_id)',
            'metrics' => [
                'count' => ['COUNT(*)', 'Số lần bấm mốc'],
                'checked' => ["SUM(CASE WHEN trip_checkpoints.location_geo_status IN ('valid', 'out_of_range') THEN 1 ELSE 0 END)", 'Mốc đã kiểm tra vị trí'],
                'wrong_location' => ["SUM(CASE WHEN trip_checkpoints.location_geo_status = 'out_of_range' THEN 1 ELSE 0 END)", 'Bấm sai vị trí điểm'],
                'wrong_rate' => ["100.0 * SUM(CASE WHEN trip_checkpoints.location_geo_status = 'out_of_range' THEN 1 ELSE 0 END) / NULLIF(SUM(CASE WHEN trip_checkpoints.location_geo_status IN ('valid', 'out_of_range') THEN 1 ELSE 0 END), 0)", 'Tỉ lệ sai vị trí'],
                'phone_away' => ["SUM(CASE WHEN trip_checkpoints.vehicle_geo_status = 'out_of_range' THEN 1 ELSE 0 END)", 'Điện thoại xa xe'],
                'phone_away_rate' => ["100.0 * SUM(CASE WHEN trip_checkpoints.vehicle_geo_status = 'out_of_range' THEN 1 ELSE 0 END) / NULLIF(SUM(CASE WHEN trip_checkpoints.vehicle_geo_status IN ('valid', 'out_of_range') THEN 1 ELSE 0 END), 0)", 'Tỉ lệ điện thoại xa xe'],
                'no_gps' => ["SUM(CASE WHEN trip_checkpoints.vehicle_geo_status = 'unverified' THEN 1 ELSE 0 END)", 'Không xác minh được (thiếu GPS)'],
            ],
        ],
        'stops' => [
            'permission' => 'ViewAny:Trip',
            'date' => 'trip_checkpoints.occurred_at',
            'status' => 'orders.status',
            'driver' => 'COALESCE(trip_checkpoints.driver_id, trips.driver_id)',
            'metrics' => [
                'count' => ['COUNT(*)', 'Số điểm giao'],
                'avg_minutes' => ['AVG((julianday(done.occurred_at) - julianday(trip_checkpoints.occurred_at)) * 1440)', 'TB phút tại điểm (đến → giao xong)'],
                'max_minutes' => ['MAX((julianday(done.occurred_at) - julianday(trip_checkpoints.occurred_at)) * 1440)', 'Lâu nhất (phút)'],
                'over_60' => ['SUM(CASE WHEN (julianday(done.occurred_at) - julianday(trip_checkpoints.occurred_at)) * 1440 > 60 THEN 1 ELSE 0 END)', 'Số điểm quá 60 phút'],
            ],
        ],
        'shifts' => [
            'permission' => 'ViewAny:DriverShift',
            'date' => 'driver_shifts.start_time',
            'status' => 'driver_shifts.shift_type',
            'driver' => 'driver_shifts.driver_id',
            'metrics' => [
                'count' => ['COUNT(*)', 'Số ca'],
                'hours' => ['SUM((julianday(driver_shifts.end_time) - julianday(driver_shifts.start_time)) * 24)', 'Tổng giờ trực'],
                'avg_hours' => ['AVG((julianday(driver_shifts.end_time) - julianday(driver_shifts.start_time)) * 24)', 'TB giờ/ca'],
                'km' => ['SUM(CASE WHEN driver_shifts.total_km BETWEEN 0 AND '.self::MAX_SHIFT_KM.' THEN driver_shifts.total_km END)', 'Km trong ca (bỏ ca nhập km sai)'],
                'bad_km' => ['SUM(CASE WHEN driver_shifts.total_km > '.self::MAX_SHIFT_KM.' OR driver_shifts.total_km < 0 THEN 1 ELSE 0 END)', 'Ca nhập km sai (> 2.000 km)'],
                'completed_trips' => ["SUM((SELECT COUNT(*) FROM trips t WHERE t.shift_id = driver_shifts.id AND t.status = 'completed'))", 'Chuyến hoàn thành'],
                'no_trip' => ["SUM(CASE WHEN NOT EXISTS (SELECT 1 FROM trips t WHERE t.shift_id = driver_shifts.id AND t.status != 'cancelled') THEN 1 ELSE 0 END)", 'Ca không chạy chuyến'],
                'overtime' => ['SUM(CASE WHEN driver_shifts.is_overtime = 1 THEN 1 ELSE 0 END)', 'Ca tăng cường'],
                'open' => ['SUM(CASE WHEN driver_shifts.end_time IS NULL THEN 1 ELSE 0 END)', 'Ca chưa kết thúc'],
            ],
        ],
        'swaps' => [
            'permission' => 'ViewAny:Trip',
            'date' => 'driver_swaps.created_at',
            'status' => 'driver_swaps.reason',
            'driver' => 'driver_swaps.from_driver_id',
            'metrics' => [
                'count' => ['COUNT(*)', 'Số lần đảo lái'],
                'trips' => ['COUNT(DISTINCT driver_swaps.trip_id)', 'Số chuyến có đảo lái'],
                'receivers' => ['COUNT(DISTINCT driver_swaps.to_driver_id)', 'Số tài xế nhận'],
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
        'location' => 'Điểm',
        'day' => 'Ngày',
        'week' => 'Tuần',
        'month' => 'Tháng',
    ];

    /** @var array<string, string> ponytail: strftime chỉ chạy trên SQLite (DB hiện tại), đổi sang DATE_FORMAT nếu chuyển MySQL. */
    private const TIME_FORMATS = ['day' => '%Y-%m-%d', 'week' => '%Y-W%W', 'month' => '%Y-%m'];

    public function description(): Stringable|string
    {
        return 'Công cụ thống kê linh hoạt — dùng cho MỌI câu hỏi đếm, tổng, xếp hạng, so sánh, xu hướng. '
            .'Chọn dataset: trips = chuyến (kể cả thời gian chuyến, thời gian lấy hàng), orders = đơn hàng, charges = cước đã tính, '
            .'checkpoints = từng lần tài xế bấm mốc (kiểm tra bấm sai vị trí, điện thoại xa xe), stops = từng điểm giao (thời gian từ lúc đến điểm đến lúc giao xong), '
            .'shifts = ca trực của tài xế (giờ trực, km, ca không chạy chuyến, ca tăng cường; chỉ nhóm theo driver, status = loại ca, thời gian), '
            .'swaps = đảo lái (driver = tài xế giao lại xe, status = lý do; nhóm được theo xe, khách, thời gian). '
            .'Rồi chọn khoảng ngày, bộ lọc, '
            .'group_by (tối đa 2 chiều: vehicle, driver, customer, status, vehicle_owner, order_type, location, day, week, month) và metrics. '
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
            'charges' => DB::table('order_charges')
                ->join('orders', 'orders.id', '=', 'order_charges.order_id')
                ->leftJoin('trips', 'trips.id', '=', 'orders.trip_id'),
            // Mỗi dòng = 1 điểm giao: mốc "đến giao" ghép với mốc "hoàn thành" cùng điểm.
            'stops' => DB::table('trip_checkpoints')
                ->join('trip_checkpoints as done', fn ($join) => $join->on('done.delivery_point_id', '=', 'trip_checkpoints.delivery_point_id')->where('done.checkpoint_type', 'completed'))
                ->where('trip_checkpoints.checkpoint_type', 'arrived_delivery')
                ->leftJoin('trips', 'trips.id', '=', 'trip_checkpoints.trip_id')
                ->leftJoin('orders', 'orders.id', '=', 'trip_checkpoints.order_id'),
            'checkpoints' => DB::table('trip_checkpoints')
                ->leftJoin('trips', 'trips.id', '=', 'trip_checkpoints.trip_id')
                ->leftJoin('orders', 'orders.id', '=', 'trip_checkpoints.order_id'),
            'swaps' => DB::table('driver_swaps')
                ->leftJoin('trips', 'trips.id', '=', 'driver_swaps.trip_id')
                ->leftJoin('orders', 'orders.trip_id', '=', 'trips.id'),
            // Ca trực không gắn với 1 chuyến/đơn: nối "rỗng" để các chiều xe/khách/điểm trả (không có) thay vì lỗi SQL.
            'shifts' => DB::table('driver_shifts')
                ->leftJoin('trips', fn ($join) => $join->whereRaw('1 = 0'))
                ->leftJoin('orders', fn ($join) => $join->whereRaw('1 = 0')),
        };

        // Điểm: điểm giao của mốc (nếu có), không thì điểm lấy hàng của đơn.
        $locationId = in_array($key, ['checkpoints', 'stops'], true)
            ? 'COALESCE(delivery_points.location_id, orders.pickup_location_id)'
            : 'orders.pickup_location_id';

        if (in_array($key, ['checkpoints', 'stops'], true)) {
            $query->leftJoin('order_delivery_points as delivery_points', 'delivery_points.id', '=', 'trip_checkpoints.delivery_point_id');
        }

        $customer = trim($request->string('customer')->toString());
        $vehicle = trim($request->string('vehicle')->toString());
        $driver = trim($request->string('driver')->toString());
        $status = $request->string('status')->toString();
        $owner = VehicleOwnerType::tryFrom($request->string('vehicle_owner')->toString());
        $orderType = OrderType::tryFrom($request->string('order_type')->toString());

        return $query
            ->leftJoin('vehicles', 'vehicles.id', '=', 'trips.vehicle_id')
            ->leftJoin('users as drivers', 'drivers.id', '=', DB::raw($dataset['driver']))
            ->leftJoin('customers', 'customers.id', '=', 'orders.customer_id')
            ->leftJoin('locations', 'locations.id', '=', DB::raw($locationId))
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
                    'location' => 'locations.code',
                ][$dimension]." as d{$i}";
            }
            $query->groupBy("d{$i}");
        }

        $query->selectRaw(implode(', ', $selects).', '.$this->metricSelect($dataset, $metrics), $bindings);

        $sortBy = $request->string('sort_by')->toString();
        $isTimeSeries = isset(self::TIME_FORMATS[$dimensions[0]]);

        if (in_array($sortBy, $metrics, true) || ! $isTimeSeries) {
            if (str_ends_with($sortBy, '_rate')) {
                // Xếp theo tỉ lệ: bỏ nhóm quá ít mẫu (2 lần bấm sai 2 = 100%) để không xếp nhầm lên đầu.
                $query->havingRaw('COUNT(*) >= ?', [self::MIN_RATE_SAMPLE]);
            }
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
            ->map(fn (string $m): string => $dataset['metrics'][$m][1].': '.$this->number($totals?->{'m_'.$m}, $m))
            ->implode(' · ');

        $output = 'Dữ liệu: '.['trips' => 'chuyến', 'orders' => 'đơn hàng', 'charges' => 'cước', 'checkpoints' => 'lần bấm mốc', 'stops' => 'điểm giao', 'shifts' => 'ca trực', 'swaps' => 'đảo lái'][$key].', '.$this->rangeLabel($from, $to)
            .($filters !== '' ? ", lọc: {$filters}" : '')
            .($request->string('status')->toString() === '' && $request->boolean('exclude_cancelled', true) ? ' (đã bỏ bản ghi huỷ)' : '')
            ."\nTỔNG CỘNG — {$totalLine}";

        if ($key === 'checkpoints') {
            $output .= "\nLƯU Ý (phải nói lại cho người dùng): kiểm tra vị trí đang ở chế độ chỉ ghi nhận; toạ độ một số điểm còn sai nên 'sai vị trí' có thể bị báo nhầm."
                .' Muốn biết lỗi do tài xế hay do toạ độ điểm, xem thêm theo điểm (group_by location): một điểm có tỉ lệ sai cao với nhiều tài xế khác nhau thường là toạ độ điểm sai.';
        }

        if ($dimensions === []) {
            return $output;
        }

        $table = $rows->map(function (object $row) use ($dimensions, $metrics, $key, $from, $to, $request): array {
            $cells = [];
            foreach ($dimensions as $i => $dimension) {
                $cells[] = $this->dimensionCell($dimension, $row->{'d'.$i}, $key, $from, $to, OrderType::tryFrom($request->string('order_type')->toString()));
            }
            foreach ($metrics as $metric) {
                $cells[] = $this->number($row->{'m_'.$metric}, $metric);
            }

            return $cells;
        })->all();

        $statusHeader = ['checkpoints' => 'Loại mốc', 'shifts' => 'Loại ca', 'swaps' => 'Lý do'][$key] ?? self::DIMENSIONS['status'];
        $headers = [
            ...array_map(fn (string $d): string => $d === 'status' ? $statusHeader : self::DIMENSIONS[$d], $dimensions),
            ...array_map(fn (string $m): string => $dataset['metrics'][$m][1], $metrics),
        ];

        $rateNote = str_ends_with($request->string('sort_by')->toString(), '_rate') ? ' (chỉ xếp các nhóm có từ '.self::MIN_RATE_SAMPLE.' bản ghi)' : '';

        return $output."\nSố nhóm: {$groupCount}{$rateNote}".($groupCount > count($table) ? ' (hiển thị '.count($table).')' : '')
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
            'status' => match ($dataset) {
                'trips' => TripStatus::tryFrom((string) $value)?->getLabel(),
                'checkpoints' => CheckpointType::tryFrom((string) $value)?->getLabel(),
                'shifts' => ShiftType::tryFrom((string) $value)?->getLabel(),
                'swaps' => DriverSwapReason::tryFrom((string) $value)?->getLabel(),
                default => OrderStatus::tryFrom((string) $value)?->getLabel(),
            } ?? (string) $value,
            'vehicle_owner' => VehicleOwnerType::tryFrom((string) $value)?->getLabel() ?? (string) $value,
            'order_type' => OrderType::tryFrom((string) $value)?->getLabel() ?? (string) $value,
            default => (string) $value,
        };
    }

    private function number(mixed $value, string $metric): string
    {
        if ($value === null) {
            return '—';
        }

        return str_ends_with($metric, '_rate')
            ? number_format((float) $value, 1, ',', '.').'%'
            : number_format((float) $value, 0, ',', '.');
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'dataset' => $schema->string()->enum(array_keys(self::DATASETS))->description('trips = chuyến xe, orders = đơn hàng, charges = cước đã tính (doanh thu), checkpoints = lần bấm mốc, stops = điểm giao, shifts = ca trực, swaps = đảo lái.')->required(),
            'date_from' => $schema->string()->description('Ngày bắt đầu YYYY-MM-DD. Bỏ trống = đầu tháng này.'),
            'date_to' => $schema->string()->description('Ngày kết thúc YYYY-MM-DD. Bỏ trống = hôm nay.'),
            'group_by' => $schema->array()->items($schema->string()->enum(array_keys(self::DIMENSIONS)))->description('Tối đa 2 chiều gom nhóm. Bỏ trống = chỉ lấy tổng.'),
            'metrics' => $schema->array()->items($schema->string()->enum(collect(self::DATASETS)->flatMap(fn (array $d): array => array_keys($d['metrics']))->unique()->values()->all()))
                ->description(collect(self::DATASETS)->map(fn (array $d, string $key): string => $key.': '.implode(', ', array_keys($d['metrics'])))->implode('. ').'.'),
            'customer' => $schema->string()->description('Lọc theo mã hoặc tên khách.'),
            'vehicle' => $schema->string()->description('Lọc theo biển số.'),
            'driver' => $schema->string()->description('Lọc theo tên tài xế.'),
            'status' => $schema->string()->description('Lọc đúng trạng thái. trips: '.implode(', ', array_column(TripStatus::cases(), 'value')).'. orders/charges/stops: '.implode(', ', array_column(OrderStatus::cases(), 'value')).'. checkpoints (loại mốc): '.implode(', ', array_column(CheckpointType::cases(), 'value')).'. shifts (loại ca): '.implode(', ', array_column(ShiftType::cases(), 'value')).'. swaps (lý do): '.implode(', ', array_column(DriverSwapReason::cases(), 'value')).'.'),
            'vehicle_owner' => $schema->string()->enum(array_column(VehicleOwnerType::cases(), 'value'))->description('company = xe công ty, rent = xe thuê ngoài.'),
            'order_type' => $schema->string()->enum(array_column(OrderType::cases(), 'value'))->description('HHHK = hàng hoá hàng không, external = hàng ngoài.'),
            'exclude_cancelled' => $schema->boolean()->description('Mặc định true: bỏ bản ghi đã huỷ khi không lọc trạng thái.'),
            'sort_by' => $schema->string()->description('Tên metric để xếp giảm dần. Chiều thời gian mặc định xếp theo thời gian tăng dần.'),
            'limit' => $schema->integer()->min(1)->max(self::MAX_GROUPS)->description('Số nhóm hiển thị, mặc định 20.'),
        ];
    }
}
