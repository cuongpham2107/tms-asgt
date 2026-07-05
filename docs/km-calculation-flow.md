# Luồng tính toán km có hàng / không hàng

## 1. Tổng quan

Hệ thống tracking km ở 2 cấp độ:

| Cấp độ | Ý nghĩa | Dữ liệu đầu vào | Tính khi nào |
|--------|---------|-----------------|-------------|
| **Trip** (chuyến) | Km của 1 chuyến giao hàng cụ thể | `trip.start_km`, `trip.end_km`, checkpoints của trip | Trip complete hoặc driver swap |
| **Shift** (ca làm) | Km của tài xế trong suốt ca | Tất cả checkpoints có `shift_id` = shift đó | Kết thúc ca |

---

## 2. Các cột trong DB

### DriverShift
```
start_km       → km đồng hồ xe lúc bắt đầu ca (từ vehicle.current_mileage)
end_km         → km đồng hồ xe lúc kết thúc ca (tài xế nhập)
total_km       → tổng km của ca (tính từ checkpoints)
total_km_loaded → km có hàng trong ca (tính từ checkpoints)
total_km_empty  → km không hàng trong ca = total_km - total_km_loaded
```

### Trip
```
start_km       → km đồng hồ xe lúc bắt đầu chuyến (từ vehicle.current_mileage)
end_km         → km đồng hồ xe lúc hoàn thành chuyến (từ completed checkpoint)
total_km       → end_km - start_km
total_km_loaded → km có hàng của chuyến (tính từ checkpoints của trip)
total_km_empty  → total_km - total_km_loaded
```

### TripCheckpoint
```
trip_id        → chuyến nào
shift_id       → ca nào (để shift có thể query)
order_id       → đơn hàng nào
checkpoint_type → started | arrived_pickup | left_pickup | arrived_delivery | completed | driver_swap
km_reading     → số km đồng hồ tại thời điểm đó
```

---

## 3. Luồng chi tiết từng bước

### Bước 1: Bắt đầu ca — Start Shift
```
API: POST /api/driver/shifts/start
Action: DriverShiftController::start()

  shift.start_km = vehicle.current_mileage  (lấy từ xe đang lái)
  shift.start_time = now
```

### Bước 2: Tạo chuyến + Bắt đầu chuyến — Started Checkpoint
```
API: POST /api/driver/trips/{trip}/checkpoints
Handler: StartedHandler::startTrip()

  trip.shift_id = activeShift.id                     (gán ca cho chuyến)
  trip.status = Started
  trip.started_at = now
  trip.start_km = vehicle.current_mileage            (lấy từ xe)

  → Đây là km bắt đầu của chuyến. Nếu đổi xe giữa ca,
    xe mới có current_mileage khác → trip.start_km sẽ khác.
```

### Bước 3: Đến điểm lấy hàng — ArrivedPickup Checkpoint
```
API: POST /api/driver/trips/{trip}/checkpoints  { km_reading: 10010 }
Handler: ArrivedPickupHandler

  Tài xế nhập km đồng hồ khi đến kho lấy hàng
  km_reading được lưu vào TripCheckpoint

  vehicle.current_mileage = km_reading              (cập nhật km xe)
```

### Bước 4: Rời điểm lấy hàng — LeftPickup Checkpoint
```
Handler: LeftPickupHandler

  trip.status = Delivering
  orders chuyển từ Sent → InTransit
  (km_reading không bắt buộc)
```

### Bước 5: Đến điểm giao — ArrivedDelivery Checkpoint
```
API: POST /api/driver/trips/{trip}/checkpoints  { order_id, delivery_point_id, km_reading }
Handler: ArrivedDeliveryHandler

  trip.status = ArrivedDelivery
  delivery_point.status = Arrived
  vehicle.current_mileage = km_reading
```

### Bước 6: Hoàn thành giao — Completed Checkpoint
```
API: POST /api/driver/trips/{trip}/checkpoints  { order_id, delivery_point_id, km_reading }
Handler: CompletedHandler

  6a. delivery_point.status = Delivered
  6b. Nếu tất cả delivery points của order đã delivered → order.status = Completed
  6c. Nếu tất cả orders trong trip đã completed → gọi Trip::complete()
  6d. vehicle.current_mileage = km_reading
```

### Bước 7: Trip Complete
```
Trip::complete(endKm: km_reading)

  trip.status = Completed
  trip.completed_at = now
  trip.end_km = km_reading
  trip.total_km = trip.end_km - trip.start_km

  → Gọi TripKmCalculatorService::calculate(trip)
```

### Bước 8: TripKmCalculatorService
```
TripKmCalculatorService::calculate(trip)

  Lấy tất cả checkpoint của trip này (trip_id = ?)
    loại 'arrived_pickup' và 'completed'
    có km_reading
    sắp xếp theo km_reading tăng dần

  Duyệt qua từng checkpoint, tracking orders đang "có hàng":

    Khởi tạo:
      - prevKm = trip.start_km
      - activeOrders = orders đã arrived_pickup nhưng chưa completed
      - totalLoadedKm = 0

    Với mỗi checkpoint:
      - eventKm = max(km_reading, prevKm)
      - Nếu đang có hàng (activeOrders không rỗng) và eventKm > prevKm:
          totalLoadedKm += eventKm - prevKm    (đoạn km này có hàng)
      - Nếu là arrived_pickup → thêm order vào activeOrders
      - Nếu là completed → xoá order khỏi activeOrders
      - prevKm = eventKm

    Sau vòng lặp:
      - Nếu còn activeOrders và trip.end_km > prevKm:
          totalLoadedKm += trip.end_km - prevKm  (đoạn cuối có hàng)

    Kết quả:
      - trip.total_km_loaded = totalLoadedKm
      - trip.total_km_empty = trip.total_km - totalLoadedKm

  Lưu trip (total_km_loaded, total_km_empty)
```

### Bước 9: Kết thúc ca — End Shift
```
Có 2 cách:
  A. API:  POST /api/driver/shifts/end  { end_km }
  B. UI:   Filament EndShiftAction

  Logic (API):
    1. shift.end_km = payload.end_km
    2. shift.end_time = now
    3. Kiểm tra trip nào còn đang active (chưa hoàn thành)
       → Nếu có: gọi TripKmCalculatorService(trip, endKm: shift.end_km)
                   → tính total_loaded/empty cho phần km đã chạy
                   → trip.status = DriverSwap, trip.shift_id = null
                   → tạo driver_swap checkpoint với shift_id = shift hiện tại
    4. Gọi ShiftKmCalculatorService::calculate(shift)

```

### Bước 10: ShiftKmCalculatorService
```
ShiftKmCalculatorService::calculate(shift)

  Lấy tất cả checkpoint thuộc ca này (shift_id = ?)
    loại 'arrived_pickup' và 'completed'
    có order_id và km_reading
    sắp xếp theo km_reading

  → Logic tương tự TripKmCalculatorService nhưng scope = shift
  → shift.total_km_loaded / shift.total_km_empty được tính từ checkpoints

  * LƯU Ý: Khi đổi xe giữa ca, km_reading của 2 xe khác nhau
    (vd: xe A: 20000-20050, xe B: 50000-50030).
    Checkpoints từ 2 xe vẫn được sort theo km_reading và xử lý bình thường.
```

---

## 4. Xử lý Driver Swap

```
Driver Shift A (Driver A)
  ├── Trip 1: start_km=20000, arrived_pickup=20010, (đang delivering)
  └── Driver A hết ca → End Shift A

  → Phát hiện Trip 1 chưa hoàn thành:
      1. TripKmCalculatorService(trip, endKm: 20060)
         → trip.total_km = 20060-20000 = 60
         → trip.total_km_loaded = (20010 là arrived_pickup... compute)
         → trip.total_km_empty = ...
      2. trip.status = DriverSwap, trip.shift_id = null
      3. Tạo driver_swap checkpoint (shift_id = shift A)
      4. ShiftKmCalculatorService(shift A) → tổng kết

Driver Shift B (Driver B)
  ├── Trip 1 tiếp tục (shift_id = shift B):
  │     - Driver B post started → trip.shift_id = shift B
  │     - arrived_delivery, completed
  │     - Trip::complete(endKm: 10090)
  │     - TripKmCalculatorService tính lại loaded/empty
  │       (từ TẤT CẢ checkpoints của trip, cả A + B)
  └── End Shift B → ShiftKmCalculatorService(shift B)

→ Trip.total_km = 10090 - 20000 = 90 (full chuyến)
→ Shift A.total_km = phần km Driver A lái
→ Shift B.total_km = phần km Driver B lái
```

---

## 5. Công thức tóm tắt

```
Trip.total_km       = trip.end_km - trip.start_km
Trip.total_km_loaded = tổng các đoạn km mà có ít nhất 1 order đang trên xe
                        (giữa arrived_pickup và completed)
Trip.total_km_empty   = trip.total_km - trip.total_km_loaded

Shift.total_km       = tổng km từ checkpoints của shift
Shift.total_km_loaded = tổng các đoạn km có hàng (từ checkpoints của shift)
Shift.total_km_empty   = shift.total_km - shift.total_km_loaded
```

---

## 6. Ví dụ cụ thể

### 1 trip, 1 order, không swap
```
vehicle.current_mileage = 10000

Started          → trip.start_km = 10000
ArrivedPickup    → checkpoint.km_reading = 10010
LeftPickup       → (không nhập km)
ArrivedDelivery  → checkpoint.km_reading = 10080
Completed        → checkpoint.km_reading = 10090

Trip::complete(endKm: 10090)
  → total_km = 10090 - 10000 = 90
  → TripKmCalculatorService:
      arrived_pickup(10010): active = [order], prevKm = 10010
      completed(10090):      loaded += 10090-10010 = 80, prevKm = 10090
      → total_loaded = 80, total_empty = 90-80 = 10
```

### 2 trips, 1 shift, cùng xe
```
Shift start_km = 20000

Trip 1:
  Started          → start_km = 20000
  ArrivedPickup OA → km = 20010
  Completed OA     → km = 20040
  → total_km = 40, loaded = 30, empty = 10

Trip 2:
  Started          → start_km = 20040 (vehicle đã update lên 20040)
  ArrivedPickup OB → km = 20060
  Completed OB     → km = 20100
  → total_km = 60, loaded = 40, empty = 20

Shift end_km = 20100
Checkpoints shift: arrived(20010) → completed(20040) → arrived(20060) → completed(20100)
  → total_km = 100, loaded = 70, empty = 30
```

### 2 trips, 1 shift, đổi xe
```
Shift bắt đầu với xe A (start_km = 20000)

Trip 1 (xe A):
  Started          → start_km = 20000
  ArrivedPickup OA → km = 20010
  Completed OA     → km = 20040
  → total_km = 40, loaded = 30, empty = 10

[Đổi xe: switch_vehicle, xe B đang ở km 50000]
Trip 2 (xe B):
  Started          → start_km = 50000  (từ xe B)
  ArrivedPickup OB → km = 50010
  Completed OB     → km = 50030
  → total_km = 30, loaded = 20, empty = 10

Shift end_km = 50030
Checkpoints shift: arrived(20010) → completed(20040) → arrived(50010) → completed(50030)
  → total_km = 50030 - 20000 = ... KHÔNG DÙNG công thức này vì khác xe
  → ShiftKmCalculatorService xử lý checkpoints:
      loaded = (20040-20010) + (50030-50010) = 30 + 20 = 50
      empty  = (20010-20000) + (50010-20040) = 10 + ... KHÔNG ĐÚNG

  **KẾT LUẬN**: Với case đổi xe, km_reading giữa 2 xe không liên tục
  → ShiftKmCalculatorService vẫn xử lý được vì nó dùng checkpoint sequence,
    nhưng khoảng trống 20040→50010 sẽ bị tính là empty km (rỗng).

  → Thực tế case này cần xem xét: shift có nên sum trip.total_km thay vì checkpoint?
  → Hiện tại giữ checkpoint-based vì xử lý đúng phần lớn case.
```

---

## 7. Files liên quan

| File | Vai trò |
|------|---------|
| `app/Models/Trip.php` | Model Trip, method `complete()` |
| `app/Models/DriverShift.php` | Model DriverShift |
| `app/Models/TripCheckpoint.php` | Model Checkpoint |
| `app/Services/TripKmCalculatorService.php` | Tính loaded/empty cho 1 trip |
| `app/Services/ShiftKmCalculatorService.php` | Tính loaded/empty cho shift (checkpoint-based) |
| `app/Services/Trip/Handlers/StartedHandler.php` | Set start_km = vehicle mileage |
| `app/Services/Trip/Handlers/CompletedHandler.php` | Gọi Trip::complete() |
| `app/Http/Controllers/Api/DriverShiftController.php` | End shift + driver swap |
| `app/Filament/Resources/DriverShifts/Actions/EndShiftAction.php` | End shift qua Filament |
