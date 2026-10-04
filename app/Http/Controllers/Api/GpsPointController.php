<?php

namespace App\Http\Controllers\Api;

use App\Enums\TripStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreGpsPointsRequest;
use App\Models\DriverShift;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleGpsPoint;
use Carbon\Carbon;
use Carbon\CarbonInterface;
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

        $vehicleId = $this->ownVehicleId($user, $validated['vehicle_id'] ?? null);
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
        $this->reopenSettledKm($user, $rows->min('recorded_at'), $rows->max('recorded_at'));

        return response()->json([
            'last_seq' => (int) VehicleGpsPoint::where('driver_id', $user->id)->where('device_id', $deviceId)->max('seq'),
        ]);
    }

    /**
     * Chỉ nhận xe tài xế đang giữ (chuyến đang chạy hoặc xe được gán); xe khác bị bỏ qua để không ghi đè vị trí xe người khác.
     */
    private function ownVehicleId(User $driver, ?int $vehicleId): ?int
    {
        if ($vehicleId === null) {
            return null;
        }

        $isOwn = Trip::query()
            ->where('driver_id', $driver->id)
            ->where('vehicle_id', $vehicleId)
            ->whereIn('status', TripStatus::busyStatuses())
            ->exists()
            || Vehicle::whereKey($vehicleId)->where('current_driver_id', $driver->id)->exists();

        return $isOwn ? $vehicleId : null;
    }

    /**
     * Điểm gửi bù muộn rơi vào chuyến/ca đã chốt km → mở lại để lệnh gps:calculate-km tính lại
     * (trừ chuyến điều hành đã điều chỉnh tay).
     */
    private function reopenSettledKm(User $driver, CarbonInterface $from, CarbonInterface $to): void
    {
        Trip::query()
            ->whereNotNull('km_calculated_at')
            ->whereNull('km_adjusted')
            ->where('started_at', '<=', $to)
            ->where(fn ($q) => $q->where('completed_at', '>=', $from)->orWhere('cancelled_at', '>=', $from))
            ->whereHas('driverAssignments', fn ($q) => $q->where('driver_id', $driver->id))
            ->update(['km_calculated_at' => null]);

        DriverShift::query()
            ->where('driver_id', $driver->id)
            ->whereNotNull('km_calculated_at')
            ->where('start_time', '<=', $to)
            ->where('end_time', '>=', $from)
            ->update(['km_calculated_at' => null]);
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
