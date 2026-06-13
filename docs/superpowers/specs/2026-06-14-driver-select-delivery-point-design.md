# Driver Chọn Điểm Đến Khi Đơn Chưa Có Delivery Point

## Vấn đề

Một số đơn hàng được admin tạo mà không gán điểm đến (`order_delivery_points` rỗng). Khi tài xế đến nơi giao hàng và gửi checkpoint `arrived_delivery`, hệ thống hiện không có cơ chế để tài xế tự chọn điểm đến.

## Yêu cầu

- Khi tài xế gửi `arrived_delivery` và đơn chưa có delivery point nào:
  - App mobile hiển thị form chọn location từ bảng `locations`
  - Location được chọn sẽ tự động tạo thành `OrderDeliveryPoint` (sequence=1)
  - Sau đó `arrived_delivery` được xử lý như bình thường
- Chỉ hỗ trợ 1 điểm đến duy nhất (đơn hàng đơn giản, 1 điểm giao)
- Không tạo endpoint mới, không thay đổi route

## Luồng xử lý

```
Driver gửi arrived_delivery
        │
        ▼
  ┌──────────────────────────────────┐
  │ arrived_delivery + chưa có       │
  │ delivery_point                   │
  └──────────────────────────────────┘
        │
        ├── Có new_delivery_location_id? ──Có──►
        │                                         │
        │  ┌─────────────────────────────────────┐ │
        │  │ Tạo OrderDeliveryPoint              │ │
        │  │ Gán delivery_point_id vào payload   │ │
        │  │ Update checkpoint với điểm vừa tạo  │ │
        │  └─────────────────────────────────────┘ │
        │                                         ▼
        │                                   match() bình thường
        │                               (handleArrivedDelivery)
        │
        └── Không ──► 422 "Đơn hàng chưa có điểm đến.
                        Vui lòng chọn điểm giao hàng."
        │
        ▼
  handleArrivedDelivery() → updateDeliveryPoint()
  (chuyển Pending → Arrived, ghi arrived_at)
        │
        ▼
  Driver gửi completed → handleCompleted()
  (chuyển Arrived → Delivered, ghi delivered_at)
```

## Thay đổi code

### 1. `CheckpointRequest.php`

Thêm rule:

```php
'new_delivery_location_id' => 'nullable|exists:locations,id',
```

### 2. `TripCheckpointController.php`

Trong method `checkpoint()`, sau khi tạo `TripCheckpoint` record (dòng 48-59) và trước `match()` (dòng 81-88), thêm:

```php
// Tự tạo delivery point nếu arrived_delivery + đơn chưa có điểm đến
if ($checkpoint->checkpoint_type === CheckpointType::ArrivedDelivery
    && empty($payload['delivery_point_id'])
    && ! empty($payload['new_delivery_location_id'])) {

    $deliveryPoint = $order->deliveryPoints()->create([
        'location_id' => $payload['new_delivery_location_id'],
        'sequence' => 1,
        'address' => Location::find($payload['new_delivery_location_id'])?->address,
        'status' => OrderDeliveryPointStatus::Pending,
    ]);

    $checkpoint->update(['delivery_point_id' => $deliveryPoint->id]);
    $payload['delivery_point_id'] = $deliveryPoint->id;
}
```

Cần thêm import: `use App\Models\Location;`

### 3. API Response

Không thay đổi format response. App có thể biết delivery_point vừa tạo qua field `delivery_point_id` trong checkpoint response (nếu muốn).

## Ghi chú

- Không ảnh hưởng đến luồng hiện tại (khi đã có delivery point, `new_delivery_location_id` bị bỏ qua)
- Validation: nếu `arrived_delivery` + chưa có delivery point + không có `new_delivery_location_id` → API trả về 422 "Đơn hàng chưa có điểm đến. Vui lòng chọn điểm giao hàng." để buộc tài xế chọn.
- Phần check km_reading trong `after()` của `CheckpointRequest` giữ nguyên
