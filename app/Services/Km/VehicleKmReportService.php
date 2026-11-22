<?php

namespace App\Services\Km;

use App\Enums\TripStatus;
use App\Models\TripCheckpoint;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Km đồng hồ của xe theo thời gian, lấy từ số km lái xe nhập ở các mốc chuyến (bỏ chuyến đã huỷ).
 * Thời điểm của mốc là giờ đã hiệu chỉnh nếu có, ngược lại là giờ xảy ra.
 */
class VehicleKmReportService
{
    private const EFFECTIVE_AT = 'COALESCE(trip_checkpoints.adjusted_at, trip_checkpoints.occurred_at)';

    /**
     * Km đầu kỳ, cuối kỳ và km đã chạy của từng xe trong khoảng [from, to].
     * Đầu kỳ là số km mới nhất tại hoặc trước `from`; xe chưa có số nào trước đó thì lấy số đầu tiên trong kỳ.
     *
     * @param  list<int>  $vehicleIds
     * @return array<int, array{start_km: ?float, start_at: ?Carbon, start_in_range: bool, end_km: ?float, end_at: ?Carbon, km_run: ?float, trip_count: int}>
     */
    public function summarize(array $vehicleIds, Carbon $from, Carbon $to): array
    {
        if ($vehicleIds === []) {
            return [];
        }

        $before = $this->latestReadings($vehicleIds, $from);
        $atEnd = $this->latestReadings($vehicleIds, $to);
        $firstInRange = $this->firstReadingsInRange($vehicleIds, $from, $to);
        $tripCounts = $this->readings($vehicleIds)
            ->whereRaw(self::EFFECTIVE_AT.' between ? and ?', [$from->toDateTimeString(), $to->toDateTimeString()])
            ->groupBy('trips.vehicle_id')
            ->selectRaw('trips.vehicle_id, count(distinct trip_checkpoints.trip_id) as trip_count')
            ->pluck('trip_count', 'vehicle_id');

        $result = [];

        foreach ($vehicleIds as $vehicleId) {
            $start = $before[$vehicleId] ?? $firstInRange[$vehicleId] ?? null;
            $end = $atEnd[$vehicleId] ?? null;

            $result[$vehicleId] = [
                'start_km' => $start['km'] ?? null,
                'start_at' => $start['at'] ?? null,
                'start_in_range' => ! isset($before[$vehicleId]) && $start !== null,
                'end_km' => $end['km'] ?? null,
                'end_at' => $end['at'] ?? null,
                'km_run' => ($start !== null && $end !== null) ? round($end['km'] - $start['km'], 1) : null,
                'trip_count' => (int) ($tripCounts[$vehicleId] ?? 0),
            ];
        }

        return $result;
    }

    /**
     * Số km mới nhất của từng xe tại hoặc trước thời điểm cho trước.
     *
     * @param  list<int>  $vehicleIds
     * @return array<int, array{km: float, at: Carbon}>
     */
    public function latestReadings(array $vehicleIds, Carbon $at): array
    {
        $latestTimes = $this->readings($vehicleIds)
            ->whereRaw(self::EFFECTIVE_AT.' <= ?', [$at->toDateTimeString()])
            ->groupBy('trips.vehicle_id')
            ->selectRaw('trips.vehicle_id, max('.self::EFFECTIVE_AT.') as effective_at')
            ->pluck('effective_at', 'vehicle_id');

        return $this->readingsAtTimes($latestTimes->all(), pickLast: true);
    }

    /**
     * @param  list<int>  $vehicleIds
     * @return array<int, array{km: float, at: Carbon}>
     */
    private function firstReadingsInRange(array $vehicleIds, Carbon $from, Carbon $to): array
    {
        $firstTimes = $this->readings($vehicleIds)
            ->whereRaw(self::EFFECTIVE_AT.' between ? and ?', [$from->toDateTimeString(), $to->toDateTimeString()])
            ->groupBy('trips.vehicle_id')
            ->selectRaw('trips.vehicle_id, min('.self::EFFECTIVE_AT.') as effective_at')
            ->pluck('effective_at', 'vehicle_id');

        return $this->readingsAtTimes($firstTimes->all(), pickLast: false);
    }

    /**
     * Lấy số km của từng xe tại đúng thời điểm đã chọn; nhiều mốc cùng giây thì lấy mốc tạo sau cùng (hoặc đầu tiên).
     *
     * @param  array<int, string>  $timesByVehicle
     * @return array<int, array{km: float, at: Carbon}>
     */
    private function readingsAtTimes(array $timesByVehicle, bool $pickLast): array
    {
        if ($timesByVehicle === []) {
            return [];
        }

        $wanted = collect($timesByVehicle)->map(fn (string $time): string => Carbon::parse($time)->toDateTimeString());

        $rows = $this->readings(array_keys($timesByVehicle))
            ->whereIn(DB::raw(self::EFFECTIVE_AT), $wanted->unique()->values()->all())
            ->orderBy('trip_checkpoints.id')
            ->get([
                'trips.vehicle_id',
                'trip_checkpoints.km_reading',
                DB::raw(self::EFFECTIVE_AT.' as effective_at'),
            ]);

        $result = [];

        foreach ($rows as $row) {
            $at = Carbon::parse($row->effective_at);

            if ($wanted->get($row->vehicle_id) !== $at->toDateTimeString()) {
                continue;
            }

            if ($pickLast || ! isset($result[$row->vehicle_id])) {
                $result[$row->vehicle_id] = ['km' => (float) $row->km_reading, 'at' => $at];
            }
        }

        return $result;
    }

    /**
     * Mốc có km của các xe, bỏ chuyến đã huỷ.
     *
     * @param  list<int>  $vehicleIds
     * @return Builder<TripCheckpoint>
     */
    private function readings(array $vehicleIds): Builder
    {
        return TripCheckpoint::query()
            ->join('trips', 'trips.id', '=', 'trip_checkpoints.trip_id')
            ->whereIn('trips.vehicle_id', $vehicleIds)
            ->where('trips.status', '!=', TripStatus::Cancelled->value)
            ->whereNotNull('trip_checkpoints.km_reading');
    }
}
