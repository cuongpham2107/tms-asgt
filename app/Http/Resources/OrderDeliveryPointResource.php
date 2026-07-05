<?php

namespace App\Http\Resources;

use App\Models\OrderDeliveryPoint;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property OrderDeliveryPoint $resource
 */
class OrderDeliveryPointResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /** ID của điểm giao hàng. */
            'id' => $this->id,
            /** Thứ tự giao hàng (1, 2, 3...). */
            'sequence' => $this->sequence,
            /** Địa chỉ giao hàng cụ thể. */
            'address' => $this->address,
            'code' => $this->location->code,
            /** Người liên hệ nhận hàng. */
            'contact_person' => $this->contact_person,
            /** Số điện thoại người nhận. */
            'contact_phone' => $this->contact_phone,
            /** Số kiện hàng cần giao tại điểm này. */
            'total_packages' => $this->total_packages,
            /** Khối lượng hàng giao tại điểm này (tấn). */
            'total_weight' => $this->total_weight,
            /** Trạng thái giao hàng tại điểm này (pending, arrived, completed). */
            'status' => $this->status,
            /** Nhãn tiếng Việt của trạng thái giao hàng tại điểm này. */
            'status_label' => $this->status?->getLabel(),
            /** Thời điểm xe đến điểm giao (ISO 8601). */
            'arrived_at' => $this->arrived_at?->toIso8601String(),
            /** Thời điểm hoàn tất giao hàng tại điểm này (ISO 8601). */
            'delivered_at' => $this->delivered_at?->toIso8601String(),
        ];
    }
}
