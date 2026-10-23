<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * Mốc bị từ chối vì sai vị trí. Kèm dữ liệu để app hiện popup riêng.
 */
class CheckpointGeofenceException extends ValidationException
{
    /**
     * @param  array{layer: string, distance_m: ?int, radius_m: int, target_code: ?string}  $geofence
     */
    public function __construct(string $message, public readonly array $geofence)
    {
        parent::__construct(validator([], [])->after(fn ($v) => $v->errors()->add('gps_lat', $message)));
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->validator->errors()->first(),
            'errors' => $this->errors(),
            'geofence' => $this->geofence,
        ], 422);
    }
}
