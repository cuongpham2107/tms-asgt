<?php

namespace App\Services\Trip;

use App\Enums\CheckpointType;
use App\Models\Trip;
use App\Models\TripCheckpoint;
use App\Models\TripLeg;
use App\Services\Gps\GpsDistanceService;
use App\Services\OsrmService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Bóc tách các chặng (legs) và tính khoảng cách từng chặng trong chuyến xe.
 *
 * Mỗi chặng gồm điểm đi, điểm đến, trạng thái (có hàng / xe rỗng), và số km.
 * Ưu tiên tính từ GPS thực tế (GpsDistanceService); nếu không có GPS (khi test tại bàn hoặc mất GPS),
 * tự động fallback sang khoảng cách đường bộ thực tế qua OSRM Routing.
 * Dữ liệu các chặng được lưu vào bảng trip_legs để điều hành có thể đối soát và điều chỉnh.
 */
class TripLegService
{
    private const EARTH_RADIUS_M = 6371000;

    public function __construct(
        private readonly GpsDistanceService $gpsDistanceService,
        private readonly OsrmService $osrmService,
    ) {}

    /**
     * Danh sách các chặng di chuyển của chuyến theo từng mốc hành trình liên tiếp.
     * (Bắt đầu -> Đến lấy hàng -> Rời lấy hàng -> Đến giao hàng -> Hoàn thành -> Kết thúc).
     * Đọc từ bảng trip_legs nếu đã lưu (ưu tiên số km đã điều chỉnh).
     *
     * @return array<int, array{
     *     id?: int,
     *     leg_index: int,
     *     from_checkpoint_id?: ?int,
     *     to_checkpoint_id?: ?int,
     *     from_name: string,
     *     to_name: string,
     *     from_time: ?string,
     *     to_time: ?string,
     *     distance_km: float,
     *     original_distance_km?: float,
     *     distance_adjusted_km?: ?float,
     *     is_adjusted?: bool,
     *     is_loaded: bool,
     *     source: string,
     *     driver_id?: ?int,
     *     adjust_reason?: ?string,
     *     adjusted_by?: ?int,
     *     adjusted_at?: ?string,
     * }>
     */
    public function calculateLegs(Trip $trip, bool $forceRecalculate = false): array
    {
        $trip->loadMissing([
            'vehicle',
            'startLocation',
            'endLocation',
            'orders.pickupLocation',
            'orders.deliveryPoints.location',
            'checkpoints' => fn ($q) => $q->with(['driver', 'deliveryPoint.location', 'order.pickupLocation'])->orderBy('occurred_at'),
            'legs',
        ]);

        if (! $trip->exists) {
            return $this->computeRawLegs($trip);
        }

        $checkpoints = $trip->checkpoints->sortBy('occurred_at')->values();
        $expectedLegCount = $checkpoints->count() >= 2 ? $checkpoints->count() - 1 : 0;
        $existingLegs = $trip->legs;

        if ($forceRecalculate || $existingLegs->isEmpty() || ($expectedLegCount > 0 && $existingLegs->count() < $expectedLegCount)) {
            $existingLegs = $this->syncLegs($trip);
        }

        if ($existingLegs->isEmpty()) {
            return [];
        }

        return $existingLegs->map(fn (TripLeg $leg): array => [
            'id' => $leg->id,
            'leg_index' => $leg->leg_index,
            'from_checkpoint_id' => $leg->from_checkpoint_id,
            'to_checkpoint_id' => $leg->to_checkpoint_id,
            'from_name' => $leg->from_name,
            'to_name' => $leg->to_name,
            'from_time' => $leg->from_time?->toIso8601String(),
            'to_time' => $leg->to_time?->toIso8601String(),
            'distance_km' => (float) ($leg->distance_adjusted_km ?? $leg->distance_km),
            'original_distance_km' => (float) $leg->distance_km,
            'distance_adjusted_km' => $leg->distance_adjusted_km !== null ? (float) $leg->distance_adjusted_km : null,
            'is_adjusted' => $leg->distance_adjusted_km !== null,
            'is_loaded' => (bool) $leg->is_loaded,
            'source' => $leg->source ?? 'unknown',
            'driver_id' => $leg->driver_id,
            'adjust_reason' => $leg->adjust_reason,
            'adjusted_by' => $leg->adjusted_by,
            'adjusted_at' => $leg->adjusted_at?->toIso8601String(),
        ])->values()->toArray();
    }

    /**
     * Tính toán thô các chặng hành trình từ checkpoints hoặc toạ độ kế hoạch.
     *
     * @return array<int, array{
     *     leg_index: int,
     *     from_checkpoint_id?: ?int,
     *     to_checkpoint_id?: ?int,
     *     from_name: string,
     *     to_name: string,
     *     from_time: ?string,
     *     to_time: ?string,
     *     distance_km: float,
     *     is_loaded: bool,
     *     source: string,
     *     driver_id?: ?int,
     * }>
     */
    public function computeRawLegs(Trip $trip): array
    {
        $trip->loadMissing([
            'vehicle',
            'startLocation',
            'endLocation',
            'orders.pickupLocation',
            'orders.deliveryPoints.location',
            'checkpoints' => fn ($q) => $q->with(['driver', 'deliveryPoint.location', 'order.pickupLocation'])->orderBy('occurred_at'),
        ]);

        $checkpoints = $trip->checkpoints->sortBy('occurred_at')->values();

        if ($checkpoints->count() >= 2) {
            return $this->calculateCheckpointLegs($trip, $checkpoints);
        }

        if ($checkpoints->isEmpty() && $trip->started_at === null) {
            return [];
        }

        return $this->calculatePlannedLegs($trip, $checkpoints);
    }

    /**
     * Đồng bộ và lưu trữ các chặng của chuyến xe vào bảng trip_legs.
     * Bảo toàn khoảng cách đã được điều hành chỉnh sửa (distance_adjusted_km).
     *
     * @return Collection<int, TripLeg>
     */
    public function syncLegs(Trip $trip): Collection
    {
        if (! $trip->exists) {
            return collect();
        }

        $rawLegs = $this->computeRawLegs($trip);

        if (empty($rawLegs)) {
            return collect();
        }

        $synced = collect();

        foreach ($rawLegs as $raw) {
            $leg = TripLeg::firstOrNew([
                'trip_id' => $trip->id,
                'leg_index' => $raw['leg_index'],
            ]);

            $leg->from_name = $raw['from_name'];
            $leg->to_name = $raw['to_name'];
            $leg->from_checkpoint_id = $raw['from_checkpoint_id'] ?? null;
            $leg->to_checkpoint_id = $raw['to_checkpoint_id'] ?? null;
            $leg->from_time = ! empty($raw['from_time']) ? CarbonImmutable::parse($raw['from_time']) : null;
            $leg->to_time = ! empty($raw['to_time']) ? CarbonImmutable::parse($raw['to_time']) : null;
            $leg->distance_km = $raw['distance_km'];
            $leg->is_loaded = (bool) $raw['is_loaded'];
            $leg->source = $raw['source'];
            $leg->driver_id = $raw['driver_id'] ?? null;
            $leg->save();

            $synced->push($leg);
        }

        TripLeg::where('trip_id', $trip->id)
            ->where('leg_index', '>', count($rawLegs))
            ->delete();

        $trip->unsetRelation('legs');

        return $synced;
    }

    /**
     * Điều hành cập nhật khoảng cách điều chỉnh cho từng chặng.
     * Tự động tính lại tổng km, km có hàng / xe rỗng cho Trip và các lượt lái.
     *
     * @param  array<int, array{id?: int, leg_index?: int, distance_adjusted_km?: mixed, adjust_reason?: ?string}>  $adjustments
     */
    public function adjustLegs(Trip $trip, array $adjustments, string $generalReason, int $userId): void
    {
        $this->syncLegs($trip);
        $legs = $trip->legs()->get();

        $hasAnyAdjustment = false;

        foreach ($adjustments as $adj) {
            $legId = $adj['id'] ?? null;
            $legIndex = $adj['leg_index'] ?? null;

            /** @var TripLeg|null $leg */
            $leg = ($legId ? $legs->firstWhere('id', $legId) : null)
                ?? ($legIndex ? $legs->firstWhere('leg_index', $legIndex) : null);

            if (! $leg) {
                continue;
            }

            $adjVal = $adj['distance_adjusted_km'] ?? null;

            if ($adjVal !== null && $adjVal !== '') {
                $leg->distance_adjusted_km = round((float) $adjVal, 1);
                $leg->adjust_reason = ! empty($adj['adjust_reason']) ? $adj['adjust_reason'] : $generalReason;
                $leg->adjusted_by = $userId;
                $leg->adjusted_at = now();
                $hasAnyAdjustment = true;
            } else {
                $leg->distance_adjusted_km = null;
                $leg->adjust_reason = null;
                $leg->adjusted_by = null;
                $leg->adjusted_at = null;
            }

            $leg->save();
        }

        // Tải lại danh sách chặng sau khi sửa để tính tổng km chuyến
        $freshLegs = $trip->legs()->get();
        $totalKm = round($freshLegs->sum(fn (TripLeg $l) => $l->effective_distance_km), 1);
        $loadedKm = round($freshLegs->where('is_loaded', true)->sum(fn (TripLeg $l) => $l->effective_distance_km), 1);
        $emptyKm = round(max(0, $totalKm - $loadedKm), 1);

        $trip->km_adjusted = $hasAnyAdjustment ? $totalKm : ($trip->km_adjusted ?? $totalKm);
        $trip->km_adjusted_loaded = $hasAnyAdjustment ? $loadedKm : ($trip->km_adjusted_loaded ?? $loadedKm);
        $trip->km_adjust_reason = $generalReason;
        $trip->km_adjusted_by = $userId;
        $trip->km_needs_review = false;
        $trip->save();

        // Cập nhật lượt lái của tài xế
        $trip->loadMissing('driverAssignments');
        if ($trip->driverAssignments->isNotEmpty()) {
            if ($trip->driverAssignments->count() === 1) {
                $trip->driverAssignments->first()->update([
                    'km' => $totalKm,
                    'km_loaded' => $loadedKm,
                    'km_empty' => $emptyKm,
                ]);
            } else {
                $tripStart = $trip->started_at?->toImmutable() ?? now()->toImmutable();
                $tripEnd = ($trip->completed_at ?? $trip->cancelled_at ?? now())->toImmutable();

                foreach ($trip->driverAssignments as $da) {
                    $daStart = $da->started_at ? $da->started_at->toImmutable() : $tripStart;
                    $daEnd = $da->ended_at ? $da->ended_at->toImmutable() : $tripEnd;

                    $daKm = 0.0;
                    $daLoaded = 0.0;

                    foreach ($freshLegs as $leg) {
                        $isDaLeg = false;
                        if ($leg->driver_id !== null && $leg->driver_id === $da->driver_id) {
                            $isDaLeg = true;
                        } elseif ($leg->from_time && $leg->to_time) {
                            $midTime = $leg->from_time->addSeconds((int) round($leg->from_time->diffInSeconds($leg->to_time) / 2));
                            if ($midTime->gte($daStart) && $midTime->lte($daEnd)) {
                                $isDaLeg = true;
                            }
                        }

                        if ($isDaLeg) {
                            $daKm += $leg->effective_distance_km;
                            if ($leg->is_loaded) {
                                $daLoaded += $leg->effective_distance_km;
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

    /**
     * Điều hành chỉnh thẳng TỔNG km chuyến (không sửa từng chặng).
     * Ghi vào km_adjusted (giữ GPS gốc) và phân bổ xuống từng lượt lái theo tỉ lệ km GPS gốc,
     * để app tài xế, km ca và thống kê đều ăn số đã điều chỉnh.
     */
    public function applyTotalAdjustment(Trip $trip, float $totalKm, float $loadedKm, string $reason, int $userId): void
    {
        $totalKm = round($totalKm, 1);
        $loadedKm = round(min($loadedKm, $totalKm), 1);

        $trip->km_adjusted = $totalKm;
        $trip->km_adjusted_loaded = $loadedKm;
        $trip->km_adjust_reason = $reason;
        $trip->km_adjusted_by = $userId;
        $trip->km_needs_review = false;
        $trip->save();

        $trip->loadMissing('driverAssignments');
        $assignments = $trip->driverAssignments;

        if ($assignments->isEmpty()) {
            return;
        }

        if ($assignments->count() === 1) {
            $assignments->first()->update([
                'km' => $totalKm,
                'km_loaded' => $loadedKm,
                'km_empty' => round(max(0, $totalKm - $loadedKm), 1),
            ]);

            return;
        }

        // Phân bổ theo tỉ lệ km GPS gốc của từng lượt; nếu GPS gốc = 0 thì chia đều.
        $gpsTotal = (float) $assignments->sum('km');
        $gpsLoaded = (float) $assignments->sum('km_loaded');
        $count = $assignments->count();

        foreach ($assignments as $assignment) {
            $kmShare = $gpsTotal > 0 ? (float) $assignment->km / $gpsTotal : 1.0 / $count;
            $loadedShare = $gpsLoaded > 0 ? (float) $assignment->km_loaded / $gpsLoaded : $kmShare;

            $daKm = round($totalKm * $kmShare, 1);
            $daLoaded = round(min($loadedKm * $loadedShare, $daKm), 1);

            $assignment->update([
                'km' => $daKm,
                'km_loaded' => $daLoaded,
                'km_empty' => round(max(0, $daKm - $daLoaded), 1),
            ]);
        }
    }

    /**
     * @param  Collection<int, TripCheckpoint>  $checkpoints
     * @return array<int, array{
     *     leg_index: int,
     *     from_checkpoint_id?: ?int,
     *     to_checkpoint_id?: ?int,
     *     from_name: string,
     *     to_name: string,
     *     from_time: ?string,
     *     to_time: ?string,
     *     distance_km: float,
     *     is_loaded: bool,
     *     source: string,
     *     driver_id?: ?int,
     * }>
     */
    private function calculateCheckpointLegs(Trip $trip, Collection $checkpoints): array
    {
        $legs = [];

        for ($i = 1; $i < $checkpoints->count(); $i++) {
            /** @var TripCheckpoint $prevCp */
            $prevCp = $checkpoints[$i - 1];
            /** @var TripCheckpoint $currCp */
            $currCp = $checkpoints[$i];

            $fromName = $this->formatCheckpointName($prevCp, $trip);
            $toName = $this->formatCheckpointName($currCp, $trip);

            $fromTime = $prevCp->occurred_at ? CarbonImmutable::parse($prevCp->occurred_at) : null;
            $toTime = $currCp->occurred_at ? CarbonImmutable::parse($currCp->occurred_at) : null;

            $prevLoc = $this->resolveCheckpointCoords($prevCp, $trip);
            $currLoc = $this->resolveCheckpointCoords($currCp, $trip);

            $driverId = $currCp->driver_id ?? $prevCp->driver_id ?? $trip->driver_id;

            $distResult = $this->measureDistance(
                $trip,
                $fromTime,
                $toTime,
                $prevLoc['lat'],
                $prevLoc['lng'],
                $currLoc['lat'],
                $currLoc['lng'],
                $driverId
            );

            $isLoaded = $this->isLegLoaded($prevCp, $currCp, $checkpoints);

            $legs[] = [
                'leg_index' => count($legs) + 1,
                'from_checkpoint_id' => $prevCp->id,
                'to_checkpoint_id' => $currCp->id,
                'from_name' => $fromName,
                'to_name' => $toName,
                'from_time' => $fromTime?->toIso8601String(),
                'to_time' => $toTime?->toIso8601String(),
                'distance_km' => $distResult['km'],
                'is_loaded' => $isLoaded,
                'source' => $distResult['source'],
                'driver_id' => $driverId,
            ];
        }

        return $legs;
    }

    /**
     * Định dạng tên hiển thị cho mốc hành trình (loại mốc + mã/tên địa điểm nếu có).
     */
    public function formatCheckpointName(TripCheckpoint $cp, Trip $trip): string
    {
        $label = $cp->checkpoint_type->getLabel();

        if ($cp->checkpoint_type === CheckpointType::DriverSwap) {
            $driverName = $cp->driver?->name;

            return $driverName ? "{$label} ({$driverName})" : $label;
        }

        $loc = $cp->deliveryPoint?->location ?? $cp->order?->pickupLocation;
        $locName = $loc?->code ?: $loc?->name;

        if (! $locName) {
            if ($cp->checkpoint_type === CheckpointType::Started && $trip->startLocation) {
                $locName = $trip->startLocation->code ?: $trip->startLocation->name;
            } elseif ($cp->checkpoint_type === CheckpointType::End && $trip->endLocation) {
                $locName = $trip->endLocation->code ?: $trip->endLocation->name;
            }
        }

        return $locName ? "{$label} ({$locName})" : $label;
    }

    /**
     * Xác định xem chặng giữa 2 checkpoint có chở hàng hay không.
     * Quy tắc: Từ Đến lấy hàng -> Hoàn thành là CÓ HÀNG, còn lại là KHÔNG HÀNG.
     *
     * @param  Collection<int, TripCheckpoint>  $allCheckpoints
     */
    private function isLegLoaded(TripCheckpoint $prevCp, TripCheckpoint $currCp, Collection $allCheckpoints): bool
    {
        // Chặng trước Đến lấy hàng (ví dụ: Bắt đầu chuyến -> Đến lấy hàng): KHÔNG HÀNG
        if ($prevCp->checkpoint_type === CheckpointType::Started) {
            return false;
        }

        // Nếu điểm trước là DriverSwap:
        // Đảo lái trong khoảng từ Đến lấy hàng -> Hoàn thành => CÓ HÀNG
        // Đảo lái ngoài khoảng này (trước lấy hàng hoặc sau hoàn thành) => KHÔNG HÀNG
        if ($prevCp->checkpoint_type === CheckpointType::DriverSwap) {
            $hasArrivedPickupBefore = $allCheckpoints
                ->filter(fn (TripCheckpoint $cp) => $cp->occurred_at <= $prevCp->occurred_at)
                ->filter(fn (TripCheckpoint $cp) => in_array($cp->checkpoint_type, [CheckpointType::ArrivedPickup, CheckpointType::LeftPickup], true))
                ->isNotEmpty();

            $hasRemainingDeliveries = $allCheckpoints
                ->filter(fn (TripCheckpoint $cp) => $cp->occurred_at >= $currCp->occurred_at)
                ->filter(fn (TripCheckpoint $cp) => in_array($cp->checkpoint_type, [CheckpointType::ArrivedDelivery, CheckpointType::Completed], true))
                ->isNotEmpty();

            return $hasArrivedPickupBefore && $hasRemainingDeliveries;
        }

        // Từ Đến lấy hàng -> Hoàn thành (Đến lấy hàng -> Rời lấy hàng, Rời lấy hàng -> Đến giao hàng, Đến giao hàng -> Hoàn thành): CÓ HÀNG
        if (in_array($prevCp->checkpoint_type, [
            CheckpointType::ArrivedPickup,
            CheckpointType::LeftPickup,
            CheckpointType::ArrivedDelivery,
        ], true)) {
            return true;
        }

        // Nếu điểm trước là Completed: kiểm tra xem phía sau còn điểm giao hàng / completed nào nữa không (trường hợp nhiều điểm giao)
        if ($prevCp->checkpoint_type === CheckpointType::Completed) {
            $remainingDeliveries = $allCheckpoints
                ->filter(fn (TripCheckpoint $cp) => $cp->occurred_at > $prevCp->occurred_at)
                ->filter(fn (TripCheckpoint $cp) => in_array($cp->checkpoint_type, [CheckpointType::ArrivedDelivery, CheckpointType::Completed], true));

            return $remainingDeliveries->isNotEmpty();
        }

        return false;
    }

    /**
     * Tính khoảng cách giữa mỗi cặp checkpoint liên tiếp theo thứ tự thời gian.
     * Dùng để hiển thị badge "+ X.X km" trên timeline giữa 2 mốc.
     *
     * @return array<int, array{
     *     checkpoint_id: int,
     *     distance_from_prev_km: float,
     *     distance_km: float,
     *     is_loaded: bool,
     *     source: string,
     * }>
     */
    public function checkpointDistances(Trip $trip): array
    {
        $legs = $this->calculateLegs($trip);
        $checkpoints = $trip->checkpoints->sortBy('occurred_at')->values();

        $result = [];
        foreach ($legs as $idx => $leg) {
            $cp = $checkpoints[$idx + 1] ?? null;
            if ($cp) {
                $result[$cp->id] = [
                    'checkpoint_id' => $cp->id,
                    'distance_from_prev_km' => $leg['distance_km'],
                    'distance_km' => $leg['distance_km'],
                    'original_distance_km' => $leg['original_distance_km'] ?? $leg['distance_km'],
                    'distance_adjusted_km' => $leg['distance_adjusted_km'] ?? null,
                    'is_adjusted' => $leg['is_adjusted'] ?? false,
                    'is_loaded' => $leg['is_loaded'],
                    'source' => $leg['source'],
                ];
            }
        }

        return $result;
    }

    /**
     * Dự kiến các chặng khi chuyến chưa có đủ 2 checkpoint.
     *
     * @param  Collection<int, TripCheckpoint>  $checkpoints
     * @return array<int, array{
     *     leg_index: int,
     *     from_checkpoint_id?: ?int,
     *     to_checkpoint_id?: ?int,
     *     from_name: string,
     *     to_name: string,
     *     from_time: ?string,
     *     to_time: ?string,
     *     distance_km: float,
     *     is_loaded: bool,
     *     source: string,
     *     driver_id?: ?int,
     * }>
     */
    private function calculatePlannedLegs(Trip $trip, Collection $checkpoints): array
    {
        $stops = $this->extractKeyStops($trip, $checkpoints);

        if (count($stops) < 2) {
            return [];
        }

        $legs = [];
        for ($i = 1; $i < count($stops); $i++) {
            $prev = $stops[$i - 1];
            $curr = $stops[$i];

            if ($this->isSameLocation($prev, $curr)) {
                continue;
            }

            $driverId = $curr['driver_id'] ?? $prev['driver_id'] ?? $trip->driver_id;

            $distResult = $this->measureDistance(
                $trip,
                $prev['time'],
                $curr['time'],
                $prev['lat'],
                $prev['lng'],
                $curr['lat'],
                $curr['lng'],
                $driverId
            );

            $legs[] = [
                'leg_index' => count($legs) + 1,
                'from_checkpoint_id' => null,
                'to_checkpoint_id' => null,
                'from_name' => $prev['name'],
                'to_name' => $curr['name'],
                'from_time' => $prev['time']?->toIso8601String(),
                'to_time' => $curr['time']?->toIso8601String(),
                'distance_km' => $distResult['km'],
                'is_loaded' => $curr['is_loaded'],
                'source' => $distResult['source'],
                'driver_id' => $driverId,
            ];
        }

        return $legs;
    }

    /**
     * Đo khoảng cách giữa 2 mốc: ưu tiên GPS thực tế, fallback OSRM nếu GPS không có điểm.
     *
     * @return array{km: float, source: string}
     */
    public function measureDistance(
        Trip $trip,
        ?CarbonImmutable $fromTime,
        ?CarbonImmutable $toTime,
        ?float $fromLat,
        ?float $fromLng,
        ?float $toLat,
        ?float $toLng,
        ?int $driverId = null,
    ): array {
        // 1. Thử tính từ GPS thực tế nếu có thời gian hợp lệ
        if ($fromTime !== null && $toTime !== null && $toTime->gt($fromTime) && $trip->vehicle_id !== null) {
            $gpsRes = $this->gpsDistanceService->distance($trip->vehicle_id, $fromTime, $toTime, $driverId);
            if ($gpsRes->km > 0.05) {
                return [
                    'km' => round($gpsRes->km, 1),
                    'source' => $gpsRes->source ?? 'phone_gps',
                ];
            }
        }

        // 2. Fallback sang OSRM nếu có đủ toạ độ 2 đầu và toạ độ khác nhau
        if ($fromLat !== null && $fromLng !== null && $toLat !== null && $toLng !== null) {
            if ($this->coordsEqual($fromLat, $fromLng, $toLat, $toLng)) {
                return ['km' => 0.0, 'source' => 'stationary'];
            }

            try {
                $routeRes = $this->osrmService->getRoute($fromLat, $fromLng, $toLat, $toLng);
                if (! empty($routeRes['success']) && isset($routeRes['data']['distance'])) {
                    $meters = (float) $routeRes['data']['distance'];

                    return [
                        'km' => round($meters / 1000, 1),
                        'source' => 'osrm',
                    ];
                }
            } catch (\Throwable) {
                // Tiếp tục fallback sang haversine
            }

            // 3. Fallback sang Haversine * 1.3 (đường bộ xấp xỉ) nếu OSRM lỗi
            $straightMeters = $this->haversine($fromLat, $fromLng, $toLat, $toLng);

            return [
                'km' => round(($straightMeters * 1.3) / 1000, 1),
                'source' => 'haversine',
            ];
        }

        return ['km' => 0.0, 'source' => 'unknown'];
    }

    /**
     * Trích xuất các điểm dừng chính của chuyến.
     *
     * @param  Collection<int, TripCheckpoint>  $checkpoints
     * @return array<int, array{
     *     name: string,
     *     time: ?CarbonImmutable,
     *     lat: ?float,
     *     lng: ?float,
     *     is_loaded: bool,
     *     driver_id: ?int,
     * }>
     */
    private function extractKeyStops(Trip $trip, Collection $checkpoints): array
    {
        $stops = [];

        // 1. Điểm xuất phát
        $startedCp = $checkpoints->firstWhere('checkpoint_type', CheckpointType::Started);
        $startTime = $startedCp?->occurred_at
            ? CarbonImmutable::parse($startedCp->occurred_at)
            : ($trip->started_at ? CarbonImmutable::parse($trip->started_at) : null);

        $startCoords = $this->resolveCoords(
            $startedCp?->gps_lat,
            $startedCp?->gps_lng,
            $trip->startLocation?->lat,
            $trip->startLocation?->lng,
            $trip->orders->first()?->pickupLocation?->lat,
            $trip->orders->first()?->pickupLocation?->lng
        );

        $stops[] = [
            'name' => $trip->startLocation?->name ?? 'Bắt đầu chuyến',
            'time' => $startTime,
            'lat' => $startCoords['lat'],
            'lng' => $startCoords['lng'],
            'is_loaded' => false,
            'driver_id' => $startedCp?->driver_id ?? $trip->driver_id,
        ];

        // 2. Điểm lấy hàng
        $pickupCps = $checkpoints->filter(fn (TripCheckpoint $cp) => in_array($cp->checkpoint_type, [CheckpointType::ArrivedPickup, CheckpointType::LeftPickup], true));
        $firstPickup = $pickupCps->firstWhere('checkpoint_type', CheckpointType::ArrivedPickup) ?? $pickupCps->first();

        if ($firstPickup) {
            $order = $firstPickup->order ?? $trip->orders->first();
            $loc = $order?->pickupLocation;
            $coords = $this->resolveCoords($firstPickup->gps_lat, $firstPickup->gps_lng, $loc?->lat, $loc?->lng);

            $stops[] = [
                'name' => $loc?->name ?? $loc?->code ?? $order?->pickup_address ?? 'Điểm lấy hàng',
                'time' => $firstPickup->occurred_at ? CarbonImmutable::parse($firstPickup->occurred_at) : null,
                'lat' => $coords['lat'],
                'lng' => $coords['lng'],
                'is_loaded' => false, // chặng từ Bắt đầu -> Điểm lấy là xe rỗng
                'driver_id' => $firstPickup->driver_id ?? $trip->driver_id,
            ];
        }

        // 3. Các điểm giao hàng
        $deliveryCps = $checkpoints->filter(fn (TripCheckpoint $cp) => in_array($cp->checkpoint_type, [CheckpointType::ArrivedDelivery, CheckpointType::Completed], true));

        // Nhóm theo delivery_point_id hoặc vị trí
        $processedDpIds = [];
        foreach ($deliveryCps as $delivCp) {
            $dpId = $delivCp->delivery_point_id;
            if ($dpId && in_array($dpId, $processedDpIds, true)) {
                continue;
            }
            if ($dpId) {
                $processedDpIds[] = $dpId;
            }

            $dp = $delivCp->deliveryPoint;
            $loc = $dp?->location;
            $coords = $this->resolveCoords($delivCp->gps_lat, $delivCp->gps_lng, $loc?->lat, $loc?->lng);

            $stops[] = [
                'name' => $loc?->name ?? $loc?->code ?? $dp?->address ?? 'Điểm giao hàng',
                'time' => $delivCp->occurred_at ? CarbonImmutable::parse($delivCp->occurred_at) : null,
                'lat' => $coords['lat'],
                'lng' => $coords['lng'],
                'is_loaded' => true, // chặng đến điểm giao là chở hàng
                'driver_id' => $delivCp->driver_id ?? $trip->driver_id,
            ];
        }

        // 4. Điểm kết thúc chuyến (nếu có mốc End riêng hoặc toạ độ kết thúc khác)
        $endCp = $checkpoints->firstWhere('checkpoint_type', CheckpointType::End);
        if ($endCp) {
            $endCoords = $this->resolveCoords(
                $endCp->gps_lat,
                $endCp->gps_lng,
                $trip->endLocation?->lat,
                $trip->endLocation?->lng
            );

            $lastStop = end($stops);
            if ($endCoords['lat'] !== null && ! $this->coordsEqual($lastStop['lat'], $lastStop['lng'], $endCoords['lat'], $endCoords['lng'])) {
                $stops[] = [
                    'name' => $trip->endLocation?->name ?? 'Kết thúc chuyến',
                    'time' => $endCp->occurred_at ? CarbonImmutable::parse($endCp->occurred_at) : null,
                    'lat' => $endCoords['lat'],
                    'lng' => $endCoords['lng'],
                    'is_loaded' => false, // chặng về bãi là xe rỗng
                    'driver_id' => $endCp->driver_id ?? $trip->driver_id,
                ];
            }
        }

        return $stops;
    }

    /**
     * @return array{lat: ?float, lng: ?float}
     */
    private function resolveCheckpointCoords(TripCheckpoint $cp, Trip $trip): array
    {
        if ($cp->gps_lat !== null && $cp->gps_lng !== null && ((float) $cp->gps_lat !== 0.0 || (float) $cp->gps_lng !== 0.0)) {
            return ['lat' => (float) $cp->gps_lat, 'lng' => (float) $cp->gps_lng];
        }

        if ($cp->deliveryPoint?->location) {
            return [
                'lat' => $cp->deliveryPoint->location->lat ? (float) $cp->deliveryPoint->location->lat : null,
                'lng' => $cp->deliveryPoint->location->lng ? (float) $cp->deliveryPoint->location->lng : null,
            ];
        }

        if ($cp->order?->pickupLocation) {
            return [
                'lat' => $cp->order->pickupLocation->lat ? (float) $cp->order->pickupLocation->lat : null,
                'lng' => $cp->order->pickupLocation->lng ? (float) $cp->order->pickupLocation->lng : null,
            ];
        }

        if ($cp->checkpoint_type === CheckpointType::Started && $trip->startLocation) {
            return [
                'lat' => $trip->startLocation->lat ? (float) $trip->startLocation->lat : null,
                'lng' => $trip->startLocation->lng ? (float) $trip->startLocation->lng : null,
            ];
        }

        if ($cp->checkpoint_type === CheckpointType::End && $trip->endLocation) {
            return [
                'lat' => $trip->endLocation->lat ? (float) $trip->endLocation->lat : null,
                'lng' => $trip->endLocation->lng ? (float) $trip->endLocation->lng : null,
            ];
        }

        return ['lat' => null, 'lng' => null];
    }

    /**
     * @return array{lat: ?float, lng: ?float}
     */
    private function resolveCoords(...$pairs): array
    {
        for ($i = 0; $i < count($pairs); $i += 2) {
            $lat = $pairs[$i] ?? null;
            $lng = $pairs[$i + 1] ?? null;
            if ($lat !== null && $lng !== null && ((float) $lat !== 0.0 || (float) $lng !== 0.0)) {
                return ['lat' => (float) $lat, 'lng' => (float) $lng];
            }
        }

        return ['lat' => null, 'lng' => null];
    }

    private function isSameLocation(array $a, array $b): bool
    {
        return $this->coordsEqual($a['lat'], $a['lng'], $b['lat'], $b['lng']);
    }

    private function coordsEqual(?float $lat1, ?float $lng1, ?float $lat2, ?float $lng2): bool
    {
        if ($lat1 === null || $lng1 === null || $lat2 === null || $lng2 === null) {
            return false;
        }

        return abs($lat1 - $lat2) < 0.0005 && abs($lng1 - $lng2) < 0.0005;
    }

    private function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * self::EARTH_RADIUS_M * asin(min(1, sqrt($a)));
    }
}
