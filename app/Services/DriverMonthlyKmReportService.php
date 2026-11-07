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
    /** Một lần chờ (đóng + hạ) dài hơn ngưỡng này bị tính là chờ lâu. */
    public const LONG_WAIT_MINUTES = 120;

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
     * Đánh giá từng lái xe trên các dòng bảng kê: thời gian chạy (B→C), thời gian chờ (đóng A→B + hạ C→D), tốc độ.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    public function driverPerformance(Collection $rows): Collection
    {
        return $rows
            ->groupBy('driver_id')
            ->map(fn (Collection $driverRows, int|string $driverId): array => [
                'driver_id' => (int) $driverId,
                'driver_name' => $driverRows->first()['driver_name'],
                ...$this->performance($driverRows),
            ])
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array{trip_count: int, order_count: int, km_total: float, avg_travel: ?int, avg_wait: ?int, wait_percent: int, avg_speed: ?float, long_wait_count: int}
     */
    public function performance(Collection $rows): array
    {
        // Chuyến không hàng chỉ có km rỗng; dòng có đơn tính km có hàng của đơn (giống trang Bảng kê)
        $rowKm = fn (array $row): float => (float) ($row['order_id'] === null ? $row['km_empty'] : $row['km_loaded']);
        $travels = $rows->pluck('time_travel')->reject(fn (?int $minutes): bool => $minutes === null);
        $waits = $rows->map(fn (array $row): ?int => self::waitMinutes($row))->reject(fn (?int $minutes): bool => $minutes === null);
        $travelMinutes = $travels->sum();
        $busyMinutes = $travelMinutes + $waits->sum();

        return [
            'trip_count' => $rows->pluck('trip_id')->unique()->count(),
            'order_count' => $rows->whereNotNull('order_id')->count(),
            'km_total' => round($rows->sum($rowKm), 1),
            'avg_travel' => $travels->isEmpty() ? null : (int) round($travels->avg()),
            'avg_wait' => $waits->isEmpty() ? null : (int) round($waits->avg()),
            'wait_percent' => $busyMinutes > 0 ? (int) round($waits->sum() / $busyMinutes * 100) : 0,
            'avg_speed' => $travelMinutes > 0 ? round($rows->sum($rowKm) / ($travelMinutes / 60), 1) : null,
            'long_wait_count' => $waits->filter(fn (int $minutes): bool => $minutes > self::LONG_WAIT_MINUTES)->count(),
        ];
    }

    /**
     * Thời gian chờ của một dòng = TG đóng (A→B) + TG hạ (C→D); null khi chưa có mốc nào.
     *
     * @param  array<string, mixed>  $row
     */
    public static function waitMinutes(array $row): ?int
    {
        if ($row['time_loading'] === null && $row['time_unloading'] === null) {
            return null;
        }

        return (int) $row['time_loading'] + (int) $row['time_unloading'];
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
