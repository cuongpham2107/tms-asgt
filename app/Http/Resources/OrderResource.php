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
            // Sender/Receiver
            /** Tên người gửi hàng. */
            'sender_name' => $this->sender_name,
            /** Người liên hệ bên gửi. */
            'sender_contact' => $this->sender_contact,
            /** Điện thoại người gửi. */
            'sender_phone' => $this->sender_phone,
            /** Tên người nhận hàng. */
            'receiver_name' => $this->receiver_name,
            /** Người liên hệ bên nhận. */
            'receiver_contact' => $this->receiver_contact,
            /** Điện thoại người nhận. */
            'receiver_phone' => $this->receiver_phone,
            // Vehicle (compact)
            /** Thông tin xe vận chuyển (nếu được load). */
            'vehicle' => $this->whenLoaded('vehicle', fn () => [
                'id' => $this->vehicle->id,
                'plate_number' => $this->vehicle->plate_number,
                'load_capacity' => $this->vehicle->load_capacity,
            ]),
            // Freight
            /** Cước vận chuyển cơ bản. */
            'freight_rate' => $this->freight_rate,
            /** Các khoản phụ phí phát sinh. */
            'surcharges' => $this->surcharges,
            /** Tổng chi phí vận chuyển. */
            'total_cost' => $this->total_cost,
            /** Ghi chú cho đơn hàng. */
            'notes' => $this->notes,
            // Delivery points (only when loaded)
            /** Danh sách các điểm giao hàng (nếu được load). */
            'delivery_points' => OrderDeliveryPointResource::collection($this->whenLoaded('deliveryPoints')),
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
        ];
    }
}
