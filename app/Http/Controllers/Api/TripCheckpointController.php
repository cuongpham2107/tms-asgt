<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CheckpointRequest;
use App\Http\Resources\TripCheckpointResource;
use App\Models\TripCheckpoint;
use App\Models\TripPhoto;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class TripCheckpointController extends Controller
{
    /**
     * Ghi nhận checkpoint chuyến đi (kèm ảnh nếu có).
     *
     * @response array{checkpoint: TripCheckpointResource}
     */
    public function checkpoint(CheckpointRequest $request): JsonResponse
    {
        $user = $request->user();
        $payload = $request->validated();

        DB::beginTransaction();
        try {
            $checkpoint = TripCheckpoint::create([
                'order_id' => $payload['order_id'],
                'driver_id' => $user->id,
                'shift_id' => $payload['shift_id'] ?? null,
                'delivery_point_id' => $payload['delivery_point_id'] ?? null,
                'checkpoint_type' => $payload['checkpoint_type'],
                'occurred_at' => $payload['occurred_at'] ?? now(),
                'km_reading' => $payload['km_reading'] ?? null,
                'gps_lat' => $payload['gps_lat'] ?? null,
                'gps_lng' => $payload['gps_lng'] ?? null,
                'voice_note' => $payload['voice_note'] ?? null,
            ]);

            // handle photos upload
            if ($request->hasFile('photos')) {
                $files = $request->file('photos');
                foreach ($files as $file) {
                    $path = $file->store('trip_photos', 'public');
                    // ensure static analyzers know the disk adapter type so ->url() is available
                    /** @var FilesystemAdapter $disk */
                    $disk = Storage::disk('public');
                    TripPhoto::create([
                        'trip_checkpoint_id' => $checkpoint->id,
                        'photo_path' => $path,
                        'photo_url' => $disk->url($path),
                    ]);
                }
            }

            DB::commit();

            return response()->json(['checkpoint' => TripCheckpointResource::make($checkpoint)]);
        } catch (\Throwable $e) {
            DB::rollBack();

            /** @status 500 */
            return response()->json(['message' => 'Unable to record checkpoint', 'error' => $e->getMessage()], 500);
        }
    }
}
