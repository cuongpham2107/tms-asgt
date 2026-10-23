<?php

namespace App\Http\Controllers\Api;

use App\Enums\TripStatus;
use App\Exceptions\CheckpointGeofenceException;
use App\Http\Controllers\Controller;
use App\Http\Requests\TripCheckpointRequest;
use App\Http\Resources\TripCheckpointResource;
use App\Models\DriverShift;
use App\Models\Trip;
use App\Services\Trip\TripCheckpointService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class TripCheckpointController extends Controller
{
    public function __construct(
        private readonly TripCheckpointService $service,
    ) {}

    public function checkpoint(TripCheckpointRequest $request, Trip $trip): JsonResponse
    {
        $user = $request->user();

        if ($trip->driver_id !== $user->id) {
            return response()->json(['message' => 'Bạn không phải tài xế được gán cho chuyến này'], 403);
        }

        if ($trip->isPaused()) {
            return response()->json([
                'message' => 'Chuyến xe đang tạm dừng do có báo sai số Km đang chờ Điều hành xử lý. Vui lòng liên hệ Điều hành trước khi cập nhật mốc tiếp theo.',
                'is_paused' => true,
                'pause_reason' => $trip->paused_reason ?? 'Chờ điều chỉnh báo sai số Km',
            ], 422);
        }

        $activeShift = DriverShift::where('driver_id', $user->id)
            ->whereNull('end_time')
            ->first();

        if (! $activeShift) {
            return response()->json(['message' => 'Bạn cần vào ca trước khi cập nhật chuyến'], 422);
        }

        $photos = $request->file('photos');
        $photosArray = match (true) {
            is_array($photos) => $photos,
            $photos !== null => [$photos],
            default => null,
        };

        try {
            $result = $this->service->recordCheckpoint($trip, $request->validated(), $photosArray);
        } catch (CheckpointGeofenceException $e) {
            return $e->render();
        } catch (ValidationException $e) {
            $response = [
                'message' => $e->validator->errors()->first(),
                'errors' => $e->errors(),
            ];

            // Attach active_trip_id if driver has an active trip
            $activeTrip = Trip::getActiveCargoTripForDriver($user->id, $trip->id);
            if (! $activeTrip) {
                $activeTrip = Trip::where('driver_id', $user->id)
                    ->where('id', '!=', $trip->id)
                    ->whereIn('status', array_filter(
                        TripStatus::activeStatuses(),
                        fn (TripStatus $s) => ! in_array($s, [TripStatus::Pending, TripStatus::DriverSwap]),
                    ))
                    ->first();
            }
            if ($activeTrip) {
                $response['active_trip_id'] = $activeTrip->id;
            }

            return response()->json($response, 422);
        }

        return response()->json([
            'checkpoints' => TripCheckpointResource::collection($result),
        ]);
    }
}
