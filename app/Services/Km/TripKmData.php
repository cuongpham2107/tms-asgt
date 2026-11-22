<?php

namespace App\Services\Km;

use App\Enums\CheckpointType;
use App\Models\DriverSwap;
use App\Models\Trip;
use App\Models\TripCheckpoint;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Mốc và lượt đảo lái của một chuyến, nạp một lần để TripKmSplitService lọc trong bộ nhớ
 * thay vì truy vấn lại cho từng bước. Thứ tự sắp xếp theo Postgres (NULL lớn nhất).
 */
final class TripKmData
{
    /**
     * @param  Collection<int, TripCheckpoint>  $checkpoints  sắp theo id
     * @param  Collection<int, DriverSwap>  $swaps  sắp theo id
     */
    public function __construct(
        public readonly Collection $checkpoints,
        public readonly Collection $swaps,
    ) {}

    public static function forTrip(Trip $trip): self
    {
        return self::forTrips(collect([$trip]))[$trip->id] ?? new self(collect(), collect());
    }

    /**
     * Nạp mốc và lượt đảo lái cho nhiều chuyến trong 2 truy vấn.
     *
     * @param  Collection<int, Trip>  $trips
     * @return array<int, self> theo trip_id
     */
    public static function forTrips(Collection $trips): array
    {
        $tripIds = $trips->pluck('id')->filter()->unique()->values();

        if ($tripIds->isEmpty()) {
            return [];
        }

        $checkpoints = TripCheckpoint::whereIn('trip_id', $tripIds)->orderBy('id')->get()->groupBy('trip_id');
        $swaps = DriverSwap::whereIn('trip_id', $tripIds)->orderBy('id')->get()->groupBy('trip_id');

        return $tripIds->mapWithKeys(fn (int $tripId): array => [
            $tripId => new self(
                $checkpoints->get($tripId, collect())->values(),
                $swaps->get($tripId, collect())->values(),
            ),
        ])->all();
    }

    /**
     * Mốc đầu tiên có tài xế (occurred_at, id tăng dần).
     */
    public function firstCheckpointWithDriver(): ?TripCheckpoint
    {
        return $this->byOccurredAt($this->checkpoints->filter(fn (TripCheckpoint $cp): bool => $cp->driver_id !== null))->first();
    }

    /**
     * Mốc driver_swap có km, sắp theo occurred_at, id.
     *
     * @return Collection<int, TripCheckpoint>
     */
    public function swapCheckpointsWithKm(): Collection
    {
        return $this->byOccurredAt($this->checkpoints->filter(
            fn (TripCheckpoint $cp): bool => self::type($cp) === CheckpointType::DriverSwap->value && $cp->km_reading !== null,
        ));
    }

    /**
     * Mốc không phải driver_swap, có tài xế, xảy ra sau thời điểm cho trước.
     */
    public function nextNonSwapCheckpointAfter(Carbon $occurredAt): ?TripCheckpoint
    {
        return $this->byOccurredAt($this->checkpoints->filter(
            fn (TripCheckpoint $cp): bool => self::type($cp) !== CheckpointType::DriverSwap->value
                && $cp->occurred_at !== null
                && $cp->occurred_at->gt($occurredAt)
                && $cp->driver_id !== null,
        ))->first();
    }

    /**
     * km_reading của mốc có km xảy ra muộn nhất.
     */
    public function latestKmReading(): ?string
    {
        $withKm = $this->checkpoints->filter(fn (TripCheckpoint $cp): bool => $cp->km_reading !== null)->all();

        usort($withKm, fn (TripCheckpoint $a, TripCheckpoint $b): int => self::compareNullsLast($b->occurred_at, $a->occurred_at) ?: $a->id <=> $b->id);

        return $withKm[0]->km_reading ?? null;
    }

    /**
     * Mốc arrived_pickup/completed có đơn và km, sắp theo km tăng dần.
     *
     * @return Collection<int, TripCheckpoint>
     */
    public function loadedEvents(): Collection
    {
        $events = $this->checkpoints->filter(
            fn (TripCheckpoint $cp): bool => in_array(self::type($cp), ['arrived_pickup', 'completed'], true)
                && $cp->order_id !== null
                && $cp->km_reading !== null,
        )->all();

        usort($events, fn (TripCheckpoint $a, TripCheckpoint $b): int => ((float) $a->km_reading <=> (float) $b->km_reading) ?: $a->id <=> $b->id);

        return collect($events);
    }

    /**
     * km của các mốc driver_swap thuộc một ca, theo thời gian.
     *
     * @return list<string>
     */
    public function swapKmReadingsForShift(int $shiftId): array
    {
        return $this->byOccurredAt($this->checkpoints->filter(
            fn (TripCheckpoint $cp): bool => self::type($cp) === CheckpointType::DriverSwap->value
                && (int) $cp->shift_id === $shiftId
                && $cp->km_reading !== null,
        ))->pluck('km_reading')->values()->all();
    }

    /**
     * @param  Collection<int, TripCheckpoint>  $checkpoints
     * @return Collection<int, TripCheckpoint>
     */
    private function byOccurredAt(Collection $checkpoints): Collection
    {
        $items = $checkpoints->all();

        usort($items, fn (TripCheckpoint $a, TripCheckpoint $b): int => self::compareNullsLast($a->occurred_at, $b->occurred_at) ?: $a->id <=> $b->id);

        return collect($items);
    }

    private static function compareNullsLast(?Carbon $a, ?Carbon $b): int
    {
        return match (true) {
            $a === null && $b === null => 0,
            $a === null => 1,
            $b === null => -1,
            default => $a->getTimestamp() <=> $b->getTimestamp() ?: (int) $a->format('u') <=> (int) $b->format('u'),
        };
    }

    private static function type(TripCheckpoint $checkpoint): string
    {
        $type = $checkpoint->checkpoint_type;

        return $type instanceof CheckpointType ? $type->value : (string) $type;
    }
}
