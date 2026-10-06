<?php

namespace App\Http\Controllers\Api;

use App\Enums\TripStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\EndShiftRequest;
use App\Http\Requests\StartShiftRequest;
use App\Http\Resources\DriverShiftResource;
use App\Models\DriverShift;
use App\Models\Trip;
use App\Services\DriverShiftService;
use App\Services\Trip\TripDriverService;
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
    #[BodyParameter('shift_type', type: 'string', description: 'Loại ca làm việc.', required: true, example: 'morning_half')]
    #[BodyParameter('start_gps_lat', type: 'string', description: 'Vĩ độ GPS lúc bắt đầu ca.', example: '10,823099')]
    #[BodyParameter('start_gps_lng', type: 'string', description: 'Kinh độ GPS lúc bắt đầu ca.', example: '106,629662')]
    public function start(StartShiftRequest $request): JsonResponse
    {
        $user = $request->user();

        $payload = $request->validated();

        $startTime = Carbon::now();

        // Ensure driver does not have an open shift
        $existing = DriverShift::query()
            ->where('driver_id', $user->id)
            ->where('start_time', '<=', now())
            ->whereNull('end_time')
            ->first();

        if ($existing) {
            return response()->json(['message' => 'Bạn đã có một ca làm việc đang hoạt động'], 409);
        }

        // Lấy xe hiện tại của tài xế (qua current_driver_id)
        $activeVehicle = $user->vehiclesAsDriver()->first();

        DB::beginTransaction();
        try {
            $shift = DriverShift::create([
                'driver_id' => $user->id,
                'shift_type' => $payload['shift_type'],
                'start_time' => $startTime,
                'start_gps_lat' => $payload['start_gps_lat'] ?? null,
                'start_gps_lng' => $payload['start_gps_lng'] ?? null,
            ]);

            app(TripDriverService::class)->attachShift($shift);

            DB::commit();

            return response()->json(['shift' => DriverShiftResource::make($shift->load(['driver', 'trips.vehicle']))]);
        } catch (\Throwable $e) {
            DB::rollBack();

            /** @status 500 */
            return response()->json(['message' => 'Không thể bắt đầu ca làm việc', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Kết thúc ca làm việc.
     *
     * @response array{shift: DriverShiftResource}
     */
    #[BodyParameter('end_time', type: 'string', format: 'date-time', description: 'Thời điểm kết thúc ca.', example: '2026-05-20T17:30:00Z')]
    #[BodyParameter('end_gps_lat', type: 'string', description: 'Vĩ độ GPS lúc kết thúc ca.', example: '10,842001')]
    #[BodyParameter('end_gps_lng', type: 'string', description: 'Kinh độ GPS lúc kết thúc ca.', example: '106,701234')]
    public function end(EndShiftRequest $request): JsonResponse
    {
        $user = $request->user();

        $payload = $request->validated();

        $shift = DriverShift::query()
            ->where('driver_id', $user->id)
            ->where('start_time', '<=', now())
            ->whereNull('end_time')
            ->latest('start_time')
            ->first();

        if (! $shift) {
            /** @status 404 */
            return response()->json(['message' => 'Không tìm thấy ca làm việc đang hoạt động'], 404);
        }

        $shift = app(DriverShiftService::class)->endShift(
            $shift,
            isset($payload['end_gps_lat']) ? (float) $payload['end_gps_lat'] : null,
            isset($payload['end_gps_lng']) ? (float) $payload['end_gps_lng'] : null,
        );

        return response()->json(['shift' => DriverShiftResource::make($shift->load(['driver', 'trips.vehicle']))]);
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
            ->where('start_time', '<=', now())
            ->whereNull('end_time')
            ->latest('start_time')
            ->first();

        if (! $shift) {
            return response()->json(['shift' => null]);
        }

        $trips = Trip::query()
            ->where('status', '!=', TripStatus::Cancelled->value)
            ->where(function ($q) use ($shift) {
                $q->where('shift_id', $shift->id)
                    ->orWhereHas('driverAssignments', function ($aq) use ($shift) {
                        $aq->where('driver_id', $shift->driver_id)
                            ->where(function ($sq) use ($shift) {
                                $sq->where('shift_id', $shift->id)
                                    ->orWhere(function ($tsq) use ($shift) {
                                        $tsq->where('started_at', '>=', $shift->start_time)
                                            ->when($shift->end_time, fn ($esq) => $esq->where('started_at', '<=', $shift->end_time));
                                    });
                            });
                    });
            })
            ->with(['vehicle', 'driverAssignments'])
            ->get();

        $shift->setRelation('trips', $trips);

        return response()->json(['shift' => DriverShiftResource::make($shift->load('driver'))]);
    }
}
