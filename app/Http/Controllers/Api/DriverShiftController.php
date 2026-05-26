<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\EndShiftRequest;
use App\Http\Requests\StartShiftRequest;
use App\Http\Resources\DriverShiftResource;
use App\Models\DriverShift;
use App\Models\Vehicle;
use Carbon\Carbon;
use Dedoc\Scramble\Attributes\BodyParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DriverShiftController extends Controller
{
    /**
     * Bắt đầu ca làm việc.
     *
     * @response array{shift: DriverShiftResource}
     */
    #[BodyParameter('vehicle_id', type: 'integer', description: 'ID xe được chọn để bắt đầu ca.', required: true, example: 10)]
    #[BodyParameter('shift_type', type: 'string', description: 'Loại ca làm việc.', required: true, example: 'morning_half')]
    #[BodyParameter('start_time', type: 'string', format: 'date-time', description: 'Thời điểm bắt đầu ca.', example: '2026-05-20T07:15:22Z')]
    #[BodyParameter('start_km', type: 'number', description: 'Km đồng hồ lúc bắt đầu ca.', example: 10000)]
    #[BodyParameter('start_gps_lat', type: 'string', description: 'Vĩ độ GPS lúc bắt đầu ca.', example: '10,823099')]
    #[BodyParameter('start_gps_lng', type: 'string', description: 'Kinh độ GPS lúc bắt đầu ca.', example: '106,629662')]
    public function start(StartShiftRequest $request): JsonResponse
    {
        $user = $request->user();

        $payload = $request->validated();

        // normalize start_time and ensure it's today
        $startTime = isset($payload['start_time']) ? Carbon::parse($payload['start_time']) : Carbon::now();
        if (! $startTime->isToday()) {
            return response()->json(['message' => 'Thời gian bắt đầu phải là ngày hôm nay'], 422);
        }

        // prevent creating two shifts of the same type on the same day
        $sameDaySameType = DriverShift::query()
            ->where('driver_id', $user->id)
            ->whereDate('start_time', $startTime->toDateString())
            ->where('shift_type', $payload['shift_type'])
            ->exists();

        if ($sameDaySameType) {
            return response()->json(['message' => 'Đã có ca cùng loại vào hôm nay'], 409);
        }

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
    #[BodyParameter('end_time', type: 'string', format: 'date-time', description: 'Thời điểm kết thúc ca.', example: '2026-05-20T17:30:00Z')]
    #[BodyParameter('end_km', type: 'number', description: 'Km đồng hồ lúc kết thúc ca.', example: 10245.5)]
    #[BodyParameter('end_gps_lat', type: 'string', description: 'Vĩ độ GPS lúc kết thúc ca.', example: '10,842001')]
    #[BodyParameter('end_gps_lng', type: 'string', description: 'Kinh độ GPS lúc kết thúc ca.', example: '106,701234')]
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

    /**
     * Lấy ca làm việc hiện tại của user (nếu có).
     *
     * @response array{shift: DriverShiftResource|null}
     */
    public function current(Request $request): JsonResponse
    {
        $user = $request->user();

        $shift = DriverShift::query()
            ->where('driver_id', $user->id)
            ->whereNull('end_time')
            ->first();

        // only return the active shift if it started today
        if ($shift) {
            $start = $shift->start_time ? Carbon::parse($shift->start_time) : null;
            if (! $start || ! $start->isToday()) {
                return response()->json(['shift' => null]);
            }
        }

        return response()->json(['shift' => $shift ? DriverShiftResource::make($shift) : null]);
    }
}
