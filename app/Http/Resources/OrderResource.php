<?php

namespace App\Http\Resources;

use App\Enums\TripStatus;
use App\Models\Order;
use App\Models\Trip;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property Order $resource
 */
class OrderResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /** ID của đơn hàng. */
            'id' => $this->id,

            /** ID khách hàng. */
            'customer_id' => $this->customer_id,
            /** ID ca làm việc (từ chuyến xe, nếu có). */
            'shift_id' => $this->trip?->shift_id,
            /** ID chuyến xe (nếu đang gom). */
            'trip_id' => $this->trip_id,
            /** Thông tin chuyến xe (nếu được load). */
            'trip' => $this->whenLoaded('trip', fn () => [
                'id' => $this->trip->id,
                'status' => $this->trip->status,
            ]),
            /** ID khu vực (từ chuyến xe, nếu có). */
            'area_id' => $this->area_id,
            /** Biển số xe (từ chuyến xe, nếu có). */
            'vehicle_plate_number' => $this->trip?->vehicle?->plate_number,
            /** Loại xe (từ chuyến xe, nếu có). */
            'vehicle_type' => $this->trip?->vehicle?->vehicle_type,
            /** Thông tin xe (từ chuyến xe, nếu được load). */
            'vehicle' => $this->when(
                $this->relationLoaded('trip') && $this->trip?->relationLoaded('vehicle'),
                fn () => [
                    'id' => $this->trip->vehicle->id,
                    'plate_number' => $this->trip->vehicle->plate_number,
                    'current_mileage' => $this->trip->vehicle->current_mileage,
                ]
            ),
            /** Thông tin khách hàng (nếu được load). */
            'customer' => $this->whenLoaded('customer', fn () => [
                'id' => $this->customer->id,
                'name' => $this->customer->name,
                'phone' => $this->customer->phone,
                'email' => $this->customer->email,
                'address' => $this->customer->address,
            ]),

            /** Loại đơn hàng (HHHK, external). */
            'type' => $this->type,
            /** Nhãn tiếng Việt của loại đơn hàng. */
            'type_label' => $this->type?->getLabel(),
            /** Mã đơn hàng độc nhất. */
            'order_code' => $this->order_code,
            /** Trạng thái đơn hàng (draft, assigned, sent, completed, cancelled). */
            'status' => $this->status,
            /** Nhãn tiếng Việt của trạng thái đơn hàng. */
            'status_label' => $this->status?->getLabel(),
            /** Độ ưu tiên của đơn hàng (low, medium, high, urgent). */
            'priority' => $this->priority,
            /** Nhãn tiếng Việt của độ ưu tiên đơn hàng. */
            'priority_label' => $this->priority?->getLabel(),
            /** Tên hàng hóa. */
            'cargo_name' => $this->cargo_name,
            /** Loại hàng hóa. */
            'cargo_type' => $this->cargo_type,
            /** Nhãn tiếng Việt của loại hàng hóa. */
            'cargo_type_label' => $this->cargo_type?->getLabel(),
            /** Tổng số kiện hàng. */
            'total_packages' => $this->total_packages,
            /** Tổng khối lượng hàng hóa (tấn). */
            'total_weight' => $this->total_weight,
            /** Thời điểm dự kiến bốc xếp hàng (ISO 8601). */
            'planned_loading_at' => $this->planned_loading_at?->toIso8601String(),
            // Pickup
            /** ID địa điểm lấy hàng. */
            'pickup_location_id' => $this->pickup_location_id,
            /** Chi tiết địa điểm lấy hàng (nếu được load). */
            'pickup_location' => $this->whenLoaded('pickupLocation', fn () => [
                'id' => $this->pickupLocation->id,
                'name' => $this->pickupLocation->name,
                'address' => $this->pickupLocation->address,
                'lat' => $this->pickupLocation->lat,
                'lng' => $this->pickupLocation->lng,
            ]),
            /** Địa chỉ lấy hàng thực tế. */
            'pickup_address' => $this->pickup_address,
            /** Người liên hệ tại kho lấy hàng. */
            'pickup_contact' => $this->pickup_contact,
            /** Điện thoại liên hệ lấy hàng. */
            'pickup_phone' => $this->pickup_phone,
            /** Ghi chú cho đơn hàng. */
            'notes' => $this->notes,
            // Delivery points (only when loaded)
            /** Danh sách các điểm giao hàng (nếu được load). */
            'delivery_points' => OrderDeliveryPointResource::collection($this->whenLoaded('deliveryPoints')),
            // Trip checkpoints (only when loaded)
            /** Danh sách checkpoint hành trình (nếu được load). */
            'trip_checkpoints' => TripCheckpointResource::collection($this->whenLoaded('tripCheckpoints')),
            // Latest checkpoint time (progress indicator)
            /** Thời điểm ghi nhận checkpoint mới nhất (ISO 8601, nếu được load). */
            'last_checkpoint_at' => $this->when(
                $this->relationLoaded('tripCheckpoints') && $this->tripCheckpoints->isNotEmpty(),
                fn () => $this->tripCheckpoints->max('occurred_at')?->toIso8601String()
            ),
            // Driver swaps (only when loaded)
            /** Danh sách các lần đổi lái (từ chuyến xe, nếu được load). */
            'driver_swaps' => DriverSwapResource::collection(
                $this->whenLoaded('trip.driverSwaps', fn () => $this->trip->driverSwaps)
            ),
            // Timestamps
            /** Thời điểm gửi lệnh điều hành (ISO 8601). */
            'sent_at' => $this->sent_at?->toIso8601String(),
            /** Thời điểm tạo đơn hàng (ISO 8601). */
            'created_at' => $this->created_at?->toIso8601String(),

            /** Lái xe đã có chuyến đang hoạt động hay chưa. */
            'has_active_order' => $this->whenLoaded('trip', function () {
                return Trip::where('driver_id', $this->trip->driver_id)
                    ->whereIn('status', [
                        TripStatus::Started,
                        TripStatus::ArrivedPickup,
                        TripStatus::Delivering,
                        TripStatus::ArrivedDelivery,
                        TripStatus::DriverSwap,
                    ])
                    ->where('id', '!=', $this->trip->id)
                    ->exists();
            }),
        ];
    }
}
