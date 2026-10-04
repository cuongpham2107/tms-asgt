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
use App\Models\DriverSwap;
use App\Models\Trip;
use App\Models\TripCheckpoint;
use App\Models\Vehicle;
use App\Services\Trip\Handlers\EndHandler;
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

            // Gán to_shift_id cho DriverSwap đang chờ của tài xế này
            DriverSwap::where('to_driver_id', $user->id)
                ->whereNull('to_shift_id')
                ->update(['to_shift_id' => $shift->id]);

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
    public function endVehicle(EndVehicleRequest $request, DriverShift $shift): JsonResponse
    {
        $user = $request->user();

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
            ->first()?->vehicle
            ?? $user->vehiclesAsDriver()->first();

        if ($vehicle === null) {
            return response()->json(['message' => 'Không tìm thấy xe đang hoạt động trong ca này'], 404);
        }

        $checkpoint = app(EndHandler::class)->handle($shift, $vehicle);

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
            ->where('start_time', '<=', now())
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
            ->whereIn('checkpoint_type', [CheckpointType::End->value, CheckpointType::DriverSwap->value])
            ->latest('id')
            ->first();

        if ($hasTrips && $endCheckpoint === null) {
            /** @status 422 */
            return response()->json(['message' => 'Cần kết thúc xe trước khi kết thúc ca.'], 422);
        }

        // Gate: không cho kết thúc ca nếu còn trip đang chạy với đơn hàng chưa hoàn thành (Sent, InTransit)
        $incompleteTrips = Trip::where('driver_id', $user->id)
            ->where(function ($query) {
                $query->whereHas('orders', function ($q) {
                    $q->whereIn('status', [OrderStatus::Sent->value, OrderStatus::InTransit->value]);
                })->orWhere(function ($q) {
                    $q->where('is_empty_run', true)
                        ->whereIn('status', [
                            TripStatus::Started,
                            TripStatus::ArrivedPickup,
                            TripStatus::Delivering,
                            TripStatus::ArrivedDelivery,
                            TripStatus::Delivered,
                            TripStatus::ReturnTrip,
                        ]);
                });
            })
            ->whereIn('status', [
                TripStatus::Started,
                TripStatus::ArrivedPickup,
                TripStatus::Delivering,
                TripStatus::ArrivedDelivery,
                TripStatus::Delivered,
                TripStatus::ReturnTrip,
            ])
            ->get();

        if ($incompleteTrips->isNotEmpty()) {
            $codes = $incompleteTrips->pluck('trip_code')->filter()->implode(', ');
            $orderCount = $incompleteTrips->sum(function (Trip $trip) {
                return $trip->orders()
                    ->whereIn('status', [OrderStatus::Sent->value, OrderStatus::InTransit->value])
                    ->count();
            });

            return response()->json([
                'message' => "Bạn có {$incompleteTrips->count()} chuyến đang hoạt động ({$codes}) với {$orderCount} đơn hàng chưa hoàn thành. Vui lòng hoàn thành tất cả đơn hàng hoặc kết thúc đơn hàng trước khi kết thúc ca.",
            ], 422);
        }

        DB::beginTransaction();
        try {
            $shift->end_time = now();
            $shift->end_gps_lat = $payload['end_gps_lat'] ?? null;
            $shift->end_gps_lng = $payload['end_gps_lng'] ?? null;
            $shift->save();

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

            // Update vehicle GPS if provided
            if ($endCheckpoint?->vehicle_id) {
                $vehicleUpdate = [];
                if (isset($payload['end_gps_lat'])) {
                    $vehicleUpdate['gps_lat'] = $payload['end_gps_lat'];
                }
                if (isset($payload['end_gps_lng'])) {
                    $vehicleUpdate['gps_lng'] = $payload['end_gps_lng'];
                }
                if (! empty($vehicleUpdate)) {
                    Vehicle::where('id', $endCheckpoint->vehicle_id)->update($vehicleUpdate);
                }
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
            ->where('start_time', '<=', now())
            ->whereNull('end_time')
            ->latest('start_time')
            ->first();

        return response()->json(['shift' => $shift ? DriverShiftResource::make($shift->load(['driver', 'trips' => fn ($q) => $q->where('status', '!=', 'cancelled')->with('vehicle')])) : null]);
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
            ->latest('id')
            ->first();

        if ($endCheckpoint === null) {
            return response()->json(['message' => 'Cần kết thúc xe hiện tại trước khi chuyển xe.'], 422);
        }

        DB::beginTransaction();
        try {
            $vehicleUpdate = [];
            if (isset($payload['handover_gps_lat'])) {
                $vehicleUpdate['gps_lat'] = $payload['handover_gps_lat'];
            }
            if (isset($payload['handover_gps_lng'])) {
                $vehicleUpdate['gps_lng'] = $payload['handover_gps_lng'];
            }
            if (! empty($vehicleUpdate)) {
                Vehicle::where('id', $payload['new_vehicle_id'])->update($vehicleUpdate);
            }

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
