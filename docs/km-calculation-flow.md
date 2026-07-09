# Luồng tính toán km có hàng / không hàng

## 1. Tổng quan

Hệ thống tracking km ở 2 cấp độ:

| Cấp độ | Ý nghĩa | Dữ liệu đầu vào | Tính khi nào |
|--------|---------|-----------------|-------------|
| **Trip** (chuyến) | Km của 1 chuyến giao hàng cụ thể | `trip.start_km`, `trip.end_km`, checkpoints của trip | Gọi API `POST /trips/{trip}/complete` |
| **Shift** (ca làm) | Km của tài xế trong suốt ca, chia segment theo xe | Checkpoints có `shift_id`, cắt đoạn tại `end` | Kết thúc ca (sau khi có checkpoint `end`) |

---

## 2. Các cột trong DB

### DriverShift
```
start_km       → km đồng hồ xe lúc bắt đầu ca (từ vehicle.current_mileage)
end_km         → km đồng hồ xe lúc kết thúc ca (lấy từ checkpoint 'end' cuối cùng)
total_km       → tổng km của ca (tính từ checkpoints, segment theo vehicle_id)
total_km_loaded → km có hàng trong ca
total_km_empty  → km không hàng trong ca = total_km - total_km_loaded
```

### Trip
```
start_km       → km đồng hồ xe lúc bắt đầu chuyến (từ vehicle.current_mileage)
end_km         → km đồng hồ xe lúc hoàn thành chuyến (từ payload API complete)
total_km       → end_km - start_km
total_km_loaded → km có hàng của chuyến (tính từ checkpoints của trip)
total_km_empty  → total_km - total_km_loaded
```

### TripCheckpoint
```
trip_id        → chuyến nào (nullable — checkpoint 'end' có thể không gắn với trip)
shift_id       → ca nào
order_id       → đơn hàng nào
vehicle_id     → xe được sử dụng (bắt buộc với checkpoint type='end')
checkpoint_type → started | arrived_pickup | left_pickup | arrived_delivery | completed | driver_swap | end
km_reading     → số km đồng hồ tại thời điểm đó
```

---

## 3. Các loại checkpoint

| Checkpoint | Ý nghĩa | Cần km_reading? | Gắn với order? | Gắn với trip? |
|------------|---------|-----------------|----------------|---------------|
| `started` | Bắt đầu chuyến | Không bắt buộc | Không (trip-wide) | Có |
| `arrived_pickup` | Đến lấy hàng | **Bắt buộc** | Không (trip-wide) | Có |
| `left_pickup` | Rời lấy hàng | Không bắt buộc | Không (trip-wide) | Có |
| `arrived_delivery` | Đến giao hàng | Không bắt buộc | Có (order + delivery_point) | Có |
| `completed` | Hoàn thành giao | Có thể có | Có (order + delivery_point) | Có |
| `driver_swap` | Đảo lái giữa chừng | Có thể có | Không | Có |
| **`end`** | **Rời xe / nhập km kết thúc** | **Bắt buộc** | **Không** | **Nullable** |

---

## 4. Luồng chi tiết từng bước

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
```

### Bước 3: Đến điểm lấy hàng — ArrivedPickup Checkpoint
```
API: POST /api/driver/trips/{trip}/checkpoints  { km_reading: 10010 }
Handler: ArrivedPickupHandler

  trip.status = ArrivedPickup
  vehicle.current_mileage = km_reading
```

### Bước 4: Rời điểm lấy hàng — LeftPickup Checkpoint
```
Handler: LeftPickupHandler

  trip.status = Delivering
  orders chuyển từ Sent → InTransit
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

  delivery_point.status = Delivered
  Nếu tất cả delivery points của order đã delivered → order.status = Completed
  Nếu tất cả orders trong trip đã completed → KHÔNG tự động complete trip nữa
  vehicle.current_mileage = km_reading
```

**Quan trọng**: Trip KHÔNG còn tự động Completed khi order cuối hoàn thành. Phải gọi API complete thủ công.

### Bước 7: Hoàn thành chuyến — Manual Trip Complete (MỚI)
```
API: POST /api/driver/trips/{trip}/complete  { end_km: 10090, completed_at? }
Action: TripController::complete()

  Validate:
    - Tài xế phải là driver của chuyến
    - Trip chưa Completed hoặc DriverSwap
    - end_km >= 0

  trip.complete(endKm: 10090, completedAt: ...)
    → trip.status = Completed
    → trip.completed_at = now
    → trip.end_km = endKm
    → trip.total_km = endKm - startKm
    → Gọi TripKmCalculatorService::calculate(trip)
```

### Bước 8: TripKmCalculatorService
```
TripKmCalculatorService::calculate(trip, ?endKm)

  Lấy tất cả checkpoint của trip này (trip_id = ?)
    loại 'arrived_pickup' và 'completed'
    có order_id và km_reading
    sắp xếp theo km_reading tăng dần

  Duyệt qua từng checkpoint, tracking orders đang "có hàng":
    Giống thuật toán cũ (xem mục 7).

  Kết quả:
    trip.total_km_loaded = totalLoadedKm
    trip.total_km_empty = trip.total_km - totalLoadedKm
```

### Bước 9: Rời xe — End Vehicle Checkpoint (MỚI)
```
API: POST /api/driver/shifts/{shift}/end-vehicle  { km_reading }
Handler: EndHandler::handle(shift, vehicle, kmReading)

  Validate:
    - km_reading >= vehicle.current_mileage (chặn nhập lùi km)

  Nếu CÓ trip đang active trên xe này trong shift:
    → Tính km cho trip: TripKmCalculatorService(trip, endKm: kmReading)
    → trip.end_km = kmReading
    → trip.status = DriverSwap
    → trip.shift_id giữ nguyên (sẽ được clean up trong end shift)
    → orders InTransit/Sent → DriverSwap

  Nếu KHÔNG có trip active:
    → Chỉ tạo checkpoint 'end' (trip_id = null)

  Tạo TripCheckpoint:
    checkpoint_type = 'end'
    trip_id = activeTripId (hoặc null)
    shift_id = shift.id
    vehicle_id = vehicle.id   ← BẮT BUỘC
    km_reading = kmReading

  vehicle.current_mileage = kmReading   ← QUAN TRỌNG: đóng Bug 1
```

### Bước 10: Kết thúc ca — End Shift (đã sửa)
```
API: POST /api/driver/shifts/end
UI:   Filament EndShiftAction

  GATE (mới):
    Tìm checkpoint 'end' gần nhất của shift này (theo id giảm dần).
    Nếu KHÔNG có → reject 422: "Cần nhập km kết thúc trước khi kết thúc ca."

  Nếu có:
    shift.end_km = endCheckpoint.km_reading   ← LẤY TỪ CHECKPOINT, không từ payload
    shift.end_time = now

    Xử lý trip đang active chưa hoàn thành:
      → TripKmCalculatorService(trip, endKm: shift.end_km)
      → trip.status = DriverSwap, trip.shift_id = null
      → Tạo driver_swap checkpoint

    Gọi ShiftKmCalculatorService::calculate(shift)   ← Xem bước 11

    Cleanup: null shift_id cho các trip DriverSwap còn sót
    (được EndHandler tạo ra — trip đã swap nhưng shift_id chưa null)

    Cập nhật vehicle mileage từ end checkpoint
```

### Bước 10b: Chuyển xe — Switch Vehicle (đã sửa)
```
API: POST /api/driver/shifts/switch-vehicle  { new_vehicle_id, handover_km }

  GATE: Phải có checkpoint 'end' cho xe hiện tại trước khi chuyển
  Nếu KHÔNG có → reject 422

  Nếu có:
    Cập nhật xe mới: current_mileage = handover_km
```

### Bước 11: ShiftKmCalculatorService (đã sửa — segment theo vehicle)
```
ShiftKmCalculatorService::calculate(shift)

  1. Fallback start_km:
     Nếu shift.start_km = 0 (bug known — startShift không set đúng):
       - Nếu trip đầu tiên bắt đầu SAU shift.start_time
         (trip được tạo trên ca này) → dùng trip.start_km
       - Nếu trip được chuyển từ ca khác (started_at < shift.start_time)
         → dùng km_reading của checkpoint đầu tiên (chính xác hơn)
       - Fallback cuối: checkpoint km_reading đầu tiên

  2. Xây dựng segments:
     Tìm tất cả checkpoint 'end' của shift, sắp xếp theo occurred_at.

     KHÔNG có 'end':
       → 1 segment duy nhất = toàn bộ shift
       → events = tất cả arrived_pickup/completed của shift

     CÓ 'end':
       → Mỗi 'end' đóng 1 segment
       → Segment 1: từ shift.start_km đến end_1.km_reading
       → Segment 2: từ end_1.km_reading đến end_2.km_reading
       → ...
       → Segment cuối (nếu có events): từ end_cuối.km_reading đến shift.end_km
       → Events trong mỗi segment = các arrived_pickup/completed nằm trong khoảng km đó

  3. Với mỗi segment, chạy calculateSegment():
     → Logic loaded/empty giống hệt TripKmCalculatorService
     → Nhưng scope = 1 segment (1 xe, 1 khoảng km liên tục)

  4. Tổng kết:
     shift.total_km       = sum(segment.total_km)
     shift.total_km_loaded = sum(segment.loaded_km)
     shift.total_km_empty  = total_km - total_km_loaded
```

**Điểm khác biệt chính với code cũ:**
- Cũ: sort **tất cả** checkpoint theo km_reading → sai khi đổi xe (2 đồng hồ khác nhau)
- Mới: cắt đoạn tại mỗi checkpoint `end` → mỗi segment thuộc 1 xe, km liên tục → tính đúng

---

## 5. Xử lý Driver Swap

```
Driver Shift A (Driver A) — xe km ~10000
  ├── Trip 1: start_km=10000, arrived_pickup=10010
  └── Driver A hết ca:
        1. Gọi end-vehicle (km=10060)
           → EndHandler phát hiện trip đang active
           → TripKmCalculatorService(trip, endKm=10060)
           → trip.status = DriverSwap, trip.shift_id vẫn = shiftA
           → Tạo checkpoint 'end' (km=10060, vehicle_id = xe A)
        2. Gọi end shift
           → Gate: có checkpoint 'end' ✓
           → shiftA.end_km = 10060
           → ShiftKmCalculatorService: segment từ 10000→10060
           → Cleanup: trip.shift_id = null

Driver Shift B (Driver B) — cùng xe, km tiếp tục từ 10060
  ├── Trip 1 tiếp tục (shift_id = shiftB):
  │     - arrived_delivery, completed
  │     - Gọi complete trip (end_km=10090)
  │       → TripKmCalculatorService tính loaded/empty toàn bộ trip
  └── Kết thúc ca:
        1. Gọi end-vehicle (km=10090)
        2. Gọi end shift
           → shiftB.end_km = 10090
           → ShiftKmCalculatorService: segment từ 10060→10090

→ Trip.total_km = 10090 - 10000 = 90 (full chuyến, cả 2 tài xế)
→ ShiftA.total_km = 10060 - 10000 = 60 (phần Driver A)
→ ShiftB.total_km = 10090 - 10060 = 30 (phần Driver B)
```

---

## 6. Xử lý đổi xe giữa ca (đã sửa)

```
Shift bắt đầu với xe A (km ~20000)
  ├── Trip 1 (xe A):
  │     arrived_pickup=20010, completed=20040
  │     → Gọi complete trip (end_km=20040)
  │
  ├── Gọi end-vehicle (km=20070) — rời xe A
  │     → Tạo checkpoint 'end' (km=20070, vehicle_id=xeA)
  │
  ├── Gọi switch-vehicle (new_vehicle_id=xeB, handover_km=50000)
  │     → Gate: có 'end' ✓
  │     → Xe B current_mileage = 50000
  │
  ├── Trip 2 (xe B):
  │     arrived_pickup=50010, completed=50080
  │     → Gọi complete trip (end_km=50080)
  │
  └── Gọi end-vehicle (km=50100) — rời xe B
        → Tạo checkpoint 'end' (km=50100, vehicle_id=xeB)

  Kết thúc ca:
    shift.end_km = 50100 (từ checkpoint 'end' cuối cùng)

    ShiftKmCalculatorService:
      End checkpoints: [20070, 50100]

      Segment 1 (xe A): 20000 → 20070
        events: [arrived_pickup(20010), completed(20040)]
        → seg_km=70, loaded=30, empty=40

      Segment 2 (xe B): 20070 → 50100
        events: [arrived_pickup(50010), completed(50080)]
        → seg_km=30030, loaded=70, empty=29960
        (khoảng trống 20070→50010 = empty km giữa 2 xe)

    shift.total_km = 70 + 30030 = 30100
    shift.total_km_loaded = 30 + 70 = 100
    shift.total_km_empty = 30100 - 100 = 30000
```

**So với code cũ:**
- Cũ: sort tất cả checkpoint [20010, 20040, 50010, 50080] → loaded = 30+70 = 100
  nhưng empty = (50080-20000) - 100 = 29980 → **sai hoàn toàn** vì trộn 2 đồng hồ
- Mới: tính riêng từng segment → loaded đúng, empty phản ánh đúng khoảng trống giữa 2 xe

---

## 7. Thuật toán loaded/empty (dùng chung cho Trip và Shift segment)

```
Input:  startKm, endKm, events[] (arrived_pickup/completed, sorted by km_reading)

preloadedIds = orders có completed nhưng KHÔNG có arrived_pickup trong events
               (hàng đã có sẵn trên xe từ trước — preloaded)

activeOrderIds = preloadedIds
totalLoadedKm = 0
prevKm = startKm

for each event in events:
    eventKm = max(event.km_reading, prevKm)

    if activeOrderIds not empty AND eventKm > prevKm:
        totalLoadedKm += eventKm - prevKm     // đoạn này có hàng

    if event is arrived_pickup:
        activeOrderIds.push(event.order_id)   // thêm order đang chở
    else (completed):
        activeOrderIds.remove(event.order_id) // bỏ order đã giao xong

    prevKm = eventKm

// Đoạn cuối (từ checkpoint cuối đến endKm)
if activeOrderIds not empty AND endKm > prevKm:
    totalLoadedKm += endKm - prevKm

totalKm = max(0, endKm - startKm)
emptyKm = totalKm - totalLoadedKm
```

---

## 8. Công thức tóm tắt

```
Trip.total_km       = trip.end_km - trip.start_km
Trip.total_km_loaded = tổng các đoạn km có ít nhất 1 order trên xe
Trip.total_km_empty  = trip.total_km - trip.total_km_loaded

Shift.total_km       = sum(segment.total_km) — mỗi segment = 1 xe, 1 khoảng km liên tục
Shift.total_km_loaded = sum(segment.loaded_km)
Shift.total_km_empty  = shift.total_km - shift.total_km_loaded
```

---

## 9. Ví dụ cụ thể

### 1 trip, 1 order, không swap
```
vehicle.current_mileage = 10000

Started          → trip.start_km = 10000
ArrivedPickup    → checkpoint.km_reading = 10010
LeftPickup       → (không nhập km)
ArrivedDelivery  → checkpoint.km_reading = 10080
Completed        → checkpoint.km_reading = 10090

POST /trips/{trip}/complete  { end_km: 10090 }
  → total_km = 10090 - 10000 = 90
  → TripKmCalculatorService:
      arrived_pickup(10010): active = [order], prevKm = 10010
      completed(10090):      loaded += 10090-10010 = 80
      → total_loaded = 80, total_empty = 90-80 = 10
```

### 1 trip, có km lang thang sau khi hoàn thành đơn (Bug 1 fix)
```
Trip:
  ArrivedPickup    → km = 10010
  Completed        → km = 10050     (order done, trip CHƯA complete)
  POST /trips/{trip}/complete  { end_km: 10050 }
    → total_km = 50, loaded = 40, empty = 10

Tài xế chạy thêm 30km không hàng, rồi rời xe:
  POST /shifts/{shift}/end-vehicle  { km_reading: 10080 }
    → Tạo checkpoint 'end' (km=10080)
    → vehicle.current_mileage = 10080   ← ĐÓNG BUG 1

  POST /shifts/end
    → shift.end_km = 10080 (từ checkpoint 'end')
    → ShiftKmCalculatorService:
        segment: 10000 → 10080
        events: [arrived_pickup(10010), completed(10050)]
        → loaded = 40, empty = 10080-10000 - 40 = 40
    → 30km lang thang nằm trong empty ✓
```

### 2 trips, 1 shift, cùng xe
```
Shift start_km = 20000

Trip 1:
  Started          → start_km = 20000
  ArrivedPickup    → km = 20010
  Completed        → km = 20040
  Complete trip    → end_km = 20040, total = 40, loaded = 30, empty = 10

Trip 2:
  Started          → start_km = 20040
  ArrivedPickup    → km = 20060
  Completed        → km = 20100
  Complete trip    → end_km = 20100, total = 60, loaded = 40, empty = 20

End vehicle → km = 20100
End shift → end_km = 20100

Shift segment (không có 'end' giữa chừng → 1 segment):
  start=20000, end=20100
  events: [arrived(20010), completed(20040), arrived(20060), completed(20100)]
  → loaded = 30+40 = 70, empty = 100-70 = 30
```

### Ca tiếp theo nhận đúng start_km (Bug 1 — phần 2)
```
Ca 1:
  End vehicle → km = 10080
  End shift   → vehicle.current_mileage = 10080

Ca 2 (tài xế khác, cùng xe):
  POST /shifts/start
    → shift.start_km = vehicle.current_mileage = 10080 ✓
    → Không bị lệch km (không nhận nhầm km lang thang của ca trước)
```

---

## 10. Files liên quan

| File | Vai trò |
|------|---------|
| `app/Models/Trip.php` | Model Trip, method `complete()` |
| `app/Models/DriverShift.php` | Model DriverShift |
| `app/Models/TripCheckpoint.php` | Model Checkpoint (có thêm `vehicle_id`) |
| `app/Enums/CheckpointType.php` | Enum các loại checkpoint (có thêm `End`) |
| `app/Services/TripKmCalculatorService.php` | Tính loaded/empty cho 1 trip |
| `app/Services/ShiftKmCalculatorService.php` | Tính loaded/empty cho shift (segment-based) |
| `app/Services/Trip/Handlers/StartedHandler.php` | Set start_km = vehicle mileage |
| `app/Services/Trip/Handlers/CompletedHandler.php` | Complete orders + delivery points (KHÔNG auto-complete trip) |
| `app/Services/Trip/Handlers/EndHandler.php` | **MỚI** — Xử lý checkpoint 'end' khi rời xe |
| `app/Http/Controllers/Api/DriverShiftController.php` | Start/end shift, end vehicle, switch vehicle |
| `app/Http/Controllers/Api/TripController.php` | **MỚI** — `complete()` endpoint cho trip |
| `app/Filament/Resources/DriverShifts/Actions/EndShiftAction.php` | End shift qua Filament |
| `routes/api.php` | Routes: `/shifts/{shift}/end-vehicle`, `/trips/{trip}/complete` |
| `database/migrations/*_add_end_checkpoint_type_*.php` | Migration: thêm `end` enum, nullable trip_id, vehicle_id |

---

## 11. API endpoints

| Method | Endpoint | Mô tả |
|--------|----------|-------|
| `POST` | `/api/driver/shifts/start` | Bắt đầu ca |
| `POST` | `/api/driver/shifts/end` | Kết thúc ca (cần checkpoint `end` trước) |
| `POST` | `/api/driver/shifts/{shift}/end-vehicle` | **MỚI** — Nhập km khi rời xe (tạo checkpoint `end`) |
| `POST` | `/api/driver/shifts/switch-vehicle` | Chuyển xe giữa ca (cần checkpoint `end` trước) |
| `POST` | `/api/driver/trips/{trip}/checkpoints` | Tạo checkpoint cho chuyến |
| `POST` | `/api/driver/trips/{trip}/complete` | **MỚI** — Kết thúc chuyến (manual) |
