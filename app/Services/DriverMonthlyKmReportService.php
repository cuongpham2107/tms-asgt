<?php

namespace App\Services;

use App\Enums\CheckpointType;
use App\Enums\OnDutyLocation;
use App\Enums\OrderStatus;
use App\Enums\TripStatus;
use App\Models\DriverShift;
use App\Models\DriverSwap;
use App\Models\Location;
use App\Models\Order;
use App\Models\Trip;
use App\Models\User;
use App\Models\VehicleGpsLog;
use App\Services\Export\TripExcelExportService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Tổng hợp km có hàng / không hàng theo tài xế trong một khoảng thời gian (ngày / tuần / tháng).
 *
 * Tính các chuyến đã kết thúc giống màn thống kê của app lái xe: hoàn thành, đã bàn giao đảo lái và
 * đã huỷ (xe vẫn chạy km); gán vào kỳ theo giờ bắt đầu chuyến. Chuyến đảo lái được chia
 * km theo đoạn từng tài xế lái; mỗi đoạn chỉ được tính khi ca của đoạn đó đã kết thúc (đã chốt).
 */
class DriverMonthlyKmReportService
{
    /** Chuyến hoàn thành nhanh hơn mức này coi như bấm bắt đầu/kết thúc liền nhau. */
    public const MIN_COMPLETION_MINUTES = 5;

    /** Hoàn thành hoặc chờ lâu hơn mức này (12 giờ) coi như quên bấm checkpoint. */
    public const MAX_PLAUSIBLE_MINUTES = 720;

    /** Tuyến cần ít nhất ngần này chuyến hợp lệ trong kỳ mới có chuẩn để so. */
    public const ROUTE_MIN_TRIPS = 3;

    /** Bán kính gom cụm điểm GPS khi xe dừng đỗ tại một vị trí (mét). */
    private const STOP_CLUSTER_RADIUS_M = 80;

    /** Bán kính tối đa quanh mốc kho nhận/hạ để nhận diện khoảng dừng đóng/hạ hàng (mét). */
    private const LOCATION_MATCH_RADIUS_M = 500;

    /**
     * Phần km của từng tài xế trên từng chuyến trong khoảng [$from, $to].
     *
     * @return Collection<int, array{trip_id: int, trip_code: string, plate: ?string, started_at: ?Carbon, driver_id: int, status: TripStatus, order_codes: list<string>, is_empty: bool, km_total: float, km_loaded: float, km_empty: float}>
     */
    public function tripLines(Carbon $from, Carbon $to): Collection
    {
        $trips = Trip::query()
            ->with([
                'vehicle:id,plate_number',
                'orders' => fn ($query) => $query->where('status', '!=', OrderStatus::Draft)->select(['id', 'trip_id', 'order_code']),
            ])
            ->whereIn('status', [TripStatus::Completed, TripStatus::DriverSwap, TripStatus::Cancelled])
            ->where(fn (Builder $q): Builder => $q
                ->whereBetween('started_at', [$from, $to])
                ->orWhere(fn (Builder $o): Builder => $o->whereNull('started_at')->whereBetween('completed_at', [$from, $to])))
            ->orderBy('started_at')
            ->get();

        $swapTripIds = DriverSwap::whereIn('trip_id', $trips->pluck('id'))->distinct()->pluck('trip_id')->flip();

        $pieces = $trips->flatMap(fn (Trip $trip): array => $swapTripIds->has($trip->id)
            ? $this->swapPieces($trip)
            : [$this->wholeTripPiece($trip)]);

        $endedShiftIds = DriverShift::whereIn('id', $pieces->pluck('shift_id')->filter()->unique())
            ->whereNotNull('end_time')
            ->pluck('id')
            ->flip();

        return $pieces
            ->filter(fn (array $piece): bool => $piece['driver_id'] !== null && $endedShiftIds->has($piece['shift_id']))
            ->groupBy(fn (array $piece): string => $piece['trip']->id.'-'.$piece['driver_id'])
            ->map(function (Collection $group): array {
                $trip = $group->first()['trip'];
                $total = $group->sum('km_total');
                $loaded = $group->sum('km_loaded');
                $orderCodes = $trip->orders->pluck('order_code')->filter()->sort()->values()->all();

                return [
                    'trip_id' => $trip->id,
                    'trip_code' => $trip->trip_code,
                    'plate' => $trip->vehicle?->plate_number,
                    'started_at' => $trip->started_at ?? $trip->completed_at,
                    'driver_id' => (int) $group->first()['driver_id'],
                    'status' => $trip->status,
                    'order_codes' => $orderCodes,
                    // Chuyến không hàng = chuyến không có đơn nào
                    'is_empty' => $orderCodes === [],
                    'km_total' => round($total, 1),
                    'km_loaded' => round($loaded, 1),
                    'km_empty' => round(max(0.0, $total - $loaded), 1),
                ];
            })
            ->values();
    }

    /**
     * Một dòng mỗi tài xế: tài xế đang hoạt động (kể cả không chạy chuyến nào) và tài xế có chuyến trong kỳ.
     *
     * @param  Collection<int, array{trip_id: int, driver_id: int, is_empty: bool, km_total: float, km_loaded: float, km_empty: float}>  $lines
     * @return Collection<int, array{driver_id: int, driver_name: string, employee_code: ?string, station: ?OnDutyLocation, trip_count: int, empty_trip_count: int, km_total: float, km_loaded: float, km_empty: float}>
     */
    public function summarize(Collection $lines): Collection
    {
        $linesByDriver = $lines->groupBy('driver_id');

        return User::query()
            ->where(fn (Builder $q): Builder => $q
                ->where(fn (Builder $active): Builder => $active->role('driver')->where('is_active', true))
                ->orWhereIn('id', $linesByDriver->keys()))
            ->get(['id', 'name', 'employee_code', 'station'])
            ->map(function (User $driver) use ($linesByDriver): array {
                $driverLines = $linesByDriver->get($driver->id, collect());

                return [
                    'driver_id' => $driver->id,
                    'driver_name' => $driver->name,
                    'employee_code' => $driver->employee_code,
                    'station' => $driver->station,
                    'trip_count' => $driverLines->pluck('trip_id')->unique()->count(),
                    'empty_trip_count' => $driverLines->where('is_empty', true)->pluck('trip_id')->unique()->count(),
                    'km_total' => round($driverLines->sum('km_total'), 1),
                    'km_loaded' => round($driverLines->sum('km_loaded'), 1),
                    'km_empty' => round($driverLines->sum('km_empty'), 1),
                ];
            })
            ->sortBy([['km_total', 'desc'], ['driver_name', 'asc']])
            ->values();
    }

    /**
     * @return Collection<int, array{driver_id: int, driver_name: string, employee_code: ?string, station: ?OnDutyLocation, trip_count: int, empty_trip_count: int, km_total: float, km_loaded: float, km_empty: float}>
     */
    public function driverRows(Carbon $from, Carbon $to): Collection
    {
        return $this->summarize($this->tripLines($from, $to));
    }

    /**
     * Dòng bảng kê chỉ xem (không có cước/phí) của các chuyến hoàn thành trong kỳ, cùng nguồn với trang Bảng kê,
     * kèm giờ bắt đầu / kết thúc chuyến lấy từ checkpoint started / end.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function statementRows(Carbon $from, Carbon $to, ?int $driverId = null): Collection
    {
        $trips = Trip::query()
            ->with([
                'vehicle',
                'driver',
                'orders.customer',
                'orders.area',
                'orders.pickupLocation',
                'orders.deliveryPoints.location',
                'orders.tripCheckpoints',
                'checkpoints',
                'startLocation',
                'endLocation',
            ])
            ->where('status', TripStatus::Completed)
            ->when($driverId !== null, fn (Builder $q): Builder => $q->where('driver_id', $driverId))
            ->where(fn (Builder $q): Builder => $q
                ->whereBetween('started_at', [$from, $to])
                ->orWhere(fn (Builder $o): Builder => $o->whereNull('started_at')->whereBetween('created_at', [$from, $to])))
            ->orderBy('started_at')
            ->get();

        $tripsById = $trips->keyBy('id');
        $stopsByVehicle = $this->extractVehicleStops($trips, $from, $to);

        return app(TripExcelExportService::class)->getTripSummaryRows($trips)
            ->map(function (array $row) use ($tripsById, $stopsByVehicle): array {
                $trip = $tripsById[$row['trip_id']];
                $checkpoints = $trip->checkpoints;
                $tripStartedAt = $checkpoints->firstWhere('checkpoint_type', CheckpointType::Started)?->effectiveOccurredAt() ?? $trip->started_at;
                $tripEndedAt = $checkpoints->where('checkpoint_type', CheckpointType::End)->last()?->effectiveOccurredAt() ?? $trip->completed_at;
                $order = $row['order_id'] !== null ? $trip->orders->firstWhere('id', $row['order_id']) : null;
                $vehicleStops = $trip->vehicle_id !== null ? ($stopsByVehicle[$trip->vehicle_id] ?? []) : [];
                $gpsMetrics = $order !== null
                    ? $this->matchOrderGpsStops($trip, $order, $row, $tripStartedAt, $tripEndedAt, $vehicleStops)
                    : ['gps_time_a' => null, 'gps_time_b' => null, 'gps_time_c' => null, 'gps_time_d' => null, 'gps_loading' => null, 'gps_unloading' => null];

                return [
                    ...$row,
                    'driver_id' => $trip->driver_id,
                    'trip_started_at' => $tripStartedAt,
                    'trip_ended_at' => $tripEndedAt,
                    ...$gpsMetrics,
                ];
            })
            ->values();
    }

    /**
     * Đánh giá tài xế trên các dòng bảng kê:
     * - Thời gian đóng/hạ chuẩn thực tế được tính từ đầu mút đầu → đầu mút cuối của khoảng xe dừng hoạt động
     *   trên GPS (thỏa mãn gần mốc điểm nhận/hạ nhất và gần thời điểm bấm nút đến điểm nhận/hạ).
     * - Thời gian hoàn thành chuyến chuẩn thực tế tính từ đầu mút xe đến điểm đóng hàng đầu tiên đến đầu mút
     *   xe rời điểm hạ hàng cuối cùng trên GPS (nếu chuyến thiếu GPS thì dự phòng bằng trung vị tuyến).
     *
     * @param  Collection<int, array<string, mixed>>  $rows  Kết quả statementRows()
     * @return array{trips: list<array<string, mixed>>, drivers: list<array<string, mixed>>}
     */
    public function evaluate(Collection $rows): array
    {
        $trips = $rows->groupBy('trip_id')->map(function (Collection $tripRows): array {
            $first = $tripRows->first();
            $orderRows = $tripRows->whereNotNull('order_id');
            $loading = $orderRows->pluck('time_loading')->filter(fn (?int $minutes): bool => $minutes !== null);
            $unloading = $orderRows->pluck('time_unloading')->filter(fn (?int $minutes): bool => $minutes !== null);
            $hasWait = $loading->isNotEmpty() || $unloading->isNotEmpty();
            $completion = $first['trip_started_at'] && $first['trip_ended_at']
                ? max(0, (int) $first['trip_started_at']->diffInMinutes($first['trip_ended_at']))
                : null;
            $wait = $hasWait ? (int) ($loading->sum() + $unloading->sum()) : null;

            $gpsLoading = $orderRows->pluck('gps_loading')->filter(fn (?int $minutes): bool => $minutes !== null);
            $gpsUnloading = $orderRows->pluck('gps_unloading')->filter(fn (?int $minutes): bool => $minutes !== null);
            $hasGpsWait = $gpsLoading->isNotEmpty() || $gpsUnloading->isNotEmpty();
            $gpsWait = $hasGpsWait ? (int) ($gpsLoading->sum() + $gpsUnloading->sum()) : null;

            $firstGpsArrival = $orderRows->pluck('gps_time_a')->filter()->sort()->first();
            $lastGpsDeparture = $orderRows->pluck('gps_time_d')->filter()->sort()->last();
            $gpsCompletion = ($firstGpsArrival instanceof Carbon && $lastGpsDeparture instanceof Carbon && $lastGpsDeparture->gte($firstGpsArrival))
                ? (int) round($firstGpsArrival->diffInSeconds($lastGpsDeparture) / 60)
                : null;

            return [
                'trip_id' => $first['trip_id'],
                'driver_id' => $first['driver_id'],
                'driver_name' => $first['driver_name'],
                'started_at' => $first['trip_started_at'],
                'codes' => $tripRows->pluck('code')->filter()->unique()->values()->all(),
                'is_empty' => $orderRows->isEmpty(),
                'route' => $tripRows->pluck('journey')->filter()->unique()->implode(' + ') ?: '—',
                'completion' => $completion,
                'loading' => $hasWait ? (int) $loading->sum() : null,
                'unloading' => $hasWait ? (int) $unloading->sum() : null,
                'wait' => $wait,
                'gps_completion' => $gpsCompletion,
                'gps_loading' => $gpsLoading->isNotEmpty() ? (int) $gpsLoading->sum() : null,
                'gps_unloading' => $gpsUnloading->isNotEmpty() ? (int) $gpsUnloading->sum() : null,
                'gps_wait' => $gpsWait,
                'gps_time_a' => $firstGpsArrival instanceof Carbon ? $firstGpsArrival->format('H:i') : null,
                'gps_time_b' => ($lastB = $orderRows->pluck('gps_time_b')->filter()->sort()->last()) instanceof Carbon ? $lastB->format('H:i') : null,
                'gps_time_c' => ($firstC = $orderRows->pluck('gps_time_c')->filter()->sort()->first()) instanceof Carbon ? $firstC->format('H:i') : null,
                'gps_time_d' => $lastGpsDeparture instanceof Carbon ? $lastGpsDeparture->format('H:i') : null,
                'flag' => self::anomaly($completion, $wait),
            ];
        });

        $valid = $trips->whereNull('flag');
        $routeStandards = $valid->groupBy('route')->map(fn (Collection $group): array => [
            'completion' => self::median($group->pluck('completion')),
            'wait' => self::median($group->pluck('wait')),
            'samples' => $group->count(),
        ]);

        $trips = $trips->map(function (array $trip) use ($routeStandards): array {
            $standard = $trip['flag'] === null ? ($routeStandards[$trip['route']] ?? null) : null;
            // Ưu tiên chuẩn thực tế từ đầu mút dừng GPS của chính chuyến đó; nếu xe không có log GPS thì dự phòng chuẩn tuyến
            $expectedCompletion = $trip['flag'] === null
                ? ($trip['gps_completion'] ?? $standard['completion'] ?? null)
                : null;
            $expectedWait = $trip['flag'] === null
                ? ($trip['gps_wait'] ?? $standard['wait'] ?? null)
                : null;

            return [
                ...$trip,
                'route_completion' => $expectedCompletion,
                'route_wait' => $expectedWait,
                'route_samples' => $routeStandards[$trip['route']]['samples'] ?? 0,
            ];
        })->sortBy('started_at')->values();

        $drivers = $trips->groupBy('driver_id')->map(function (Collection $driverTrips): array {
            $valid = $driverTrips->whereNull('flag');
            $average = fn (string $key): ?int => ($values = $valid->pluck($key)->filter(fn (?int $value): bool => $value !== null))->isEmpty()
                ? null
                : (int) round($values->avg());

            return [
                'driver_id' => $driverTrips->first()['driver_id'],
                'driver_name' => $driverTrips->first()['driver_name'],
                'trip_count' => $valid->count(),
                'flagged_count' => $driverTrips->count() - $valid->count(),
                'avg_completion' => $average('completion'),
                'avg_wait' => $average('wait'),
                'avg_loading' => $average('loading'),
                'avg_unloading' => $average('unloading'),
                ...self::againstRoute($valid, 'completion', 'route_completion'),
                ...self::againstRoute($valid, 'wait', 'route_wait'),
            ];
        })->values();

        return ['trips' => $trips->all(), 'drivers' => $drivers->all()];
    }

    /**
     * Lý do chuyến bị loại khỏi đánh giá, null nếu số liệu hợp lý.
     */
    public static function anomaly(?int $completion, ?int $wait): ?string
    {
        return match (true) {
            $completion !== null && $completion < self::MIN_COMPLETION_MINUTES => 'Hoàn thành dưới '.self::MIN_COMPLETION_MINUTES.' phút — có thể bấm bắt đầu/kết thúc liền nhau',
            $completion !== null && $completion > self::MAX_PLAUSIBLE_MINUTES => 'Hoàn thành quá '.(self::MAX_PLAUSIBLE_MINUTES / 60).' giờ — có thể quên bấm kết thúc chuyến',
            $wait !== null && $wait > self::MAX_PLAUSIBLE_MINUTES => 'Chờ quá '.(self::MAX_PLAUSIBLE_MINUTES / 60).' giờ — có thể quên bấm checkpoint',
            default => null,
        };
    }

    /**
     * Tài xế lệch bao nhiêu so với chuẩn tuyến trên các chuyến so được: số phút trung bình mỗi chuyến
     * và % trên tổng chuẩn. Dương = chậm hơn / chờ lâu hơn.
     *
     * @param  Collection<int, array<string, mixed>>  $trips
     * @return array<string, int|null>
     */
    private static function againstRoute(Collection $trips, string $actualKey, string $routeKey): array
    {
        $compared = $trips->filter(fn (array $trip): bool => $trip[$actualKey] !== null && $trip[$routeKey] !== null);
        $expected = $compared->sum($routeKey);
        $difference = $compared->sum($actualKey) - $expected;

        return [
            "{$actualKey}_compared" => $compared->count(),
            "{$actualKey}_diff" => $compared->isEmpty() ? null : (int) round($difference / $compared->count()),
            "{$actualKey}_diff_percent" => $expected > 0 ? (int) round($difference / $expected * 100) : null,
        ];
    }

    /**
     * Trung vị (bỏ giá trị null); null khi có dưới ROUTE_MIN_TRIPS giá trị.
     *
     * @param  Collection<int, ?int>  $values
     */
    private static function median(Collection $values): ?float
    {
        $values = $values->filter(fn (?int $value): bool => $value !== null);

        return $values->count() < self::ROUTE_MIN_TRIPS ? null : (float) $values->median();
    }

    /**
     * @return array{trip: Trip, driver_id: ?int, shift_id: ?int, km_total: float, km_loaded: float}
     */
    private function wholeTripPiece(Trip $trip): array
    {
        return [
            'trip' => $trip,
            'driver_id' => $trip->driver_id,
            'shift_id' => $trip->shift_id,
            'km_total' => (float) ($trip->total_km ?? 0),
            'km_loaded' => (float) ($trip->total_km_loaded ?? 0),
        ];
    }

    /**
     * @return list<array{trip: Trip, driver_id: ?int, shift_id: ?int, km_total: float, km_loaded: float}>
     */
    private function swapPieces(Trip $trip): array
    {
        $segments = TripKmSplitService::segments($trip);

        // Chỉ có self-swap (cùng tài xế, cùng ca) → coi như chuyến không đảo lái
        if ($segments === []) {
            return [$this->wholeTripPiece($trip)];
        }

        $loadedRanges = TripKmSplitService::loadedRanges($trip);

        return array_map(function (array $segment) use ($trip, $loadedRanges): array {
            [$segStart, $segEnd, $segShiftId, $segDriverId] = $segment;

            $loaded = 0.0;
            foreach ($loadedRanges as [$loadStart, $loadEnd]) {
                $loaded += max(0.0, min((float) $segEnd, (float) $loadEnd) - max((float) $segStart, (float) $loadStart));
            }

            return [
                'trip' => $trip,
                'driver_id' => $segDriverId,
                'shift_id' => $segShiftId,
                'km_total' => max(0.0, (float) $segEnd - (float) $segStart),
                'km_loaded' => $loaded,
            ];
        }, $segments);
    }

    /**
     * Trích xuất các khoảng dừng hoạt động của các xe có chuyến trong kỳ bằng cursor (không tốn bộ nhớ).
     *
     * @param  Collection<int, Trip>  $trips
     * @return array<int, list<array{start: int, end: int, lat: float, lng: float}>>
     */
    private function extractVehicleStops(Collection $trips, Carbon $from, Carbon $to): array
    {
        $vehicleIds = $trips->pluck('vehicle_id')->filter()->unique()->values()->all();

        if ($vehicleIds === []) {
            return [];
        }

        $cursor = VehicleGpsLog::query()
            ->whereIn('vehicle_id', $vehicleIds)
            ->whereBetween('recorded_at', [$from->copy()->subHours(3), $to->copy()->addHours(3)])
            ->orderBy('vehicle_id')
            ->orderBy('recorded_at')
            ->toBase()
            ->select(['vehicle_id', 'lat', 'lng', 'speed', 'recorded_at'])
            ->cursor();

        $stopsByVehicle = [];
        $curVeh = null;
        $anchorLat = 0.0;
        $anchorLng = 0.0;
        $firstStopTs = null;
        $lastTs = null;
        $radiusSq = self::STOP_CLUSTER_RADIUS_M * self::STOP_CLUSTER_RADIUS_M;

        foreach ($cursor as $row) {
            $vid = (int) $row->vehicle_id;
            $lat = (float) $row->lat;
            $lng = (float) $row->lng;
            $spd = (float) $row->speed;
            $ts = strtotime((string) $row->recorded_at);

            if ($vid !== $curVeh) {
                if ($curVeh !== null && $firstStopTs !== null && ($lastTs - $firstStopTs) >= 60) {
                    $stopsByVehicle[$curVeh][] = ['start' => $firstStopTs, 'end' => $lastTs, 'lat' => $anchorLat, 'lng' => $anchorLng];
                }
                $curVeh = $vid;
                $anchorLat = $lat;
                $anchorLng = $lng;
                $firstStopTs = $spd <= 5.0 ? $ts : null;
                $lastTs = $ts;

                continue;
            }

            if ($spd < 10.0 && self::distanceSquaredMeters($lat, $lng, $anchorLat, $anchorLng) <= $radiusSq) {
                if ($firstStopTs === null && ($spd <= 5.0 || ($ts - $lastTs) >= 90)) {
                    $firstStopTs = $spd <= 5.0 ? $ts : $lastTs;
                }
                $lastTs = $ts;
            } else {
                // Nếu điểm trước đó đứng yên lâu (do EUP không ghi trùng khi xe dừng) rồi điểm này xe bắt đầu chạy đi
                $startCandidate = $firstStopTs ?? (($ts - $lastTs) >= 120 ? $lastTs : null);
                if ($startCandidate !== null) {
                    $endTs = ($ts - $lastTs) <= 180 || self::distanceSquaredMeters($lat, $lng, $anchorLat, $anchorLng) <= 400 * 400
                        ? $ts
                        : $lastTs;
                    if (($endTs - $startCandidate) >= 60) {
                        $stopsByVehicle[$curVeh][] = ['start' => $startCandidate, 'end' => $endTs, 'lat' => $anchorLat, 'lng' => $anchorLng];
                    }
                }
                $anchorLat = $lat;
                $anchorLng = $lng;
                $firstStopTs = $spd <= 5.0 ? $ts : null;
                $lastTs = $ts;
            }
        }

        if ($curVeh !== null && $firstStopTs !== null && ($lastTs - $firstStopTs) >= 60) {
            $stopsByVehicle[$curVeh][] = ['start' => $firstStopTs, 'end' => $lastTs, 'lat' => $anchorLat, 'lng' => $anchorLng];
        }

        return $stopsByVehicle;
    }

    /**
     * Tìm đầu mút đến (A_gps, C_gps) và đầu mút rời (B_gps, D_gps) thực tế của đơn hàng theo 3 điều kiện:
     * 1. Gần cắm mốc điểm nhận / hạ nhất.
     * 2. Gần thời gian bấm nút đến điểm nhận / hạ hàng nhất.
     * 3. Lấy mốc đầu mút và cuối mút của khoảng xe dừng hoạt động thỏa mãn mục 1 & 2.
     *
     * @param  array<string, mixed>  $row
     * @param  list<array{start: int, end: int, lat: float, lng: float}>  $vehicleStops
     * @return array{gps_time_a: ?Carbon, gps_time_b: ?Carbon, gps_time_c: ?Carbon, gps_time_d: ?Carbon, gps_loading: ?int, gps_unloading: ?int}
     */
    private function matchOrderGpsStops(
        Trip $trip,
        Order $order,
        array $row,
        ?Carbon $tripStartedAt,
        ?Carbon $tripEndedAt,
        array $vehicleStops,
    ): array {
        $empty = [
            'gps_time_a' => null,
            'gps_time_b' => null,
            'gps_time_c' => null,
            'gps_time_d' => null,
            'gps_loading' => null,
            'gps_unloading' => null,
        ];

        if ($vehicleStops === []) {
            return $empty;
        }

        $checkpoints = $order->tripCheckpoints->isNotEmpty() ? $order->tripCheckpoints : $trip->checkpoints;
        $cpA = $checkpoints->firstWhere('checkpoint_type', CheckpointType::ArrivedPickup);
        $cpB = $checkpoints->firstWhere('checkpoint_type', CheckpointType::LeftPickup);

        $timeA = $row['time_a'] instanceof Carbon ? $row['time_a'] : ($row['time_a'] ? Carbon::parse($row['time_a']) : $tripStartedAt);
        $timeB = $row['time_b'] instanceof Carbon ? $row['time_b'] : ($row['time_b'] ? Carbon::parse($row['time_b']) : $timeA);
        $timeC = $row['time_c'] instanceof Carbon ? $row['time_c'] : ($row['time_c'] ? Carbon::parse($row['time_c']) : null);
        $timeD = $row['time_d'] instanceof Carbon ? $row['time_d'] : ($row['time_d'] ? Carbon::parse($row['time_d']) : $tripEndedAt);

        $minSearchTs = min(
            array_filter([$tripStartedAt?->getTimestamp(), $timeA?->getTimestamp()]) ?: [0]
        ) - 1800; // -30 phút trước khi bắt đầu chuyến (tránh tính gộp thời gian xe đỗ qua đêm ở bãi)
        $maxSearchTs = max(
            array_filter([$tripEndedAt?->getTimestamp(), $timeD?->getTimestamp()]) ?: [PHP_INT_MAX]
        ) + 1200; // +20 phút sau khi kết thúc chuyến (tránh tính gộp thời gian xe nằm bãi sau chuyến)

        $windowStops = [];
        foreach ($vehicleStops as $stop) {
            if ($stop['end'] < $minSearchTs || $stop['start'] > $maxSearchTs) {
                continue;
            }
            $clampedStart = max($stop['start'], $minSearchTs);
            $clampedEnd = min($stop['end'], $maxSearchTs);
            if (($clampedEnd - $clampedStart) >= 60) {
                $windowStops[] = [
                    ...$stop,
                    'start' => $clampedStart,
                    'end' => $clampedEnd,
                ];
            }
        }

        if ($windowStops === []) {
            return $empty;
        }

        // 1. Điểm đóng hàng (Pickup)
        $pickupStop = $this->findBestStopForPoint(
            stops: $windowStops,
            location: $order->pickupLocation,
            fallbackLat: $cpA?->gps_lat !== null ? (float) $cpA->gps_lat : ($cpB?->gps_lat !== null ? (float) $cpB->gps_lat : null),
            fallbackLng: $cpA?->gps_lng !== null ? (float) $cpA->gps_lng : ($cpB?->gps_lng !== null ? (float) $cpB->gps_lng : null),
            buttonStartTs: $timeA?->getTimestamp(),
            buttonEndTs: $timeB?->getTimestamp(),
        );

        $gpsA = $pickupStop !== null ? Carbon::createFromTimestamp($pickupStop['start'], config('app.timezone')) : null;
        $gpsB = $pickupStop !== null ? Carbon::createFromTimestamp($pickupStop['end'], config('app.timezone')) : null;
        $gpsLoading = $pickupStop !== null ? (int) round(($pickupStop['end'] - $pickupStop['start']) / 60) : null;

        // 2. Các điểm hạ hàng (Delivery Points)
        $pickupAfterTs = $pickupStop['start'] ?? $minSearchTs;
        $deliveryCandidates = array_values(array_filter(
            $windowStops,
            fn (array $stop): bool => $stop['end'] > $pickupAfterTs
        ));

        $deliveryPoints = $order->deliveryPoints->sortBy('sequence')->values();
        $totalUnloading = 0;
        $hasUnloading = false;
        $firstGpsC = null;
        $lastGpsD = null;

        if ($deliveryPoints->isNotEmpty()) {
            foreach ($deliveryPoints as $dp) {
                $dpCheckpoints = $checkpoints->where('delivery_point_id', $dp->id);
                $cpC = $dpCheckpoints->firstWhere('checkpoint_type', CheckpointType::ArrivedDelivery)
                    ?? $checkpoints->firstWhere('checkpoint_type', CheckpointType::ArrivedDelivery);
                $cpD = $dpCheckpoints->where('checkpoint_type', CheckpointType::Completed)->last()
                    ?? $checkpoints->where('checkpoint_type', CheckpointType::Completed)->last();

                $dpStartTs = ($cpC?->effectiveOccurredAt() ?? $dp->arrived_at ?? $timeC)?->getTimestamp();
                $dpEndTs = ($cpD?->effectiveOccurredAt() ?? $dp->delivered_at ?? $timeD)?->getTimestamp();

                $delivStop = $this->findBestStopForPoint(
                    stops: $deliveryCandidates,
                    location: $dp->location,
                    fallbackLat: $cpC?->gps_lat !== null ? (float) $cpC->gps_lat : ($cpD?->gps_lat !== null ? (float) $cpD->gps_lat : null),
                    fallbackLng: $cpC?->gps_lng !== null ? (float) $cpC->gps_lng : ($cpD?->gps_lng !== null ? (float) $cpD->gps_lng : null),
                    buttonStartTs: $dpStartTs,
                    buttonEndTs: $dpEndTs,
                    excludeStartTs: $pickupStop['start'] ?? null,
                );

                if ($delivStop !== null) {
                    $cAt = Carbon::createFromTimestamp($delivStop['start'], config('app.timezone'));
                    $dAt = Carbon::createFromTimestamp($delivStop['end'], config('app.timezone'));
                    $firstGpsC ??= $cAt;
                    $lastGpsD = $dAt;
                    $totalUnloading += (int) round(($delivStop['end'] - $delivStop['start']) / 60);
                    $hasUnloading = true;
                }
            }
        }

        return [
            'gps_time_a' => $gpsA,
            'gps_time_b' => $gpsB,
            'gps_time_c' => $firstGpsC,
            'gps_time_d' => $lastGpsD,
            'gps_loading' => $gpsLoading,
            'gps_unloading' => $hasUnloading ? $totalUnloading : null,
        ];
    }

    /**
     * Lọc khoảng dừng theo tọa độ điểm nhận/hạ (Điều kiện 1) và thời điểm bấm nút (Điều kiện 2),
     * đồng thời gộp các khoảng dừng sát nhau trong cùng kho (xe dịch chuyển nội bộ giữa cổng và cửa kho).
     *
     * @param  list<array{start: int, end: int, lat: float, lng: float}>  $stops
     * @return array{start: int, end: int, lat: float, lng: float}|null
     */
    private function findBestStopForPoint(
        array $stops,
        ?Location $location,
        ?float $fallbackLat,
        ?float $fallbackLng,
        ?int $buttonStartTs,
        ?int $buttonEndTs,
        ?int $excludeStartTs = null,
    ): ?array {
        $candidates = [];

        if ($location !== null && $location->lat !== null && $location->lng !== null) {
            $locLat = (float) $location->lat;
            $locLng = (float) $location->lng;
            $radius = max(self::LOCATION_MATCH_RADIUS_M, (int) ($location->geofence_radius_m ?? 0));
            $candidates = $this->filterAndMergeNearbyStops($stops, $locLat, $locLng, $radius, $excludeStartTs);
        }

        // Dự phòng: nếu cắm mốc Location bị lệch xa thực tế (không có khoảng dừng nào quanh ghim kho) thì đối chiếu tọa độ GPS lúc bấm nút
        if ($candidates === [] && $fallbackLat !== null && $fallbackLng !== null && abs($fallbackLat) > 0.001 && abs($fallbackLng) > 0.001) {
            $candidates = $this->filterAndMergeNearbyStops($stops, $fallbackLat, $fallbackLng, 500, $excludeStartTs);
        }

        if ($candidates === []) {
            return null;
        }

        $refStart = $buttonStartTs ?? $buttonEndTs;
        $refEnd = $buttonEndTs ?? $buttonStartTs;

        // Ưu tiên các khoảng dừng thực sự tại kho (>= 2 phút) nếu có
        $substantial = array_values(array_filter($candidates, fn (array $s): bool => ($s['end'] - $s['start']) >= 120));
        $pool = $substantial !== [] ? $substantial : $candidates;

        usort($pool, function (array $a, array $b) use ($refStart, $refEnd): int {
            $gapA = self::intervalTimeGapSeconds($a['start'], $a['end'], $refStart, $refEnd);
            $gapB = self::intervalTimeGapSeconds($b['start'], $b['end'], $refStart, $refEnd);

            // Nếu cả 2 khoảng dừng đều sát thời điểm bấm nút (chênh nhau <= 15 phút), chọn khoảng dừng tại kho lâu hơn
            if (abs($gapA - $gapB) <= 900) {
                return ($b['end'] - $b['start']) <=> ($a['end'] - $a['start']);
            }

            return $gapA <=> $gapB;
        });

        return $pool[0];
    }

    /**
     * Lọc các khoảng dừng nằm trong bán kính quanh điểm mục tiêu và nối các khoảng dừng liên tiếp
     * tại cùng kho cách nhau <= 5 phút (xe lùi cửa kho / di chuyển nội bộ trong nhà máy).
     *
     * @param  list<array{start: int, end: int, lat: float, lng: float}>  $stops
     * @return list<array{start: int, end: int, lat: float, lng: float}>
     */
    private function filterAndMergeNearbyStops(
        array $stops,
        float $targetLat,
        float $targetLng,
        int $radiusMeters,
        ?int $excludeStartTs = null,
    ): array {
        $radiusSq = $radiusMeters * $radiusMeters;
        $matched = [];

        foreach ($stops as $stop) {
            if ($excludeStartTs !== null && $stop['start'] === $excludeStartTs) {
                continue;
            }
            if (self::distanceSquaredMeters($stop['lat'], $stop['lng'], $targetLat, $targetLng) <= $radiusSq) {
                $matched[] = $stop;
            }
        }

        if (count($matched) <= 1) {
            return $matched;
        }

        $merged = [$matched[0]];
        for ($i = 1, $n = count($matched); $i < $n; $i++) {
            $prevIdx = count($merged) - 1;
            $sameSpot = self::distanceSquaredMeters(
                $matched[$i]['lat'],
                $matched[$i]['lng'],
                $merged[$prevIdx]['lat'],
                $merged[$prevIdx]['lng'],
            ) <= 150 * 150;

            if ($sameSpot && ($matched[$i]['start'] - $merged[$prevIdx]['end']) <= 180) {
                $merged[$prevIdx]['end'] = max($merged[$prevIdx]['end'], $matched[$i]['end']);
            } else {
                $merged[] = $matched[$i];
            }
        }

        return $merged;
    }

    /**
     * Khoảng cách thời gian (giây) giữa khoảng dừng [stopStart, stopEnd] và khoảng bấm nút [btnStart, btnEnd].
     * Trả về 0 nếu hai khoảng giao nhau.
     */
    private static function intervalTimeGapSeconds(int $stopStart, int $stopEnd, ?int $btnStart, ?int $btnEnd): int
    {
        if ($btnStart === null || $btnEnd === null) {
            return 0;
        }
        $bMin = min($btnStart, $btnEnd);
        $bMax = max($btnStart, $btnEnd);

        if ($stopEnd >= $bMin && $stopStart <= $bMax) {
            return 0;
        }

        return $stopEnd < $bMin ? ($bMin - $stopEnd) : ($stopStart - $bMax);
    }

    /**
     * Bình phương khoảng cách phẳng xấp xỉ (m²) tại vĩ độ miền Bắc Việt Nam (~21°N) để lọc nhanh.
     */
    private static function distanceSquaredMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = ($lat1 - $lat2) * 111320.0;
        $dLng = ($lng1 - $lng2) * 103600.0;

        return ($dLat * $dLat) + ($dLng * $dLng);
    }
}
