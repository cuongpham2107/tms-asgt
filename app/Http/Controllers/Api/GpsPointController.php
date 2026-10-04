<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreGpsPointsRequest;
use App\Models\DriverShift;
use App\Models\Vehicle;
use App\Models\VehicleGpsPoint;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;

class GpsPointController extends Controller
{
    /**
     * Nhận một lô điểm GPS từ app tài xế. Gửi trùng (cùng device_id + seq) được bỏ qua.
     *
     * @response array{last_seq: int}
     */
    public function store(StoreGpsPointsRequest $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();
        $deviceId = $validated['device_id'];

        $shiftId = $validated['shift_id'] ?? null;
        if ($shiftId !== null && ! DriverShift::where('id', $shiftId)->where('driver_id', $user->id)->exists()) {
            return response()->json(['message' => 'Ca làm việc không thuộc về bạn'], 403);
        }

        $vehicleId = $validated['vehicle_id'] ?? null;
        $now = now();

        $rows = collect($validated['points'])->map(fn (array $point): array => [
            'vehicle_id' => $vehicleId,
            'driver_id' => $user->id,
            'shift_id' => $shiftId,
            'device_id' => $deviceId,
            'seq' => (int) $point['seq'],
            'recorded_at' => Carbon::parse($point['recorded_at'])->setTimezone(config('app.timezone')),
            'lat' => $point['lat'],
            'lng' => $point['lng'],
            'speed' => $point['speed'] ?? null,
            'heading' => $point['heading'] ?? null,
            'accuracy' => $point['accuracy'] ?? null,
            'mocked' => (bool) ($point['mocked'] ?? false),
            'source' => VehicleGpsPoint::SOURCE_PHONE,
            'created_at' => $now,
        ]);

        VehicleGpsPoint::insertOrIgnore($rows->all());

        $this->updateVehiclePosition($vehicleId, $rows->sortBy('recorded_at')->last());

        return response()->json([
            'last_seq' => (int) VehicleGpsPoint::where('device_id', $deviceId)->max('seq'),
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $latest
     */
    private function updateVehiclePosition(?int $vehicleId, ?array $latest): void
    {
        if ($vehicleId === null || $latest === null || $latest['mocked']) {
            return;
        }

        $vehicle = Vehicle::find($vehicleId);
        if ($vehicle === null || ($vehicle->last_gps_update !== null && $vehicle->last_gps_update->gte($latest['recorded_at']))) {
            return;
        }

        $vehicle->gps_lat = $latest['lat'];
        $vehicle->gps_lng = $latest['lng'];
        $vehicle->gps_speed = $latest['speed'];
        $vehicle->last_gps_update = $latest['recorded_at'];
        $vehicle->save();
    }
}
