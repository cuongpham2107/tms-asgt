# Sửa công thức tính km có tải / không tải

**Ngày:** 2026-06-10

## Vấn đề

Công thức tính `total_km_loaded` hiện tại dùng `completed.km - started.km`, nhưng `started` checkpoint không phải lúc nào cũng có `km_reading` (đặc biệt khi đảo lái). Ngoài ra, tài xế phải nhập km ở quá nhiều checkpoint gây dư thừa và sai sót.

## Giải pháp

### Vòng tuần hoàn km

```
vehicle.current_mileage
    │
    ├──[started]──→ lấy từ xe, ghi vào shift_vehicles.start_km (ko nhập)
    │
    ├──[arrived_pickup]──→ *tài xế nhập km* (mốc bắt đầu có tải)
    │
    ├──[completed]──→ *tài xế nhập km* (mốc kết thúc có tải)
    │
    └──[Kết thúc ca]──→ lấy shift_vehicles.end_km → cập nhật vehicle
```

Tài xế chỉ nhập km tại **2 checkpoint**: `arrived_pickup` và `completed`.

### Công thức mới

```
total_km        = Σ segment(end_km - start_km)
total_km_loaded = Σ loaded của từng order

Với mỗi order:
  arrivedKm  = checkpoint('arrived_pickup').km_reading
  completeKm = checkpoint('completed').km_reading

  Nếu có arrivedKm + completeKm → completeKm - arrivedKm         (chuẩn)
  Nếu chỉ có arrivedKm (swap, chưa giao) → segment.end_km - arrivedKm
  Nếu chỉ có completeKm (swap, tiếp quản) → completeKm - segment.start_km
  Không có cả 2 → 0

total_km_empty  = total_km - total_km_loaded
```

### Driver swap

```
TÀI XẾ A:                           TÀI XẾ B (tiếp quản):
  started(lấy km xe=10000)            started(lấy km xe=10060)
  arrived_pickup(km=10010)            completed(km=10090)
  end_shift(km=10060)                 end_shift(km=10100)

  loaded_A = 10060-10010=50           loaded_B = 10090-10060=30
  empty_A  = 60-50=10                 empty_B  = 40-30=10
```

## Thay đổi

### Backend

| File | Thay đổi |
|------|----------|
| `app/Http/Controllers/Api/TripCheckpointController.php` | `handleStarted()`: ưu tiên lấy km từ `vehicle.current_mileage` thay vì từ request |
| `app/Services/ShiftKmCalculatorService.php` | Dùng `arrived_pickup.km` thay `started.km`; thêm fallback swap; filter null `start_km` khi tính `total_km` |

### API

Không thay đổi API contract — tất cả endpoint giữ nguyên. Chỉ thay đổi logic xử lý bên trong.

### Tests

| File | Thay đổi |
|------|----------|
| `tests/Feature/OrderFullFlowTest.php` | Cập nhật kỳ vọng: loaded=80, empty=20 |
| `tests/Feature/OrderFlowHHHKTest.php` | Cập nhật kỳ vọng: loaded=55, empty=45 |
| `tests/Feature/TripCheckpointControllerTest.php` | Bỏ `vehicle_id` khỏi shift start; bỏ assert vehicle trong response |
