<?php

namespace App\Http\Controllers\Api;

use App\Enums\CheckpointType;
use App\Enums\OrderStatus;
use App\Enums\TripStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\EndShiftRequest;
use App\Http\Requests\EndVehicleRequest;
use App\Http\Requests\StartShiftRequest;
use App\Http\Requests\SwitchVehicleRequest;
use App\Http\Resources\DriverShiftResource;
use App\Models\DriverShift;
use App\Models\Order;
use App\Models\Trip;
use App\Models\TripCheckpoint;
use App\Models\Vehicle;
use App\Services\ShiftKmCalculatorService;
use App\Services\Trip\Handlers\EndHandler;
use App\Services\TripKmCalculatorService;
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

        // // Check vehicle requirements
        // $vehicleId = $payload['vehicle_id'] ?? null;
        // $currentVehicle = $user->vehiclesAsDriver()->first();

        // if ($currentVehicle === null && $vehicleId === null) {
        //     return response()->json(['message' => 'Vui lòng chọn phương tiện để bắt đầu ca.'], 422);
        // }

        // $activeVehicle = $currentVehicle;
        // if ($vehicleId !== null) {
        //     $activeVehicle = Vehicle::find($vehicleId);
        //     if ($activeVehicle === null) {
        //         return response()->json(['message' => 'Phương tiện không hợp lệ.'], 422);
        //     }
        // }

        // prevent invalid shift combinations on the same day
        // $existingShiftsToday = DriverShift::query()
        //     ->where('driver_id', $user->id)
        //     ->whereDate('start_time', $startTime->toDateString())
        //     ->get();

        // $requestedType = $payload['shift_type'];

        // foreach ($existingShiftsToday as $shift) {
        //     $existingType = $shift->shift_type->value ?? $shift->shift_type;

        //     if ($requestedType === 'full') {
        //         return response()->json(['message' => 'Không thể tạo ca cả ngày vì bạn đã có ca khác trong hôm nay.'], 409);
        //     }

        //     if ($existingType === 'full') {
        //         return response()->json(['message' => 'Bạn đã có ca cả ngày trong hôm nay, không thể tạo thêm ca mới.'], 409);
        //     }

        //     if ($requestedType === $existingType) {
        //         return response()->json(['message' => 'Đã có ca cùng loại vào hôm nay.'], 409);
        //     }
        // }

        // Ensure driver does not have an open shift
        // $existing = DriverShift::query()
        //     ->where('driver_id', $user->id)
        //     ->whereNull('end_time')
        //     ->first();

        // if ($existing) {
        //     /** @status 409 */
        //     return response()->json(['message' => 'Bạn đã có một ca làm việc đang hoạt động'], 409);
        // }

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
            return response()->json(['message' => 'Không thể bắt đầu ca làm việc', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Nhập km khi rời xe (checkpoint type = end).
     *
     * @response array{checkpoint: array, vehicle: array}
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

        if ($shift->end_time !== null) {
            return response()->json(['message' => 'Ca đã kết thúc'], 422);
        }

        // Find vehicle: from any trip on this shift (even completed ones — driver
        // may still be in the same vehicle after all trips are done)
        $vehicle = $shift->trips()
            ->latest('started_at')
            ->first()?->vehicle;

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

        $checkpoint = app(EndHandler::class)->handle($shift, $vehicle, $kmReading);

        return response()->json([
            'checkpoint' => $checkpoint->toArray(),
            'vehicle' => ['id' => $vehicle->id, 'current_mileage' => $vehicle->fresh()->current_mileage],
        ]);
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
            ->latest('start_time')
            ->first();

        if (! $shift) {
            /** @status 404 */
            return response()->json(['message' => 'Không tìm thấy ca làm việc đang hoạt động'], 404);
        }

        $hasTrips = $shift->trips()->exists();

        // Gate: phải có checkpoint type='end' cho xe hiện tại trước khi kết thúc ca
        // (chỉ bắt buộc nếu ca có chuyến — không có chuyến nào thì không cần)
        $endCheckpoint = TripCheckpoint::where('shift_id', $shift->id)
            ->where('checkpoint_type', CheckpointType::End->value)
            ->whereNotNull('km_reading')
            ->latest('id')
            ->first();

        if ($hasTrips && $endCheckpoint === null) {
            /** @status 422 */
            return response()->json(['message' => 'Cần nhập km kết thúc trước khi kết thúc ca.'], 422);
        }

        // Use km_reading from the 'end' checkpoint (NOT from payload — avoids double entry drift)
        $endKm = $endCheckpoint ? (float) $endCheckpoint->km_reading : ($payload['end_km'] ?? null);

        DB::beginTransaction();
        try {
            $shift->end_time = now();
            $shift->end_km = $endKm;
            $shift->end_gps_lat = $payload['end_gps_lat'] ?? null;
            $shift->end_gps_lng = $payload['end_gps_lng'] ?? null;
            $shift->save();

            // Auto driver_swap: chuyển trip đang active có đơn hàng chưa hoàn thành sang driver_swap
            $incompleteTrips = Trip::where('driver_id', $user->id)
                ->whereHas('orders', function ($q) {
                    $q->whereIn('status', [OrderStatus::Sent->value, OrderStatus::InTransit->value, OrderStatus::Assigned->value]);
                })
                ->whereIn('status', [
                    TripStatus::Started,
                    TripStatus::ArrivedPickup,
                    TripStatus::Delivering,
                    TripStatus::ArrivedDelivery,
                ])
                ->get();

            foreach ($incompleteTrips as $trip) {
                if ($endKm > 0) {
                    app(TripKmCalculatorService::class)->calculate($trip, endKm: $endKm);
                    $trip->refresh();
                }

                $trip->status = TripStatus::DriverSwap;
                $trip->shift_id = null;
                $trip->save();

                $trip->orders()
                    ->whereIn('status', [OrderStatus::Sent->value, OrderStatus::InTransit->value])
                    ->update(['status' => OrderStatus::DriverSwap->value]);

                TripCheckpoint::create([
                    'trip_id' => $trip->id,
                    'driver_id' => $user->id,
                    'shift_id' => $shift->id,
                    'checkpoint_type' => CheckpointType::DriverSwap->value,
                    'occurred_at' => now(),
                    'km_reading' => $endKm,
                ]);
            }

            app(ShiftKmCalculatorService::class)->calculate($shift);

            // Clean up trips that were driver_swapped via EndHandler
            // (status=DriverSwap but still linked to this shift)
            $driverSwappedTrips = Trip::where('driver_id', $user->id)
                ->where('shift_id', $shift->id)
                ->where('status', TripStatus::DriverSwap)
                ->get();

            foreach ($driverSwappedTrips as $trip) {
                $trip->shift_id = null;
                $trip->save();
            }

            // Update vehicle mileage from the 'end' checkpoint's km_reading
            if ($endCheckpoint->vehicle_id) {
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

            return response()->json(['shift' => DriverShiftResource::make($shift->load(['driver', 'trips.vehicle']))]);
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
            ->whereNull('end_time')
            ->latest('start_time')
            ->first();

        // only return the active shift if it started today
        // if ($shift) {
        //     $start = $shift->start_time ? Carbon::parse($shift->start_time) : null;
        //     if (! $start || ! $start->isToday()) {
        //         return response()->json(['shift' => null]);
        //     }
        // }

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
            return response()->json(['message' => 'Không tìm thấy ca làm việc đang hoạt động'], 404);
        }

        // Gate: must have an 'end' checkpoint before switching vehicle
        $endCheckpoint = TripCheckpoint::where('shift_id', $shift->id)
            ->where('checkpoint_type', CheckpointType::End->value)
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
                'shift' => DriverShiftResource::make($shift->load(['driver', 'trips.vehicle'])),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json(['message' => 'Không thể chuyển xe', 'error' => $e->getMessage()], 500);
        }
    }
}
