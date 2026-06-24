<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\TripCheckpointRequest;
use App\Http\Resources\TripCheckpointResource;
use App\Models\Trip;
use App\Services\Trip\TripCheckpointService;
use Illuminate\Http\JsonResponse;

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

        $result = $this->service->recordCheckpoint($trip, $request->validated(), $request->file('photos'));

        return response()->json([
            'checkpoints' => TripCheckpointResource::collection($result),
        ]);
    }
}
