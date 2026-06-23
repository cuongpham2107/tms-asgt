# Các trường hợp gửi Trip Checkpoint

## 1. started — Bắt đầu chuyến

```json
{
  "checkpoint_type": "started",
  "occurred_at": "2026-06-23T01:00:00+07:00"

  // KHÔNG gửi km_reading (tự lấy từ đồng hồ xe)
  // KHÔNG gửi order_id
  // KHÔNG gửi delivery_point_id
}
```

---

## 2. arrived_pickup — Đến lấy hàng

```json
{
  "checkpoint_type": "arrived_pickup",
  "km_reading": 20010,
  "occurred_at": "2026-06-23T01:10:00+07:00"

  // KHÔNG gửi order_id (trip-level — tự tạo per-order)
  // KHÔNG gửi delivery_point_id
}
```

---

## 3. left_pickup — Rời lấy hàng

```json
{
  "checkpoint_type": "left_pickup",
  "km_reading": 20015,
  "occurred_at": "2026-06-23T01:15:00+07:00"

  // KHÔNG gửi order_id (trip-level — tự tạo per-order)
  // KHÔNG gửi delivery_point_id
}
```

---

## 4. arrived_delivery — Đến giao hàng

```json
{
  "checkpoint_type": "arrived_delivery",
  "order_id": 1,
  "km_reading": 20080,
  "occurred_at": "2026-06-23T02:00:00+07:00"
}
```

Có 2 trường hợp con:

### 4a. Order đã có sẵn điểm đến

```json
{
  "checkpoint_type": "arrived_delivery",
  "order_id": 1,
  "delivery_point_id": 5,
  "km_reading": 20080,
  "occurred_at": "2026-06-23T02:00:00+07:00"
}
```

### 4b. Order chưa có điểm đến — tạo mới tại chỗ

```json
{
  "checkpoint_type": "arrived_delivery",
  "order_id": 1,
  "new_delivery_location_id": 10,
  "km_reading": 20080,
  "occurred_at": "2026-06-23T02:00:00+07:00"
}
```

---

## 5. completed — Hoàn thành

```json
{
  "checkpoint_type": "completed",
  "order_id": 1,
  "km_reading": 20090,
  "occurred_at": "2026-06-23T02:30:00+07:00"
}
```

Có 2 trường hợp con:

### 5a. Order đã có sẵn điểm đến

```json
{
  "checkpoint_type": "completed",
  "order_id": 1,
  "delivery_point_id": 5,
  "km_reading": 20090,
  "occurred_at": "2026-06-23T02:30:00+07:00"
}
```

### 5b. Order chưa có điểm đến — tạo mới tại chỗ

```json
{
  "checkpoint_type": "completed",
  "order_id": 1,
  "new_delivery_location_id": 10,
  "km_reading": 20090,
  "occurred_at": "2026-06-23T02:30:00+07:00"
}
```

---

## 6. driver_swap — Đảo lái (chỉ dùng nội bộ)

```json
{
  "checkpoint_type": "driver_swap",
  "occurred_at": "2026-06-23T03:00:00+07:00"

  // KHÔNG gửi km_reading
  // KHÔNG gửi order_id
}
```

---

## Bảng tóm tắt field theo từng loại

| checkpoint_type | order_id | delivery_point_id | new_delivery_location_id | km_reading | Ghi chú |
|---|---|---|---|---|---|
| `started` | ❌ | ❌ | ❌ | ❌ Cấm | km tự lấy từ xe |
| `arrived_pickup` | ❌ | ❌ | ❌ | ✅ Bắt buộc | |
| `left_pickup` | ❌ | ❌ | ❌ | ✅ Tuỳ chọn | |
| `arrived_delivery` | ✅ Bắt buộc | ⚠️ Xem dưới | ⚠️ Xem dưới | ✅ Tuỳ chọn | |
| `completed` | ✅ Bắt buộc | ⚠️ Xem dưới | ⚠️ Xem dưới | ✅ Bắt buộc | km phải > left_pickup.km |
| `driver_swap` | ❌ | ❌ | ❌ | ❌ Tuỳ chọn | Nội bộ |

**Ghi chú cho `delivery_point_id` / `new_delivery_location_id`:**
- Nếu order có `OrderDeliveryPoint` → bắt buộc gửi `delivery_point_id`
- Nếu order KHÔNG có `OrderDeliveryPoint` → bắt buộc gửi `new_delivery_location_id`
- Không được gửi cả 2 cùng lúc

## Field chung (áp dụng cho tất cả)

| Field | Type | Bắt buộc |
|---|---|---|
| `checkpoint_type` | string | ✅ |
| `occurred_at` | ISO 8601 string | ❌ (mặc định now) |
| `gps_lat` | number | ❌ |
| `gps_lng` | number | ❌ |
| `voice_note` | string | ❌ |
| `photos[]` | file (max 10MB) | ❌ |
