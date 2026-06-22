<?php

namespace App\Http\Controllers\Api;

use App\Enums\CheckpointType;
use App\Enums\OrderStatus;
use App\Enums\TripStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\EndShiftRequest;
use App\Http\Requests\StartShiftRequest;
use App\Http\Requests\SwitchVehicleRequest;
use App\Http\Resources\DriverShiftResource;
use App\Models\DriverShift;
use App\Models\Trip;
use App\Models\TripCheckpoint;
use App\Models\Vehicle;
use App\Services\ShiftKmCalculatorService;
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

        // Check vehicle requirements
        $vehicleId = $payload['vehicle_id'] ?? null;
        $currentVehicle = $user->vehiclesAsDriver()->first();

        if ($currentVehicle === null && $vehicleId === null) {
            return response()->json(['message' => 'Vui lòng chọn phương tiện để bắt đầu ca.'], 422);
        }

        $activeVehicle = $currentVehicle;
        if ($vehicleId !== null) {
            $activeVehicle = Vehicle::find($vehicleId);
            if ($activeVehicle === null) {
                return response()->json(['message' => 'Phương tiện không hợp lệ.'], 422);
            }
        }

        // prevent invalid shift combinations on the same day
        $existingShiftsToday = DriverShift::query()
            ->where('driver_id', $user->id)
            ->whereDate('start_time', $startTime->toDateString())
            ->get();

        $requestedType = $payload['shift_type'];

        foreach ($existingShiftsToday as $shift) {
            $existingType = $shift->shift_type->value ?? $shift->shift_type;

            if ($requestedType === 'full') {
                return response()->json(['message' => 'Không thể tạo ca cả ngày vì bạn đã có ca khác trong hôm nay.'], 409);
            }

            if ($existingType === 'full') {
                return response()->json(['message' => 'Bạn đã có ca cả ngày trong hôm nay, không thể tạo thêm ca mới.'], 409);
            }

            if ($requestedType === $existingType) {
                return response()->json(['message' => 'Đã có ca cùng loại vào hôm nay.'], 409);
            }
        }

        // Ensure driver does not have an open shift
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
            // Vehicle assignment is tracked via Order.driver_id and Order.vehicle_id only.
            // Vehicle.current_driver_id is a static/default field — not modified here.

            $startKm = $activeVehicle->current_mileage ?? 0;

            $shift = DriverShift::create([
                'driver_id' => $user->id,
                'shift_type' => $payload['shift_type'],
                'start_time' => $startTime,
                'start_km' => $startKm,
                'start_gps_lat' => $payload['start_gps_lat'] ?? null,
                'start_gps_lng' => $payload['start_gps_lng'] ?? null,
            ]);

            DB::commit();

            return response()->json(['shift' => DriverShiftResource::make($shift->load(['driver', 'trips.vehicle']))]);
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
            $endTime = $payload['end_time'] ?? now();
            $shift->end_time = $endTime;
            $shift->end_km = $payload['end_km'] ?? $shift->end_km;
            $shift->end_gps_lat = $payload['end_gps_lat'] ?? null;
            $shift->end_gps_lng = $payload['end_gps_lng'] ?? null;
            $shift->save();

            // Auto driver_swap: chuyển trip đang active có đơn hàng chưa hoàn thành sang driver_swap
            $incompleteTrips = Trip::where('driver_id', $user->id)
                ->whereHas('orders', function ($q) {
                    $q->whereIn('status', [OrderStatus::Sent, OrderStatus::Assigned]);
                })
                ->whereIn('status', [
                    TripStatus::Started,
                    TripStatus::ArrivedPickup,
                    TripStatus::Delivering,
                    TripStatus::ArrivedDelivery,
                ])
                ->get();

            foreach ($incompleteTrips as $trip) {
                $trip->status = TripStatus::DriverSwap;
                $trip->shift_id = null;
                $trip->save();

                TripCheckpoint::create([
                    'trip_id' => $trip->id,
                    'driver_id' => $user->id,
                    'shift_id' => $shift->id,
                    'checkpoint_type' => CheckpointType::DriverSwap->value,
                    'occurred_at' => now(),
                    'km_reading' => $payload['end_km'] ?? null,
                ]);
            }

            app(ShiftKmCalculatorService::class)->calculate($shift);

            // update vehicle info - use latest trip's vehicle
            $latestTrip = $shift->trips()->latest('started_at')->first();
            $vehicle = $latestTrip?->vehicle;
            if ($vehicle) {
                if (isset($payload['end_km'])) {
                    $vehicle->current_mileage = $payload['end_km'];
                }
                if (isset($payload['end_gps_lat'])) {
                    $vehicle->gps_lat = $payload['end_gps_lat'];
                }
                if (isset($payload['end_gps_lng'])) {
                    $vehicle->gps_lng = $payload['end_gps_lng'];
                }
                $vehicle->save();
            }

            DB::commit();

            return response()->json(['shift' => DriverShiftResource::make($shift->load(['driver', 'trips.vehicle']))]);
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
            ->latest('start_time')
            ->first();

        // only return the active shift if it started today
        if ($shift) {
            $start = $shift->start_time ? Carbon::parse($shift->start_time) : null;
            if (! $start || ! $start->isToday()) {
                return response()->json(['shift' => null]);
            }
        }

        return response()->json(['shift' => $shift ? DriverShiftResource::make($shift->load(['driver', 'trips.vehicle'])) : null]);
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
            ->whereNull('end_time')
            ->first();

        if (! $shift) {
            return response()->json(['message' => 'No active shift found'], 404);
        }

        DB::beginTransaction();
        try {
            // Update old vehicle: mileage and GPS
            Vehicle::where('id', $payload['new_vehicle_id'])
                ->update([
                    'current_mileage' => $payload['handover_km'],
                    'gps_lat' => $payload['handover_gps_lat'] ?? null,
                    'gps_lng' => $payload['handover_gps_lng'] ?? null,
                ]);

            DB::commit();

            return response()->json([
                'shift' => DriverShiftResource::make($shift->load(['driver', 'trips.vehicle'])),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json(['message' => 'Unable to switch vehicle', 'error' => $e->getMessage()], 500);
        }
    }
}
