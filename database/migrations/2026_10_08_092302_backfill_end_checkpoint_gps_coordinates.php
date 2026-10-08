<?php

use App\Enums\CheckpointType;
use App\Models\TripCheckpoint;
use App\Models\VehicleGpsPoint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $endCheckpoints = TripCheckpoint::where('checkpoint_type', CheckpointType::End->value)
            ->whereNull('gps_lat')
            ->with(['trip.endLocation', 'trip.checkpoints'])
            ->get();

        foreach ($endCheckpoints as $cp) {
            $trip = $cp->trip;
            if ($trip === null) {
                continue;
            }

            $lat = $trip->endLocation?->latitude;
            $lng = $trip->endLocation?->longitude;

            if ($lat === null || $lng === null) {
                $lastGps = VehicleGpsPoint::where('vehicle_id', $trip->vehicle_id)
                    ->where('source', VehicleGpsPoint::SOURCE_PHONE)
                    ->where('recorded_at', '<=', $trip->completed_at ?? now())
                    ->latest('recorded_at')
                    ->first();
                if ($lastGps !== null) {
                    $lat = $lastGps->lat;
                    $lng = $lastGps->lng;
                }
            }

            if ($lat === null || $lng === null) {
                $lastCp = $trip->checkpoints
                    ->where('id', '!=', $cp->id)
                    ->filter(fn ($c) => $c->gps_lat !== null && $c->gps_lng !== null)
                    ->sortByDesc('occurred_at')
                    ->first();
                if ($lastCp !== null) {
                    $lat = $lastCp->gps_lat;
                    $lng = $lastCp->gps_lng;
                }
            }

            if ($lat !== null && $lng !== null) {
                $cp->update([
                    'gps_lat' => $lat,
                    'gps_lng' => $lng,
                    'vehicle_id' => $cp->vehicle_id ?? $trip->vehicle_id,
                ]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No reverse operation needed for backfill
    }
};
