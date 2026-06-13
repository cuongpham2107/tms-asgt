# Driver Chọn Điểm Đến Khi Đơn Chưa Có Delivery Point — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Cho phép tài xế chọn điểm đến từ bảng locations khi gửi checkpoint `arrived_delivery` cho đơn chưa có delivery point.

**Architecture:** Mở rộng checkpoint API duy nhất — thêm field `new_delivery_location_id` vào request. Khi `arrived_delivery` + chưa có delivery point + có location, tự tạo `OrderDeliveryPoint` rồi xử lý tiếp. Nếu không có location, trả về 422.

**Tech Stack:** Laravel 13, PHP 8.4, SQLite

---

### Task 1: Thêm validation rule cho new_delivery_location_id

**Files:**
- Modify: `app/Http/Requests/CheckpointRequest.php:23-37`

- [ ] **Step 1: Thêm rule**

Vào mảng `rules()` trong `CheckpointRequest.php`, thêm:

```php
'new_delivery_location_id' => 'nullable|exists:locations,id',
```

Đặt sau dòng `'delivery_point_id' => 'nullable|exists:order_delivery_points,id',`.

- [ ] **Step 2: Chạy test để verify**

Run: `php artisan test --compact --filter="Order"`
Expected: 4 tests passed, 97 assertions

- [ ] **Step 3: Commit**

```bash
git add app/Http/Requests/CheckpointRequest.php
git commit -m "feat: add new_delivery_location_id validation to checkpoint request"
```

---

### Task 2: Thêm logic auto-create delivery point trong TripCheckpointController

**Files:**
- Modify: `app/Http/Controllers/Api/TripCheckpointController.php:36-100`

- [ ] **Step 1: Thêm import Location model**

Đầu file, thêm:

```php
use App\Models\Location;
```

Đặt sau dòng `use App\Models\DriverShift;`.

- [ ] **Step 2: Thêm early-return 422 nếu arrived_delivery + chưa có điểm đến + không có location**

Trong method `checkpoint()`, sau khi `$order = Order::findOrFail($payload['order_id'])` (sau dòng 42) và trước driver check (dòng 44), thêm:

```php
if ($payload['checkpoint_type'] === CheckpointType::ArrivedDelivery->value
    && $order->deliveryPoints()->count() === 0
    && empty($payload['delivery_point_id'])
    && empty($payload['new_delivery_location_id'])) {
    return response()->json([
        'message' => 'Đơn hàng chưa có điểm đến. Vui lòng chọn điểm giao hàng.',
    ], 422);
}
```

- [ ] **Step 3: Thêm logic tạo delivery point khi arrived_delivery**

Trong method `checkpoint()`, sau khi tạo `TripCheckpoint` record (sau dòng 59 `]);` — kết thúc create) và trước `updateVehicleFromCheckpoint()`:

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

- [ ] **Step 4: Chạy test để verify**

Run: `php artisan test --compact --filter="Order"`
Expected: 4 tests passed, 97 assertions

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/TripCheckpointController.php
git commit -m "feat: auto-create delivery point on arrived_delivery when order has none"
```
