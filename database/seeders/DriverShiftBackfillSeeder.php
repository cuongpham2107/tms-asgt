<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DriverShiftBackfillSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $now = now();

        $shifts = DB::table('driver_shifts')
            ->orderBy('id')
            ->get();

        foreach ($shifts as $shift) {
            $checkpoints = DB::table('trip_checkpoints')
                ->where('shift_id', $shift->id)
                ->orderBy('occurred_at')
                ->get(['checkpoint_type', 'km_reading']);

            [$endKm, $totalKm] = $this->resolveShiftMileage($shift->start_km, $shift->shift_type, $shift->id, $checkpoints);
            $loadedKm = $this->resolveLoadedMileage($totalKm, $shift->shift_type, $checkpoints);

            DB::table('driver_shifts')
                ->where('id', $shift->id)
                ->update([
                    'end_km' => $endKm,
                    'total_km' => $totalKm,
                    'total_km_loaded' => $loadedKm,
                    'total_km_empty' => round(max(0, $totalKm - $loadedKm), 1),
                    'updated_at' => $now,
                ]);
        }
    }

    /**
     * @return array{0: float, 1: float}
     */
    private function resolveShiftMileage(float|string|null $startKm, string $shiftType, int $shiftId, Collection $checkpoints): array
    {
        $startMileage = (float) $startKm;

        if ($checkpoints->isNotEmpty()) {
            $minCheckpoint = (float) $checkpoints->min('km_reading');
            $maxCheckpoint = (float) $checkpoints->max('km_reading');

            $distance = $maxCheckpoint > $startMileage
                ? $maxCheckpoint - $startMileage
                : $startMileage - $minCheckpoint;

            $distance = round(max(75.0, $distance), 1);

            return [round($startMileage + $distance, 1), $distance];
        }

        return [round($startMileage + $this->estimateShiftDistance($shiftType, $shiftId), 1), $this->estimateShiftDistance($shiftType, $shiftId)];
    }

    private function estimateShiftDistance(string $shiftType, int $shiftId): float
    {
        $baseDistance = match ($shiftType) {
            'morning_half' => 145.0,
            'night_half' => 165.0,
            default => 220.0,
        };

        return round($baseDistance + (($shiftId % 4) * 18.5), 1);
    }

    private function resolveLoadedMileage(float $totalKm, string $shiftType, Collection $checkpoints): float
    {
        $checkpointCount = $checkpoints->count();
        $loadedCheckpointCount = $checkpoints
            ->whereIn('checkpoint_type', ['left_pickup', 'arrived_delivery', 'completed'])
            ->count();

        if ($checkpointCount === 0) {
            $ratio = match ($shiftType) {
                'morning_half' => 0.55,
                'night_half' => 0.50,
                default => 0.62,
            };

            return round($totalKm * $ratio, 1);
        }

        $ratio = 0.35 + (($loadedCheckpointCount / $checkpointCount) * 0.45);

        if ($checkpointCount >= 20) {
            $ratio += 0.05;
        }

        return round($totalKm * min(0.85, max(0.35, $ratio)), 1);
    }
}
