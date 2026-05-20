<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CheckpointRequest;
use App\Http\Resources\TripCheckpointResource;
use App\Models\TripCheckpoint;
use App\Models\TripPhoto;
use Dedoc\Scramble\Attributes\BodyParameter;
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
    #[BodyParameter('order_id', type: 'integer', description: 'ID đơn hàng.', required: true, example: 1001)]
    #[BodyParameter('shift_id', type: 'integer', description: 'ID ca trực tương ứng (nếu có).', example: 88)]
    #[BodyParameter('delivery_point_id', type: 'integer', description: 'ID điểm giao cụ thể (nếu đơn có nhiều điểm).', example: 501)]
    #[BodyParameter('checkpoint_type', type: 'string', description: 'Loại mốc hành trình: started, arrived_pickup, left_pickup, arrived_delivery, completed, driver_swap.', required: true, example: 'arrived_pickup')]
    #[BodyParameter('occurred_at', type: 'string', format: 'date-time', description: 'Thời điểm thực tế phát sinh mốc.', example: '2026-05-20T07:15:22Z')]
    #[BodyParameter('km_reading', type: 'number', description: 'Số km đồng hồ tại thời điểm ghi nhận.', example: 12540.5)]
    #[BodyParameter('gps_lat', type: 'number', description: 'Vĩ độ GPS tại thời điểm ghi nhận.', example: 10.823099)]
    #[BodyParameter('gps_lng', type: 'number', description: 'Kinh độ GPS tại thời điểm ghi nhận.', example: 106.629662)]
    #[BodyParameter('voice_note', type: 'string', description: 'Ghi chú giọng nói đã chuyển thành văn bản.', example: 'Đã đến điểm lấy hàng, chờ bốc xếp.')]
    #[BodyParameter('photos', type: 'array', description: 'Danh sách ảnh đính kèm checkpoint.')]
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
