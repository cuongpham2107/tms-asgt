<?php

namespace App\Http\Controllers\Api;

use App\Enums\CheckpointType;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\DriverSwapRequest;
use App\Http\Resources\DriverSwapResource;
use App\Http\Resources\TripCheckpointResource;
use App\Models\DriverShift;
use App\Models\DriverSwap;
use App\Models\Order;
use App\Models\TripCheckpoint;
use App\Models\TripPhoto;
use Dedoc\Scramble\Attributes\BodyParameter;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class DriverSwapController extends Controller
{
    /**
     * Đảo lái — chuyển giao đơn hàng cho tài xế khác.
     *
     * @response array{data: DriverSwapResource, checkpoint: TripCheckpointResource}
     */
    #[BodyParameter('order_id', type: 'integer', description: 'ID đơn hàng cần đảo lái.', required: true, example: 1001)]
    #[BodyParameter('reason', type: 'string', description: 'Lý do đảo lái. Giá trị: shift_handover (Bàn giao ca), cargo_not_unloaded (Hàng chưa hạ được), other (Lý do khác).', required: true, example: 'shift_handover')]
    #[BodyParameter('handover_km', type: 'number', description: 'Số km đồng hồ tại thời điểm bàn giao.', example: 12540.5)]
    #[BodyParameter('note', type: 'string', description: 'Ghi chú thêm.', example: 'Bàn giao ca cho tài xế chiều.')]
    #[BodyParameter('from_shift_id', type: 'integer', description: 'ID ca trực của tài xế cũ (nếu có).', example: 88)]
    #[BodyParameter('gps_lat', type: 'string', description: 'Vĩ độ GPS tại thời điểm đảo lái.', example: '10,823099')]
    #[BodyParameter('gps_lng', type: 'string', description: 'Kinh độ GPS tại thời điểm đảo lái.', example: '106,629662')]
    #[BodyParameter('photos', type: 'array', description: 'Danh sách ảnh đính kèm.')]
    public function store(DriverSwapRequest $request): JsonResponse
    {
        $user = $request->user();
        $payload = $request->validated();

        DB::beginTransaction();
        try {
            $order = Order::findOrFail($payload['order_id']);

            if (! $order->status->canSwapDriver()) {
                return response()->json([
                    'message' => 'Đơn hàng không ở trạng thái có thể đảo lái.',
                ], 422);
            }

            if ($order->driver_id === $user->id) {
                return response()->json([
                    'message' => 'Bạn đã là tài xế của đơn hàng này.',
                ], 422);
            }

            // End the old driver's active shift so they stop accumulating KM
            $oldShift = DriverShift::where('driver_id', $order->driver_id)
                ->where('vehicle_id', $order->vehicle_id)
                ->whereNull('end_time')
                ->first();

            $fromShiftId = $oldShift?->id;

            if ($oldShift) {
                $oldShift->end_time = now();
                $oldShift->save();
            }

            // Check if the new driver already has an active shift on this vehicle
            $newShift = DriverShift::where('driver_id', $user->id)
                ->where('vehicle_id', $order->vehicle_id)
                ->whereNull('end_time')
                ->first();

            $toShiftId = $newShift?->id;

            $driverSwap = DriverSwap::create([
                'order_id' => $order->id,
                'from_driver_id' => $order->driver_id,
                'to_driver_id' => $user->id,
                'from_shift_id' => $fromShiftId ?? $payload['from_shift_id'] ?? null,
                'to_shift_id' => $toShiftId,
                'handover_km' => $payload['handover_km'] ?? null,
                'reason' => $payload['reason'],
                'note' => $payload['note'] ?? null,
                'created_by' => $user->id,
            ]);

            $checkpoint = TripCheckpoint::create([
                'order_id' => $order->id,
                'driver_id' => $user->id,
                'shift_id' => $fromShiftId ?? $payload['from_shift_id'] ?? null,
                'checkpoint_type' => CheckpointType::DriverSwap,
                'occurred_at' => now(),
                'km_reading' => $payload['handover_km'] ?? null,
                'gps_lat' => $payload['gps_lat'] ?? null,
                'gps_lng' => $payload['gps_lng'] ?? null,
            ]);

            if ($request->hasFile('photos')) {
                $files = Arr::wrap($request->file('photos'));
                foreach ($files as $file) {
                    if ($file === null) {
                        continue;
                    }

                    $path = $file->store('trip_photos', 'public');
                    /** @var FilesystemAdapter $disk */
                    $disk = Storage::disk('public');
                    TripPhoto::create([
                        'trip_checkpoint_id' => $checkpoint->id,
                        'photo_path' => $path,
                        'photo_url' => $disk->url($path),
                    ]);
                }
            }

            $order->driver_id = $user->id;
            $order->status = OrderStatus::DriverSwap;
            $order->shift_id = $toShiftId ?? $order->shift_id;
            $order->save();

            DB::commit();

            $checkpoint->load('photos');

            return response()->json([
                'data' => DriverSwapResource::make($driverSwap),
                'checkpoint' => TripCheckpointResource::make($checkpoint),
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json(['message' => 'Unable to swap driver', 'error' => $e->getMessage()], 500);
        }
    }
}
