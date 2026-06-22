<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\EndShiftRequest;
use App\Http\Requests\StartShiftRequest;
use App\Http\Requests\SwitchVehicleRequest;
use App\Http\Resources\DriverShiftResource;
use App\Models\DriverShift;
use App\Models\Order;
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

            // Create initial shift vehicle segment (tracks vehicle usage, not orders)
            $shift->shiftVehicles()->create([
                'vehicle_id' => $activeVehicle->id,
                'start_time' => $startTime,
                'start_km' => $startKm,
                'start_gps_lat' => $payload['start_gps_lat'] ?? null,
                'start_gps_lng' => $payload['start_gps_lng'] ?? null,
            ]);

            DB::commit();

            return response()->json(['shift' => DriverShiftResource::make($shift->load(['driver', 'shiftVehicles.vehicle']))]);
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

            // End current shift vehicle segment
            $currentSegment = $shift->currentShiftVehicle();
            if ($currentSegment) {
                $currentSegment->end_time = $endTime;
                $currentSegment->end_km = $payload['end_km'] ?? $currentSegment->end_km;
                $currentSegment->end_gps_lat = $payload['end_gps_lat'] ?? null;
                $currentSegment->end_gps_lng = $payload['end_gps_lng'] ?? null;
                $currentSegment->save();
            }

            // Auto driver_swap: chuyển đơn đang active sang driver_swap
            $activeOrders = Order::query()
                ->where('driver_id', $user->id)
                ->whereIn('status', [
                    OrderStatus::Started,
                    OrderStatus::ArrivedPickup,
                    OrderStatus::Delivering,
                    OrderStatus::ArrivedDelivery,
                ])
                ->get();

            foreach ($activeOrders as $activeOrder) {
                $activeOrder->status = OrderStatus::DriverSwap;
                $activeOrder->save();
            }

            app(ShiftKmCalculatorService::class)->calculate($shift);

            // update vehicle info - use last vehicle from segments
            $lastSegment = $shift->lastSegment();
            $vehicle = $lastSegment ? Vehicle::find($lastSegment->vehicle_id) : null;
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

            return response()->json(['shift' => DriverShiftResource::make($shift->load(['driver', 'shiftVehicles.vehicle']))]);
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

        return response()->json(['shift' => $shift ? DriverShiftResource::make($shift->load(['driver', 'shiftVehicles.vehicle'])) : null]);
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

        $currentSegment = $shift->currentShiftVehicle();
        if (! $currentSegment) {
            return response()->json(['message' => 'No active vehicle segment found'], 404);
        }

        if ((int) $currentSegment->vehicle_id === (int) $payload['new_vehicle_id']) {
            return response()->json(['message' => 'Xe mới phải khác xe hiện tại'], 422);
        }

        DB::beginTransaction();
        try {
            // End current vehicle usage segment
            $currentSegment->end_time = now();
            $currentSegment->end_km = $payload['handover_km'];
            $currentSegment->end_gps_lat = $payload['handover_gps_lat'] ?? null;
            $currentSegment->end_gps_lng = $payload['handover_gps_lng'] ?? null;
            $currentSegment->save();

            // Create new vehicle usage segment
            $shift->shiftVehicles()->create([
                'vehicle_id' => $payload['new_vehicle_id'],
                'start_time' => now(),
                'start_km' => $payload['handover_km'],
                'start_gps_lat' => $payload['handover_gps_lat'] ?? null,
                'start_gps_lng' => $payload['handover_gps_lng'] ?? null,
            ]);

            // Note: Vehicle.current_driver_id is a static/default field — not modified here.
            // Driver-vehicle assignment is tracked via Order.driver_id/vehicle_id and DriverSwap.

            // Update old vehicle: mileage and GPS
            Vehicle::where('id', $currentSegment->vehicle_id)
                ->update([
                    'current_mileage' => $payload['handover_km'],
                    'gps_lat' => $payload['handover_gps_lat'] ?? null,
                    'gps_lng' => $payload['handover_gps_lng'] ?? null,
                ]);

            // Update new vehicle: mileage only (no driver assignment)
            Vehicle::where('id', $payload['new_vehicle_id'])->update([
                'current_mileage' => $payload['handover_km'],
            ]);

            DB::commit();

            return response()->json([
                'shift' => DriverShiftResource::make($shift->load(['driver', 'shiftVehicles.vehicle'])),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json(['message' => 'Unable to switch vehicle', 'error' => $e->getMessage()], 500);
        }
    }
}
