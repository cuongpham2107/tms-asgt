<?php

namespace App\Http\Controllers\Api;

use App\Enums\CheckpointType;
use App\Enums\OrderDeliveryPointStatus;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\CheckpointRequest;
use App\Http\Resources\TripCheckpointResource;
use App\Models\Order;
use App\Models\OrderDeliveryPoint;
use App\Models\TripCheckpoint;
use App\Models\TripPhoto;
use App\Models\Vehicle;
use Dedoc\Scramble\Attributes\BodyParameter;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class TripCheckpointController extends Controller
{
    #[BodyParameter('order_id', type: 'integer', description: 'ID đơn hàng.', required: true, example: 1001)]
    #[BodyParameter('shift_id', type: 'integer', description: 'ID ca trực tương ứng (nếu có).', example: 88)]
    #[BodyParameter('delivery_point_id', type: 'integer', description: 'ID điểm giao cụ thể (nếu đơn có nhiều điểm).', example: 501)]
    #[BodyParameter('checkpoint_type', type: 'string', description: 'Loại mốc hành trình. Giá trị hỗ trợ: started (Bắt đầu chuyến), arrived_pickup (Đến lấy hàng), left_pickup (Rời lấy hàng), arrived_delivery (Đến giao hàng), completed (Hoàn thành), driver_swap (Đảo lái).', required: true, example: 'arrived_pickup')]
    #[BodyParameter('occurred_at', type: 'string', format: 'date-time', description: 'Thời điểm thực tế phát sinh mốc.', example: '2026-05-20T07:15:22Z')]
    #[BodyParameter('km_reading', type: 'number', description: 'Số km đồng hồ tại thời điểm ghi nhận.', example: 12540.5)]
    #[BodyParameter('gps_lat', type: 'string', description: 'Vĩ độ GPS tại thời điểm ghi nhận.', example: '10,823099')]
    #[BodyParameter('gps_lng', type: 'string', description: 'Kinh độ GPS tại thời điểm ghi nhận.', example: '106,629662')]
    #[BodyParameter('voice_note', type: 'string', description: 'Ghi chú giọng nói đã chuyển thành văn bản.', example: 'Đã đến điểm lấy hàng, chờ bốc xếp.')]
    #[BodyParameter('photos', type: 'array', description: 'Danh sách ảnh đính kèm checkpoint.')]
    public function checkpoint(CheckpointRequest $request): JsonResponse
    {
        $user = $request->user();
        $payload = $request->validated();
        DB::beginTransaction();
        try {
            $order = Order::findOrFail($payload['order_id']);

            if ($order->driver_id !== $user->id) {
                return response()->json(['message' => 'Bạn không phải tài xế được gán cho đơn hàng này'], 403);
            }

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

            $this->updateVehicleFromCheckpoint($order, $payload);

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

            match ($checkpoint->checkpoint_type) {
                CheckpointType::Started => $this->handleStarted($order),
                CheckpointType::ArrivedPickup => $this->handleArrivedPickup($order, $payload),
                CheckpointType::LeftPickup => $this->handleLeftPickup($order),
                CheckpointType::ArrivedDelivery => $this->handleArrivedDelivery($order, $payload),
                CheckpointType::Completed => $this->handleCompleted($order, $payload),
                CheckpointType::DriverSwap => null,
            };

            DB::commit();

            $checkpoint->load('photos');

            return response()->json(['checkpoint' => TripCheckpointResource::make($checkpoint)]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json(['message' => 'Unable to record checkpoint', 'error' => $e->getMessage()], 500);
        }
    }

    private function updateVehicleFromCheckpoint(Order $order, array $payload): void
    {
        if ($order->vehicle_id === null) {
            return;
        }

        $vehicle = Vehicle::find($order->vehicle_id);
        if ($vehicle === null) {
            return;
        }

        $dirty = false;

        if (isset($payload['km_reading'])) {
            $vehicle->current_mileage = $payload['km_reading'];
            $dirty = true;
        }

        if (isset($payload['gps_lat'])) {
            $vehicle->gps_lat = $payload['gps_lat'];
            $dirty = true;
        }

        if (isset($payload['gps_lng'])) {
            $vehicle->gps_lng = $payload['gps_lng'];
            $dirty = true;
        }

        if ($dirty) {
            $vehicle->save();
        }
    }

    private function handleStarted(Order $order): void
    {
        $order->status = OrderStatus::Started;
        if ($order->sent_at === null) {
            $order->sent_at = now();
        }
        $order->save();

        if ($order->vehicle_id !== null) {
            Vehicle::where('id', $order->vehicle_id)->update(['current_driver_id' => $order->driver_id]);
        }
    }

    private function handleArrivedPickup(Order $order, array $payload): void
    {
        $order->status = OrderStatus::ArrivedPickup;
        $order->save();

        $this->updateDeliveryPoint($payload, OrderDeliveryPointStatus::Arrived);
    }

    private function handleLeftPickup(Order $order): void
    {
        $order->status = OrderStatus::Delivering;
        $order->save();
    }

    private function handleArrivedDelivery(Order $order, array $payload): void
    {
        $order->status = OrderStatus::ArrivedDelivery;
        $order->save();

        $this->updateDeliveryPoint($payload, OrderDeliveryPointStatus::Arrived);
    }

    private function handleCompleted(Order $order, array $payload): void
    {
        $this->updateDeliveryPoint($payload, OrderDeliveryPointStatus::Delivered);

        $hasPendingDeliveryPoint = $order->deliveryPoints()
            ->where('status', '!=', OrderDeliveryPointStatus::Delivered)
            ->exists();

        if (! $hasPendingDeliveryPoint) {
            $order->status = OrderStatus::Completed;
            $order->save();

            if ($order->vehicle_id !== null) {
                Vehicle::where('id', $order->vehicle_id)->update(['current_driver_id' => null]);
            }
        }
    }

    private function updateDeliveryPoint(array $payload, OrderDeliveryPointStatus $status): void
    {
        $deliveryPointId = $payload['delivery_point_id'] ?? null;
        if ($deliveryPointId === null) {
            return;
        }

        $point = OrderDeliveryPoint::find($deliveryPointId);
        if ($point === null) {
            return;
        }

        if ($status === OrderDeliveryPointStatus::Arrived && $point->status !== OrderDeliveryPointStatus::Pending) {
            return;
        }

        if ($status === OrderDeliveryPointStatus::Delivered && $point->status === OrderDeliveryPointStatus::Delivered && $point->delivered_at !== null) {
            return;
        }

        $point->status = $status;
        if ($status === OrderDeliveryPointStatus::Arrived) {
            $point->arrived_at = $payload['occurred_at'] ?? now();
        } elseif ($status === OrderDeliveryPointStatus::Delivered) {
            $point->delivered_at = $payload['occurred_at'] ?? now();
        }
        $point->save();
    }
}
