<?php

namespace App\Services;

use App\Enums\CheckpointType;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Trip;
use App\Models\TripDriverAssignment;
use App\Services\Gps\DistanceResult;
use App\Services\Gps\GpsDistanceService;
use App\Services\Trip\TripLegService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Km chuyến / có hàng / không hàng / theo đơn / theo lượt lái, tính từ GPS theo khoảng thời gian.
 *
 * Có hàng = hợp các khoảng [đến điểm nhận, giao xong] của mọi đơn; không hàng = tổng − có hàng.
 * Km mỗi đơn (tính tiền khách) = quãng đường trong khoảng của riêng đơn đó.
 */
class TripKmCalculatorService
{
    public function __construct(
        private readonly GpsDistanceService $gps,
        private readonly TripLegService $legService,
    ) {}

    public function calculate(Trip $trip): void
    {
        $trip->loadMissing(['orders', 'checkpoints', 'driverAssignments']);

        $start = $trip->started_at?->toImmutable();
        $end = ($trip->completed_at ?? $trip->cancelled_at)?->toImmutable();

        if ($start === null || $end === null || $end->lte($start)) {
            return;
        }

        $segments = $this->driverSegments($trip, $start, $end);
        $orderIntervals = $this->orderIntervals($trip, $start, $end);
        $loadedUnion = $this->union($orderIntervals->flatten(1));

        $totalKm = 0.0;
        $loadedKm = 0.0;
        $coverageSeconds = 0.0;
        $sources = collect();
        $hasMocked = false;

        foreach ($segments as $segment) {
            $result = $this->measure($trip, $segment['from'], $segment['to'], $segment['driver_id']);
            $segmentLoaded = $this->measureWithin($trip, $loadedUnion, $segment);

            $totalKm += $result->km;
            $loadedKm += $segmentLoaded;
            $coverageSeconds += $result->coverage / 100 * $segment['from']->diffInSeconds($segment['to']);
            $hasMocked = $hasMocked || $result->hasMocked;
            if ($result->source !== null) {
                $sources->push($result->source);
            }

            if ($segment['assignment'] instanceof TripDriverAssignment) {
                $segment['assignment']->update([
                    'km' => round($result->km, 1),
                    'km_loaded' => round(min($segmentLoaded, $result->km), 1),
                    'km_empty' => round(max(0, $result->km - $segmentLoaded), 1),
                ]);
            }
        }

        foreach ($trip->orders as $order) {
            $intervals = $orderIntervals->get($order->id);
            if ($intervals === null) {
                continue;
            }

            $orderKm = $segments->sum(fn (array $segment) => $this->measureWithin($trip, $intervals, $segment));
            $order->loaded_km = round($orderKm, 1);
            $order->save();
        }

        // Fallback: nếu không có điểm GPS (như khi test tại bàn hoặc mất GPS), tính theo các chặng đường bộ OSRM
        if ($totalKm == 0.0) {
            $legs = $this->legService->calculateLegs($trip);
            if (! empty($legs)) {
                $osrmTotal = array_sum(array_column($legs, 'distance_km'));
                if ($osrmTotal > 0.0) {
                    $osrmLoaded = array_sum(array_map(fn ($l) => $l['is_loaded'] ? $l['distance_km'] : 0, $legs));
                    $totalKm = $osrmTotal;
                    $loadedKm = $osrmLoaded;
                    $sources->push('osrm');

                    foreach ($trip->orders as $order) {
                        if (($order->loaded_km ?? 0.0) == 0.0) {
                            $order->loaded_km = round($osrmLoaded, 1);
                            $order->save();
                        }
                    }

                    if ($trip->driverAssignments->isNotEmpty()) {
                        if ($trip->driverAssignments->count() === 1) {
                            $trip->driverAssignments->first()->update([
                                'km' => round($totalKm, 1),
                                'km_loaded' => round($loadedKm, 1),
                                'km_empty' => round(max(0, $totalKm - $loadedKm), 1),
                            ]);
                        } else {
                            // Phân bổ km từng chặng cho từng lượt lái (đảo lái)
                            foreach ($trip->driverAssignments as $da) {
                                $daStart = $this->later($da->started_at->toImmutable(), $start);
                                $daEnd = $this->earlier(($da->ended_at ?? $end)->toImmutable(), $end);

                                $daKm = 0.0;
                                $daLoaded = 0.0;

                                foreach ($legs as $leg) {
                                    $lFrom = ! empty($leg['from_time']) ? CarbonImmutable::parse($leg['from_time']) : null;
                                    $lTo = ! empty($leg['to_time']) ? CarbonImmutable::parse($leg['to_time']) : null;

                                    if ($lFrom && $lTo) {
                                        $midTime = $lFrom->addSeconds((int) round($lFrom->diffInSeconds($lTo) / 2));
                                        if ($midTime->gte($daStart) && $midTime->lte($daEnd)) {
                                            $daKm += (float) $leg['distance_km'];
                                            if ($leg['is_loaded']) {
                                                $daLoaded += (float) $leg['distance_km'];
                                            }
                                        }
                                    }
                                }

                                $da->update([
                                    'km' => round($daKm, 1),
                                    'km_loaded' => round($daLoaded, 1),
                                    'km_empty' => round(max(0, $daKm - $daLoaded), 1),
                                ]);
                            }
                        }
                    }
                }
            }
        }

        $loadedKm = min($loadedKm, $totalKm);
        $coverage = round($coverageSeconds / $start->diffInSeconds($end) * 100, 2);

        $trip->total_km = round($totalKm, 1);
        $trip->total_km_loaded = round($loadedKm, 1);
        $trip->total_km_empty = round(max(0, $totalKm - $loadedKm), 1);
        $trip->gps_coverage = $coverage;
        $trip->km_source = $this->combinedSource($sources);
        $trip->km_needs_review = $hasMocked || $coverage < config('gps.review_coverage_percent');
        $trip->km_calculated_at = now();
        $trip->save();

        $this->legService->syncLegs($trip);
    }

    /**
     * Các khoảng thời gian xe có hàng (hợp của mọi đơn), dùng cho bản đồ hành trình.
     *
     * @return Collection<int, array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    public function loadedIntervals(Trip $trip): Collection
    {
        $trip->loadMissing(['orders', 'checkpoints']);

        $start = $trip->started_at?->toImmutable();
        $end = ($trip->completed_at ?? $trip->cancelled_at ?? now())->toImmutable();

        if ($start === null || $end->lte($start)) {
            return collect();
        }

        return $this->union($this->orderIntervals($trip, $start, $end)->flatten(1));
    }

    /**
     * Chia thời gian chuyến theo lượt lái; khoảng không ai giữ chuyến (chờ đảo lái) tính theo xe.
     *
     * @return Collection<int, array{from: CarbonImmutable, to: CarbonImmutable, driver_id: ?int, assignment: ?TripDriverAssignment}>
     */
    private function driverSegments(Trip $trip, CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        $segments = collect();
        $cursor = $start;

        foreach ($trip->driverAssignments as $assignment) {
            $from = $this->later($assignment->started_at->toImmutable(), $start);
            $to = $this->earlier(($assignment->ended_at ?? $end)->toImmutable(), $end);

            if ($to->lte($from)) {
                continue;
            }

            if ($from->gt($cursor)) {
                $segments->push(['from' => $cursor, 'to' => $from, 'driver_id' => null, 'assignment' => null]);
            }

            $segments->push(['from' => $from, 'to' => $to, 'driver_id' => $assignment->driver_id, 'assignment' => $assignment]);
            $cursor = $this->later($cursor, $to);
        }

        if ($cursor->lt($end)) {
            $segments->push(['from' => $cursor, 'to' => $end, 'driver_id' => null, 'assignment' => null]);
        }

        return $segments;
    }

    /**
     * Khoảng có hàng của từng đơn, theo id đơn.
     * Quy tắc: từ Đến lấy hàng -> Hoàn thành là CÓ HÀNG, còn lại là KHÔNG HÀNG.
     *
     * @return Collection<int, Collection<int, array{0: CarbonImmutable, 1: CarbonImmutable}>>
     */
    private function orderIntervals(Trip $trip, CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        return $trip->orders
            ->mapWithKeys(function (Order $order) use ($trip, $start, $end) {
                $checkpoints = $trip->checkpoints->filter(fn ($cp) => $cp->order_id === null || $cp->order_id === $order->id);
                $pickedUpAt = $checkpoints->whereIn('checkpoint_type', [CheckpointType::ArrivedPickup, CheckpointType::LeftPickup])->min('occurred_at');
                $deliveredAt = $checkpoints->whereIn('checkpoint_type', [CheckpointType::Completed, CheckpointType::ArrivedDelivery])->max('occurred_at');

                if ($order->status === OrderStatus::Cancelled && $pickedUpAt === null) {
                    return [];
                }

                if ($pickedUpAt === null) {
                    return [];
                }

                $from = $pickedUpAt->toImmutable();
                $to = $deliveredAt?->toImmutable()
                    ?? ($order->status === OrderStatus::Cancelled ? $order->cancelled_at?->toImmutable() : null)
                    ?? $end;

                $from = $this->later($from, $start);
                $to = $this->earlier($to, $end);

                return $to->gt($from) ? [$order->id => collect([[$from, $to]])] : [];
            });
    }

    /**
     * @param  Collection<int, array{0: CarbonImmutable, 1: CarbonImmutable}>  $intervals
     * @return Collection<int, array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    private function union(Collection $intervals): Collection
    {
        $merged = collect();

        foreach ($intervals->sortBy(fn (array $i) => $i[0]->getTimestamp()) as [$from, $to]) {
            $last = $merged->pop();

            if ($last !== null && $from->lte($last[1])) {
                $merged->push([$last[0], $this->later($last[1], $to)]);
            } else {
                if ($last !== null) {
                    $merged->push($last);
                }
                $merged->push([$from, $to]);
            }
        }

        return $merged;
    }

    /**
     * Tổng km của các khoảng sau khi cắt theo một lượt lái.
     *
     * @param  Collection<int, array{0: CarbonImmutable, 1: CarbonImmutable}>  $intervals
     * @param  array{from: CarbonImmutable, to: CarbonImmutable, driver_id: ?int}  $segment
     */
    private function measureWithin(Trip $trip, Collection $intervals, array $segment): float
    {
        return $intervals->sum(function (array $interval) use ($trip, $segment) {
            $from = $this->later($interval[0], $segment['from']);
            $to = $this->earlier($interval[1], $segment['to']);

            return $to->gt($from) ? $this->measure($trip, $from, $to, $segment['driver_id'])->km : 0.0;
        });
    }

    private function measure(Trip $trip, CarbonImmutable $from, CarbonImmutable $to, ?int $driverId): DistanceResult
    {
        return $this->gps->distance($trip->vehicle_id, $from, $to, $driverId);
    }

    /**
     * @param  Collection<int, string>  $sources
     */
    private function combinedSource(Collection $sources): ?string
    {
        $unique = $sources->unique()->values();

        return match ($unique->count()) {
            0 => null,
            1 => $unique->first(),
            default => DistanceResult::SOURCE_MIXED,
        };
    }

    private function later(CarbonImmutable $a, CarbonImmutable $b): CarbonImmutable
    {
        return $a->gt($b) ? $a : $b;
    }

    private function earlier(CarbonImmutable $a, CarbonImmutable $b): CarbonImmutable
    {
        return $a->lt($b) ? $a : $b;
    }
}
