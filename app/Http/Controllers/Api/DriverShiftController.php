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
    #[BodyParameter('start_time', type: 'string', format: 'date-time', description: 'Thời điểm bắt đầu ca.', example: '2026-05-20T07:15:22Z')]
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

        // prevent creating two shifts of the same type on the same day
        $sameDaySameType = DriverShift::query()
            ->where('driver_id', $user->id)
            ->whereDate('start_time', $startTime->toDateString())
            ->where('shift_type', $payload['shift_type'])
            ->exists();

        if ($sameDaySameType) {
            return response()->json(['message' => 'Đã có ca cùng loại vào hôm nay'], 409);
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
            if ($vehicleId !== null) {
                // Release driver from any other vehicle they currently have
                Vehicle::where('current_driver_id', $user->id)
                    ->where('id', '!=', $vehicleId)
                    ->update(['current_driver_id' => null]);

                // Assign driver to the selected vehicle
                $activeVehicle->current_driver_id = $user->id;
                $activeVehicle->save();
            }

            $shift = DriverShift::create([
                'driver_id' => $user->id,
                'shift_type' => $payload['shift_type'],
                'start_time' => $payload['start_time'] ?? now(),
            ]);

            // Create initial shift vehicle segment
            $shift->shiftVehicles()->create([
                'vehicle_id' => $activeVehicle->id,
                'start_time' => $payload['start_time'] ?? now(),
                'start_km' => $activeVehicle->current_mileage ?? 0,
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
            $shift->end_time = $payload['end_time'] ?? now();
            $shift->save();

            // End current shift vehicle segment
            $currentSegment = $shift->currentShiftVehicle();
            if ($currentSegment) {
                $currentSegment->end_time = $shift->end_time;
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
    #[BodyParameter('order_id', type: 'integer', description: 'ID đơn hàng tương ứng với xe mới.', required: false)]
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
            // End current segment
            $currentSegment->end_time = now();
            $currentSegment->end_km = $payload['handover_km'];
            $currentSegment->end_gps_lat = $payload['handover_gps_lat'] ?? null;
            $currentSegment->end_gps_lng = $payload['handover_gps_lng'] ?? null;
            $currentSegment->save();

            // Create new segment
            $shift->shiftVehicles()->create([
                'vehicle_id' => $payload['new_vehicle_id'],
                'order_id' => $payload['order_id'] ?? null,
                'start_time' => now(),
                'start_km' => $payload['handover_km'],
                'start_gps_lat' => $payload['handover_gps_lat'] ?? null,
                'start_gps_lng' => $payload['handover_gps_lng'] ?? null,
            ]);

            // Update old vehicle: remove driver
            Vehicle::where('id', $currentSegment->vehicle_id)
                ->where('current_driver_id', $user->id)
                ->update(['current_driver_id' => null]);

            // Update new vehicle: assign driver + mileage
            Vehicle::where('id', $payload['new_vehicle_id'])->update([
                'current_driver_id' => $user->id,
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
