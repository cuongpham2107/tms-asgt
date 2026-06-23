# Thay đổi API — Ghi chú cho Frontend

## 1. Trip History API (MỚI)

**Endpoint:** `GET /api/driver/trips/history`

**Auth:** `auth:sanctum` + role `vehicle`

**Mô tả:** Lấy danh sách chuyến đi đã hoàn thành hoặc đảo lái của tài xế (phân trang).

### Query Parameters

| Param | Type | Required | Description |
|---|---|---|---|
| `status` | string | No | Lọc theo trạng thái: `completed` hoặc `driver_swap` |
| `from_date` | string (ISO date) | No | Lọc từ ngày (started_at >=) |
| `to_date` | string (ISO date) | No | Lọc đến ngày (started_at <=) |
| `vehicle_id` | int | No | Lọc theo phương tiện |
| `per_page` | int | No | Số bản ghi/trang (mặc định 15, tối đa 100) |

### Response

```json
{
  "data": [ TripResource, ... ],
  "meta": {
    "current_page": 1,
    "last_page": 5,
    "per_page": 15,
    "total": 65
  }
}
```

### TripResource

```json
{
  "id": 1,
  "trip_code": "T-ABC-123",
  "vehicle_id": 1,
  "status": "completed",
  "started_at": "2026-06-23T01:00:00+07:00",
  "completed_at": "2026-06-23T05:00:00+07:00",
  "start_km": 20000.0,
  "end_km": 20070.0,
  "vehicle": {
    "id": 1,
    "plate_number": "51F-12345"
  },
  "shift": { ... },
  "orders": [ OrderResource, ... ],
  "checkpoints": [ TripCheckpointResource, ... ],
  "driver_swaps": [ DriverSwapResource, ... ],
  "created_at": "2026-06-23T00:00:00+07:00",
  "updated_at": "2026-06-23T05:00:00+07:00"
}
```

- `shift`: null nếu trip bị clear shift_id (driver swap)
- `driver_swaps`: mảng rỗng nếu không có đảo lái

---

## 2. Trip Checkpoint API (THAY ĐỔI)

**Endpoint:** `POST /api/driver/trips/{trip}/checkpoints`

**Auth:** `auth:sanctum` + role `vehicle`

**Mô tả:** Ghi nhận chốt chặng trong chuyến đi. Trip-centric: tài xế thao tác trên trip, hệ thống tự động tạo per-order checkpoints cho các mốc trip-level.

### Request Body

| Field | Type | Valid For | Required | Description |
|---|---|---|---|---|
| `checkpoint_type` | string | all | Yes | Một trong: `started`, `arrived_pickup`, `left_pickup`, `arrived_delivery`, `completed`, `driver_swap` |
| `order_id` | int | `arrived_delivery`, `completed` | Yes (cho 2 loại đó) | ID của đơn hàng |
| `delivery_point_id` | int | `arrived_delivery`, `completed` | Conditional | Xem ghi chú bên dưới |
| `new_delivery_location_id` | int | `arrived_delivery`, `completed` | Conditional | ID location — dùng khi đơn chưa có điểm đến |
| `km_reading` | number | `arrived_pickup`, `completed` | Yes (cho 2 loại đó) | Số km đồng hồ. **Không** được gửi cho `started` |
| `occurred_at` | string (ISO 8601) | all | No | Mặc định là thời điểm request |
| `gps_lat` | number | all | No | Vĩ độ |
| `gps_lng` | number | all | No | Kinh độ |
| `voice_note` | string | all | No | Ghi chú thoại |
| `photos[]` | file (image, max 10MB) | all | No | Upload ảnh |

### Logic `delivery_point_id` vs `new_delivery_location_id`

| Trường hợp | Bắt buộc gửi | Hành vi |
|---|---|---|
| Order đã có điểm đến (`OrderDeliveryPoint`) | `delivery_point_id` | Chọn 1 trong các điểm đến hiện có |
| Order CHƯA có điểm đến | `new_delivery_location_id` | Hệ thống tự tạo `OrderDeliveryPoint` mới từ `Location` này |

Nếu vi phạm → 422 với message tương ứng.

### Km validation rules

- `started`: km_reading bị cấm (tự động lấy từ đồng hồ xe)
- `arrived_pickup`, `completed`: km_reading bắt buộc
- Mọi km_reading: phải >= km gần nhất của chính order đó (trong ca)
- `completed`: km_reading phải > km lúc rời điểm nhận

### Checkpoint types & behavior

| `checkpoint_type` | Scope | Auto-created | Status |
|---|---|---|---|
| `started` | Trip-level | Tạo per-order cho mỗi order trong trip | Trip → Started |
| `arrived_pickup` | Trip-level | Tạo per-order cho mỗi order | Trip → ArrivedPickup |
| `left_pickup` | Trip-level | Tạo per-order cho mỗi order | Trip → Delivering |
| `arrived_delivery` | Order-level | 1 checkpoint cho 1 order | Trip → ArrivedDelivery |
| `completed` | Order-level | 1 checkpoint + cập nhật order status | Trip → Completed (nếu hết order) |
| `driver_swap` | Trip-level | (nội bộ từ admin) | — |

### Response

```json
{
  "checkpoint": TripCheckpointResource
}
```

### TripCheckpointResource

```json
{
  "id": 1,
  "trip_id": 1,
  "order_id": 1,
  "checkpoint_type": "arrived_pickup",
  "occurred_at": "2026-06-23T01:00:00+07:00",
  "km_reading": 20010.0,
  "gps_lat": 10.8,
  "gps_lng": 106.6,
  "photos": [ ... ]
}
```

---

## 3. Trip Active API (GIỮ NGUYÊN)

**Endpoint:** `GET /api/driver/trips/active`

Không thay đổi. Trả về trip đang active của tài xế.

---

## 4. Trip Show API (GIỮ NGUYÊN)

**Endpoint:** `GET /api/driver/trips/{trip}`

Không thay đổi. Trả về chi tiết 1 trip.

---

## 5. Driver Swap Endpoint (MỚI)

**Endpoint:** `POST /api/driver/driver-swap`

Đã thêm route mới (xem routes). Dùng để ghi nhận đảo lái qua API.

---

## Tổng kết thay đổi

| Endpoint | Method | Trạng thái |
|---|---|---|
| `/api/driver/trips/history` | GET | **MỚI** |
| `/api/driver/trips/{trip}/checkpoints` | POST | **Sửa validation** (thêm `new_delivery_location_id`, mềm hoá `delivery_point_id`) |
| `/api/driver/trips/active` | GET | Giữ nguyên |
| `/api/driver/trips/{trip}` | GET | Giữ nguyên response (thêm field `driver_swaps`, `trip_code`, `shift`) |
| `/api/driver/orders/history` | GET | Giữ nguyên |
| `/api/driver/orders/stats` | GET | Giữ nguyên |
| `/api/driver/orders` | GET | Giữ nguyên |
| `/api/driver/shifts/start` | POST | Giữ nguyên |
| `/api/driver/shifts/end` | POST | Giữ nguyên |
| `/api/driver/shifts/current` | GET | Giữ nguyên |

### TripResource hiện tại trả về thêm:

- `driver_swaps` — mảng các driver swap (nếu có)
- `shift` — object shift (nếu trip còn shift_id)
- `trip_code` — mã chuyến (string)

### Checkpoint type enum (6 loại)

```
started         → "Bắt đầu chuyến"
arrived_pickup  → "Đến lấy hàng"
left_pickup     → "Rời lấy hàng"
arrived_delivery → "Đến giao hàng"
completed       → "Hoàn thành"
driver_swap     → "Đảo lái"
```
