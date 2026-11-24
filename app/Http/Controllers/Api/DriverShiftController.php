<?php

namespace App\Http\Controllers\Api;

use App\Enums\CheckpointType;
use App\Enums\TripStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\EndShiftRequest;
use App\Http\Requests\EndVehicleRequest;
use App\Http\Requests\StartShiftRequest;
use App\Http\Requests\SwitchVehicleRequest;
use App\Http\Resources\DriverShiftResource;
use App\Models\DriverShift;
use App\Models\DriverSwap;
use App\Models\Trip;
use App\Models\TripCheckpoint;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Km\ShiftKmCalculatorService;
use App\Services\Shift\LeaveVehicleService;
use App\Services\Shift\ShiftEndService;
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

        DB::beginTransaction();
        try {
            $shift = DriverShift::create([
                'driver_id' => $user->id,
                'shift_type' => $payload['shift_type'],
                'start_time' => $startTime,
                'start_gps_lat' => $payload['start_gps_lat'] ?? null,
                'start_gps_lng' => $payload['start_gps_lng'] ?? null,
            ]);

            // Gán to_shift_id cho DriverSwap đang chờ của tài xế này
            DriverSwap::where('to_driver_id', $user->id)
                ->whereNull('to_shift_id')
                ->update(['to_shift_id' => $shift->id]);

            DB::commit();

            return response()->json(['shift' => DriverShiftResource::make($shift->load(['driver', 'trips.vehicle', 'trips.orders']))]);
        } catch (\Throwable $e) {
            DB::rollBack();

            /** @status 500 */
            return response()->json(['message' => 'Không thể bắt đầu ca làm việc', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Nhập km khi rời xe (checkpoint type = end).
     *
     * Idempotent: nếu ca đã kết thúc, trả 200 kèm checkpoint 'end' gần nhất và cờ
     * `already_ended` thay vì lỗi 422 — app mobile có thể giữ state cũ (ca đã bị
     * auto-end/admin kết thúc) và bấm "Kết thúc ca" lần nữa.
     *
     * @response array{checkpoint: ?array, vehicle: ?array, already_ended: bool}
     */
    #[BodyParameter('km_reading', type: 'number', description: 'Số km đồng hồ lúc rời xe.', required: true)]
    public function endVehicle(EndVehicleRequest $request, DriverShift $shift): JsonResponse
    {
        $user = $request->user();
        $payload = $request->validated();
        $kmReading = (float) $payload['km_reading'];

        if ($shift->driver_id !== $user->id) {
            return response()->json(['message' => 'Ca này không thuộc về bạn'], 403);
        }

        // Find vehicle: from any trip on this shift (even completed ones — driver
        // may still be in the same vehicle after all trips are done)
        $vehicle = $shift->trips()
            ->latest('started_at')
            ->first()?->vehicle
            ?? $user->vehiclesAsDriver()->first();

        // Ca đã kết thúc rồi (auto-end lúc 08:00/20:00, admin kết thúc, hoặc thiết bị khác)
        // → trả về checkpoint 'end' đã có thay vì báo lỗi, để app đồng bộ lại trạng thái.
        if ($shift->end_time !== null) {
            $existingCheckpoint = TripCheckpoint::where('shift_id', $shift->id)
                ->whereIn('checkpoint_type', [CheckpointType::End->value, CheckpointType::DriverSwap->value])
                ->whereNotNull('km_reading')
                ->latest('id')
                ->first();

            return response()->json([
                'checkpoint' => $existingCheckpoint?->toArray(),
                'vehicle' => $vehicle ? [
                    'id' => $vehicle->id,
                    'current_mileage' => $vehicle->fresh()->current_mileage,
                ] : null,
                'already_ended' => true,
            ]);
        }

        // Còn chuyến chưa xong thì không cho rời xe: trả lỗi kèm active_trip_id để App mở chi tiết chuyến
        // (bàn giao bằng Đảo lái hoặc làm nốt), thay vì tự chuyển chuyến sang Đảo lái.
        if ($blocked = $this->unfinishedTripsResponse($user)) {
            return $blocked;
        }

        if ($vehicle === null) {
            return response()->json(['message' => 'Không tìm thấy xe đang hoạt động trong ca này'], 404);
        }

        // Validate km_reading >= vehicle.current_mileage (chặn nhập lùi km)
        $currentMileage = (float) ($vehicle->current_mileage ?? 0);
        if ($kmReading < $currentMileage) {
            return response()->json([
                'message' => 'Số km nhập vào ('.number_format($kmReading, 1).') nhỏ hơn số km hiện tại của xe ('.number_format($currentMileage, 1).')',
            ], 422);
        }

        $checkpoint = app(LeaveVehicleService::class)->handle($shift, $vehicle, $kmReading);

        return response()->json([
            'checkpoint' => $checkpoint->toArray(),
            'vehicle' => ['id' => $vehicle->id, 'current_mileage' => $vehicle->fresh()->current_mileage],
        ]);
    }

    /**
     * Kết thúc ca làm việc.
     *
     * Idempotent: nếu không còn ca nào đang mở, trả 200 kèm ca vừa kết thúc gần nhất
     * (cờ `already_ended`) để app mobile tự cập nhật lại state; chỉ trả 404 khi tài xế
     * chưa từng có ca nào.
     *
     * @response array{shift: DriverShiftResource, already_ended?: bool}
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
            ->where('start_time', '<=', now())
            ->whereNull('end_time')
            ->latest('start_time')
            ->first();

        if (! $shift) {
            $lastShift = DriverShift::query()
                ->where('driver_id', $user->id)
                ->whereNotNull('end_time')
                ->latest('end_time')
                ->first();

            if ($lastShift) {
                return response()->json([
                    'shift' => DriverShiftResource::make($lastShift->load(['driver', 'trips' => fn ($q) => $q->whereNotIn('status', [TripStatus::Cancelled->value, TripStatus::Pending->value], 'and')->with(['vehicle', 'orders'])])),
                    'already_ended' => true,
                ]);
            }

            /** @status 404 */
            return response()->json(['message' => 'Không tìm thấy ca làm việc đang hoạt động'], 404);
        }

        $hasTrips = $shift->trips()->whereNotIn('status', [TripStatus::Cancelled, TripStatus::Pending], 'and')->exists();

        // Gate: phải có checkpoint type='end' cho xe hiện tại trước khi kết thúc ca
        // (chỉ bắt buộc nếu ca có chuyến — không có chuyến nào thì không cần)
        $endCheckpoint = TripCheckpoint::where('shift_id', $shift->id)
            ->whereIn('checkpoint_type', [CheckpointType::End->value, CheckpointType::DriverSwap->value])
            ->whereNotNull('km_reading')
            ->latest('id')
            ->first();

        if ($hasTrips && $endCheckpoint === null) {
            /** @status 422 */
            return response()->json(['message' => 'Cần nhập km kết thúc trước khi kết thúc ca.'], 422);
        }

        // Gate: không cho kết thúc ca nếu còn trip đang chạy chưa kết thúc chuyến (kể cả đã giao hàng xong nhưng chưa chốt Kết thúc đơn hàng)
        if ($blocked = $this->unfinishedTripsResponse($user)) {
            return $blocked;
        }

        // Use km_reading from the 'end' checkpoint (NOT from payload — avoids double entry drift)
        $endKm = $endCheckpoint ? (float) $endCheckpoint->km_reading : ($payload['end_km'] ?? null);

        DB::beginTransaction();
        try {
            app(ShiftEndService::class)->close($shift, now(), [
                'end_gps_lat' => $payload['end_gps_lat'] ?? null,
                'end_gps_lng' => $payload['end_gps_lng'] ?? null,
            ]);

            // Update vehicle mileage from the 'end' checkpoint's km_reading
            if ($endCheckpoint?->vehicle_id) {
                $vehicleUpdate = ['current_mileage' => $endKm];
                if (isset($payload['end_gps_lat'])) {
                    $vehicleUpdate['gps_lat'] = $payload['end_gps_lat'];
                }
                if (isset($payload['end_gps_lng'])) {
                    $vehicleUpdate['gps_lng'] = $payload['end_gps_lng'];
                }
                Vehicle::where('id', $endCheckpoint->vehicle_id)->update($vehicleUpdate);
            }

            DB::commit();

            return response()->json(['shift' => DriverShiftResource::make($shift->load(['driver', 'trips.vehicle', 'trips.orders']))]);
        } catch (\Throwable $e) {
            DB::rollBack();

            /** @status 500 */
            return response()->json(['message' => 'Không thể kết thúc ca làm việc', 'error' => $e->getMessage()], 500);
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
            ->where('start_time', '<=', now())
            ->whereNull('end_time')
            ->latest('start_time')
            ->first();

        if ($shift) {
            app(ShiftKmCalculatorService::class)->calculate($shift);
        }

        return response()->json(['shift' => $shift ? DriverShiftResource::make($shift->load(['driver', 'trips' => fn ($q) => $q->whereNotIn('status', [TripStatus::Cancelled->value, TripStatus::Pending->value], 'and')->with(['vehicle', 'orders'])])) : null]);
    }

    /**
     * Chuyển xe giữa ca.
     *
     * @response array{shift: DriverShiftResource}
     */
    #[BodyParameter('new_vehicle_id', type: 'integer', description: 'ID xe mới.', required: true)]
    #[BodyParameter('handover_km', type: 'number', description: 'Km đồng hồ tại thời điểm chuyển xe.', required: true)]
    public function switchVehicle(SwitchVehicleRequest $request): JsonResponse
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
            return response()->json(['message' => 'Không tìm thấy ca làm việc đang hoạt động'], 404);
        }

        // Gate: must have an 'end' checkpoint before switching vehicle
        $endCheckpoint = TripCheckpoint::where('shift_id', $shift->id)
            ->whereIn('checkpoint_type', [CheckpointType::End->value, CheckpointType::DriverSwap->value])
            ->whereNotNull('km_reading')
            ->latest('id')
            ->first();

        if ($endCheckpoint === null) {
            return response()->json(['message' => 'Cần nhập km kết thúc xe hiện tại trước khi chuyển xe.'], 422);
        }

        DB::beginTransaction();
        try {
            // Update new vehicle mileage with handover_km (setting up for the new vehicle)
            Vehicle::where('id', $payload['new_vehicle_id'])
                ->update([
                    'current_mileage' => $payload['handover_km'],
                    'gps_lat' => $payload['handover_gps_lat'] ?? null,
                    'gps_lng' => $payload['handover_gps_lng'] ?? null,
                ]);

            DB::commit();

            return response()->json([
                'shift' => DriverShiftResource::make($shift->load(['driver', 'trips.vehicle', 'trips.orders'])),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json(['message' => 'Không thể chuyển xe', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Lỗi 422 khi tài xế còn chuyến chưa xong (kể cả đã giao hàng nhưng chưa bấm Kết thúc đơn hàng),
     * dùng chung cho Rời xe và Kết thúc ca. Trả null khi được phép kết thúc ca.
     */
    private function unfinishedTripsResponse(User $user): ?JsonResponse
    {
        $incompleteTrips = Trip::query()
            ->unfinishedForDriver($user->id)
            ->with(['vehicle', 'orders'])
            ->get();

        if ($incompleteTrips->isEmpty()) {
            return null;
        }

        $plates = $incompleteTrips->map(fn (Trip $t) => $t->vehicle?->plate_number)
            ->filter()
            ->unique()
            ->implode(', ');
        if (empty($plates)) {
            $plates = $user->vehiclesAsDriver()->first()?->plate_number ?? ('#'.$incompleteTrips->first()->id);
        }

        $orderCodes = $incompleteTrips->flatMap(fn (Trip $t) => $t->orders->pluck('order_code'))
            ->filter()
            ->unique()
            ->implode(', ');
        if (empty($orderCodes)) {
            $orderCodes = $incompleteTrips->pluck('trip_code')->filter()->implode(', ');
        }

        $driverName = $user->name ?? 'Không xác định';

        return response()->json([
            'message' => sprintf(
                'Xe %s, mã đơn %s, lái xe %s chưa hoàn thiện chuyến, yêu cầu lái xe hoàn thành trước khi kết thúc ca.',
                $plates,
                $orderCodes,
                $driverName
            ),
            'active_trip_id' => $incompleteTrips->first()->id,
        ], 422);
    }
}
