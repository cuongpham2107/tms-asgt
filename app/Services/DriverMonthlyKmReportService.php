<?php

namespace App\Services;

use App\Enums\CheckpointType;
use App\Enums\OnDutyLocation;
use App\Enums\OrderStatus;
use App\Enums\TripStatus;
use App\Models\DriverShift;
use App\Models\DriverSwap;
use App\Models\Trip;
use App\Models\User;
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

        return app(TripExcelExportService::class)->getTripSummaryRows($trips)
            ->map(function (array $row) use ($tripsById): array {
                $checkpoints = $tripsById[$row['trip_id']]->checkpoints;

                return [
                    ...$row,
                    'driver_id' => $tripsById[$row['trip_id']]->driver_id,
                    'trip_started_at' => $checkpoints->firstWhere('checkpoint_type', CheckpointType::Started)?->effectiveOccurredAt(),
                    'trip_ended_at' => $checkpoints->where('checkpoint_type', CheckpointType::End)->last()?->effectiveOccurredAt(),
                ];
            })
            ->values();
    }

    /**
     * Đánh giá tài xế trên các dòng bảng kê: mỗi chuyến có thời gian hoàn thành (bắt đầu → kết thúc chuyến)
     * và thời gian chờ (đóng A→B + hạ C→D của các đơn), so với chuẩn tuyến = trung vị của cả đội trên cùng
     * tuyến trong kỳ. Chuyến có số liệu bất thường (thường do bấm checkpoint sai) bị gắn cờ, không tính vào
     * chuẩn tuyến lẫn trung bình của tài xế. Tuyến có dưới ROUTE_MIN_TRIPS chuyến hợp lệ thì không có chuẩn (null).
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

            return [
                ...$trip,
                'route_completion' => $standard['completion'] ?? null,
                'route_wait' => $standard['wait'] ?? null,
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
}
