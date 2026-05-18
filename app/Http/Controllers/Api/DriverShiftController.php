<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\EndShiftRequest;
use App\Http\Requests\StartShiftRequest;
use App\Http\Resources\DriverShiftResource;
use App\Models\DriverShift;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class DriverShiftController extends Controller
{
    /**
     * Bắt đầu ca làm việc.
     *
     * @response array{shift: DriverShiftResource}
     */
    public function start(StartShiftRequest $request): JsonResponse
    {
        $user = $request->user();

        $payload = $request->validated();

        // Ensure driver does not have an open shift on same vehicle
        $existing = DriverShift::query()
            ->where('driver_id', $user->id)
            ->whereNull('end_time')
            ->first();

        if ($existing) {
            /** @status 409 */
            return response()->json(['message' => 'You already have an active shift'], 409);
        }

        DB::beginTransaction();
        try {
            $shift = DriverShift::create([
                'driver_id' => $user->id,
                'vehicle_id' => $payload['vehicle_id'],
                'shift_type' => $payload['shift_type'],
                'start_time' => $payload['start_time'] ?? now(),
                'start_km' => $payload['start_km'] ?? null,
                'start_gps_lat' => $payload['start_gps_lat'] ?? null,
                'start_gps_lng' => $payload['start_gps_lng'] ?? null,
            ]);

            // mark vehicle current driver
            Vehicle::query()->where('id', $payload['vehicle_id'])->update(['current_driver_id' => $user->id]);

            DB::commit();

            return response()->json(['shift' => DriverShiftResource::make($shift)]);
        } catch (\Throwable $e) {
            DB::rollBack();

            /** @status 500 */
            return response()->json(['message' => 'Unable to start shift', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Kết thúc ca làm việc.
     *
     * @response array{shift: DriverShiftResource}
     */
    public function end(EndShiftRequest $request): JsonResponse
    {
        $user = $request->user();

        $payload = $request->validated();

        $shift = DriverShift::query()
            ->where('driver_id', $user->id)
            ->whereNull('end_time')
            ->first();

        if (! $shift) {
            /** @status 404 */
            return response()->json(['message' => 'No active shift found'], 404);
        }

        DB::beginTransaction();
        try {
            $shift->end_time = $payload['end_time'] ?? now();
            $shift->end_km = $payload['end_km'] ?? $shift->end_km;
            $shift->end_gps_lat = $payload['end_gps_lat'] ?? null;
            $shift->end_gps_lng = $payload['end_gps_lng'] ?? null;

            if ($shift->start_km !== null && $shift->end_km !== null) {
                $shift->total_km = $shift->end_km - $shift->start_km;
            }

            $shift->save();

            // remove vehicle current driver if matches
            // use direct lookup to avoid calling relation on a non-model (stdClass) in some flows
            $vehicle = Vehicle::find($shift->vehicle_id);
            if ($vehicle && $vehicle->current_driver_id === $user->id) {
                $vehicle->current_driver_id = null;
                $vehicle->save();
            }

            DB::commit();

            return response()->json(['shift' => DriverShiftResource::make($shift)]);
        } catch (\Throwable $e) {
            DB::rollBack();

            /** @status 500 */
            return response()->json(['message' => 'Unable to end shift', 'error' => $e->getMessage()], 500);
        }
    }
}
