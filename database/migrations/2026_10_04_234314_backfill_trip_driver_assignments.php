<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Dựng lượt lái từ driver_swaps + trips.driver_id cho dữ liệu cũ.
     */
    public function up(): void
    {
        DB::table('trips')
            ->where(fn ($query) => $query
                ->whereNotNull('driver_id')
                ->orWhereExists(fn ($sub) => $sub->select(DB::raw(1))->from('driver_swaps')->whereColumn('driver_swaps.trip_id', 'trips.id')))
            ->orderBy('id')
            ->chunkById(200, function ($trips): void {
                foreach ($trips as $trip) {
                    $this->backfillTrip($trip);
                }
            });
    }

    public function down(): void
    {
        DB::table('trip_driver_assignments')->delete();
    }

    private function backfillTrip(object $trip): void
    {
        $swaps = DB::table('driver_swaps')->where('trip_id', $trip->id)->orderBy('created_at')->orderBy('id')->get();
        $cursor = $trip->started_at ?? $trip->created_at;
        $rows = [];

        foreach ($swaps as $swap) {
            if ($swap->from_driver_id !== null) {
                $rows[] = $this->row($trip->id, $swap->from_driver_id, $swap->from_shift_id, $cursor, $swap->created_at, $swap->reason ?? 'other', $swap->note);
            }
            $cursor = $swap->created_at ?? $cursor;
        }

        if ($trip->status === 'driver_swap') {
            if ($trip->driver_id !== null) {
                $rows[] = $this->row($trip->id, $trip->driver_id, $trip->shift_id, $cursor, $trip->updated_at, 'other', null);
            }

            DB::table('trips')->where('id', $trip->id)->update([
                'driver_id' => null,
                'status_before_swap' => $this->statusBeforeSwap($trip->id),
            ]);
        } elseif ($trip->driver_id !== null) {
            [$endedAt, $reason] = match ($trip->status) {
                'completed' => [$trip->completed_at ?? $trip->updated_at, 'trip_finished'],
                'cancelled' => [$trip->cancelled_at ?? $trip->updated_at, 'trip_cancelled'],
                default => [null, null],
            };

            $rows[] = $this->row($trip->id, $trip->driver_id, $trip->shift_id, $cursor, $endedAt, $reason, null);
        }

        if ($rows !== []) {
            DB::table('trip_driver_assignments')->insert($rows);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function row(int $tripId, int $driverId, ?int $shiftId, mixed $startedAt, mixed $endedAt, ?string $reason, ?string $note): array
    {
        $startedAt ??= now();

        return [
            'trip_id' => $tripId,
            'driver_id' => $driverId,
            'shift_id' => $shiftId !== null && DB::table('driver_shifts')->where('id', $shiftId)->exists() ? $shiftId : null,
            'started_at' => $startedAt,
            'ended_at' => $endedAt,
            'end_reason' => $reason,
            'note' => $note,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    private function statusBeforeSwap(int $tripId): string
    {
        $lastType = DB::table('trip_checkpoints')
            ->where('trip_id', $tripId)
            ->whereNotIn('checkpoint_type', ['driver_swap', 'end', 'cancelled'])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->value('checkpoint_type');

        return match ($lastType) {
            'left_pickup', 'completed' => 'delivering',
            'arrived_delivery' => 'arrived_delivery',
            'arrived_pickup' => 'arrived_pickup',
            default => 'started',
        };
    }
};
