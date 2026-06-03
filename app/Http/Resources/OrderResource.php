<?php

namespace App\Http\Resources;

use App\Models\Order;
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
            /** ID ca làm việc. */
            'shift_id' => $this->shift_id,
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
            /** Trạng thái đơn hàng (draft, assigned, sent, started, arrived_pickup, delivering, arrived_delivery, delivered, completed, cancelled, driver_swap). */
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
            // Timestamps
            /** Thời điểm gửi lệnh điều hành (ISO 8601). */
            'sent_at' => $this->sent_at?->toIso8601String(),
            /** Thời điểm tạo đơn hàng (ISO 8601). */
            'created_at' => $this->created_at?->toIso8601String(),

            /** Lái xe đã có đơn đang hoạt động hay chưa. */
            'has_active_order' => Order::where('driver_id', $this->driver_id)
                ->whereIn('status', [
                    OrderStatus::Sent,
                    OrderStatus::Started,
                    OrderStatus::ArrivedPickup,
                    OrderStatus::Delivering,
                    OrderStatus::ArrivedDelivery,
                    OrderStatus::DriverSwap,
                ])
                ->where('id', '!=', $this->id)
                ->exists(),
        ];
    }
}
