# Multi-Vehicle Shift Tracking

**Ngày:** 2026-06-04

## Vấn đề

`DriverShift` hiện chỉ có một `vehicle_id` duy nhất — một ca gắn với một xe. Km được ghi nhận từ chính xe đó (`start_km`, `end_km`). Điều này sai khi tài xế lái nhiều xe trong cùng một ca (mỗi xe là một đơn hàng khác nhau):

1. Tài xế bắt đầu ca trên xe A (đơn 1) → ghi `start_km = 10.000`
2. Làm xong đơn 1, nhận đơn 2 → chuyển sang xe B
3. Kết thúc ca trên xe B → ghi `end_km = 15.000` (từ xe B)
4. `total_km = 15.000 - 10.000 = 5.000` ❌ vô nghĩa — km của 2 xe khác nhau
5. Không thể biết mỗi xe chạy bao nhiêu km trong ca

Cơ chế swap hiện tại (`DriverSwapAction`, `ReassignDriverAction`) chỉ xử lý đổi tài xế, không đổi xe.

## Giải pháp

Thêm bảng `shift_vehicles` để theo dõi từng phân đoạn xe trong ca. Mỗi phân đoạn gắn với một đơn hàng cụ thể, ghi lại xe nào được dùng, cho đơn nào, thời gian, km/GPS tại điểm ranh giới.

### Bảng mới: `shift_vehicles`

```sql
CREATE TABLE shift_vehicles (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    shift_id      BIGINT UNSIGNED NOT NULL,
    vehicle_id    BIGINT UNSIGNED NOT NULL,
    order_id      BIGINT UNSIGNED NULL     COMMENT 'Đơn hàng tương ứng với phân đoạn xe này',
    start_time    DATETIME NOT NULL          COMMENT 'Thời điểm bắt đầu dùng xe này',
    end_time      DATETIME NULL              COMMENT 'Thời điểm chuyển xe/kết thúc',
    start_km      DECIMAL(10,1) NULL         COMMENT 'Km lúc nhận xe',
    end_km        DECIMAL(10,1) NULL         COMMENT 'Km lúc chuyển xe/kết thúc',
    start_gps_lat DECIMAL(10,7) NULL,
    start_gps_lng DECIMAL(10,7) NULL,
    end_gps_lat   DECIMAL(10,7) NULL,
    end_gps_lng   DECIMAL(10,7) NULL,
    created_at    TIMESTAMP NULL,
    updated_at    TIMESTAMP NULL,

    FOREIGN KEY (shift_id)   REFERENCES driver_shifts(id) ON DELETE CASCADE,
    FOREIGN KEY (vehicle_id) REFERENCES vehicles(id),
    FOREIGN KEY (order_id)   REFERENCES orders(id) ON DELETE SET NULL,

    INDEX (shift_id, vehicle_id),
    INDEX (order_id)
);
```

### Thay đổi trên DriverShift

- **Giữ** `vehicle_id`, `start_km`, `end_km` — đại diện cho xe đầu tiên của ca (tương thích ngược, tiện cho UI)
- **Thêm** `shiftVehicles()`: quan hệ `HasMany` tới `ShiftVehicle`
- **Thêm** `currentShiftVehicle()`: helper trả về segment đang active (`end_time IS NULL`)
- `total_km` → tính từ tổng tất cả segments (sửa trong `ShiftKmCalculatorService`)

### Model mới: `ShiftVehicle`

```php
class ShiftVehicle extends Model
{
    protected $fillable = [
        'shift_id', 'vehicle_id', 'order_id',
        'start_time', 'end_time',
        'start_km', 'end_km',
        'start_gps_lat', 'start_gps_lng',
        'end_gps_lat', 'end_gps_lng',
    ];

    public function shift(): BelongsTo;
    public function vehicle(): BelongsTo;
    public function order(): BelongsTo;
}
```

### Luồng xử lý

| Sự kiện | Hành động |
|---|---|
| Bắt đầu ca | Tạo `DriverShift` + tạo `ShiftVehicle` đầu tiên (vehicle_id, start_km, start_time, start_gps) |
| Nhận đơn hàng mới (xe khác) | Nếu đơn mới dùng xe khác với segment đang active → kết thúc segment cũ → tạo segment mới (vehicle_id mới, order_id = đơn mới, start_km = km hiện tại) |
| Kết thúc ca | Kết thúc segment cuối (end_time, end_km, end_gps) → Tính `total_km` từ tổng tất cả segments |

### API / Filament Action

**`POST /api/shifts/{shift}/switch-vehicle`**
- Input: `new_vehicle_id`, `order_id`, `handover_km`, `handover_gps_lat`, `handover_gps_lng`, `reason`, `note`
- Logic:
  1. Tìm `ShiftVehicle` đang active (`end_time IS NULL`)
  2. Kết thúc nó: gán `end_time`, `end_km`, `end_gps`
  3. Tạo `ShiftVehicle` mới: `vehicle_id` = new_vehicle_id, `order_id` = order_id, `start_km` = handover_km, `start_time` = now
  4. Cập nhật Vehicle cũ và mới (`current_driver_id`, `current_mileage`)

Ngoài ra: endpoint `start` tự động tạo segment đầu; endpoint `end` tự động kết thúc segment cuối.

### Tính toán Km

**Tổng km cả ca:**
```php
$totalKm = $shift->shiftVehicles->sum(
    fn ($sv) => ($sv->end_km ?? 0) - $sv->start_km
);
```

**Km theo từng xe trong ca:**
```php
$kmPerVehicle = $shift->shiftVehicles
    ->groupBy('vehicle_id')
    ->map(fn ($segments) => $segments->sum(
        fn ($sv) => ($sv->end_km ?? 0) - $sv->start_km
    ));
// Kết quả: [1 => 1500, 2 => 1700, 3 => 1800]
```

**Km theo từng đơn hàng:**
```php
$kmPerOrder = $shift->shiftVehicles
    ->filter(fn ($sv) => $sv->order_id)
    ->mapWithKeys(fn ($sv) => [
        $sv->order_id => ($sv->end_km ?? 0) - $sv->start_km
    ]);
```

### Các file cần sửa

| File | Thay đổi |
|---|---|
| `database/migrations/XXXX_XX_XX_create_shift_vehicles_table.php` | Migration mới |
| `app/Models/ShiftVehicle.php` | Model mới |
| `app/Models/DriverShift.php` | Thêm `shiftVehicles()`, `currentShiftVehicle()` |
| `app/Services/ShiftKmCalculatorService.php` | Tính `total_km` từ segments |
| `app/Http/Controllers/Api/DriverShiftController.php` | Tạo segment đầu khi start, kết thúc segment cuối khi end; thêm `switchVehicle` endpoint |
| `app/Http/Requests/SwitchVehicleRequest.php` | Form request mới |
| `app/Http/Resources/ShiftVehicleResource.php` | Resource mới (không bắt buộc) |
| `app/Filament/Resources/Orders/Actions/ReassignDriverAction.php` | Bỏ `where('vehicle_id', ...)` khi tìm shift active |
| `app/Filament/Resources/DriverShifts/Schemas/DriverShiftForm.php` | Thêm `shiftVehicles` repeatable section (read-only) |
| `app/Filament/Resources/DriverShifts/Schemas/DriverShiftInfolist.php` | Thêm shift vehicle segments |

### Ví dụ thực tế

Tài xế A bắt đầu ca lúc 07:00, lái 3 xe trong ca:

| shift_id | Xe | Đơn hàng | Bắt đầu | Kết thúc | start_km | end_km | Km |
|---|---|---|---|---|---|---|---|
| 1 | Xe A (id=1) | Đơn #101 | 07:00 | 10:00 | 10.000 | 11.500 | 1.500 |
| 1 | Xe B (id=2) | Đơn #102 | 10:00 | 14:00 | 11.500 | 13.200 | 1.700 |
| 1 | Xe C (id=3) | Đơn #103 | 14:00 | 17:00 | 13.200 | 15.000 | 1.800 |

**Kết quả:**
- Tổng km ca: 1.500 + 1.700 + 1.800 = **5.000 km** ✅
- Xe A: 1.500 km, Xe B: 1.700 km, Xe C: 1.800 km ✅
- Đơn #101: 1.500 km, Đơn #102: 1.700 km, Đơn #103: 1.800 km ✅

### Phạm vi

- ✅ **Trong phạm vi**: bảng shift_vehicles (có order_id), model, migration, luồng start/end/switch, tính km theo xe và theo đơn, sửa ReassignDriverAction
- ❌ **Ngoài phạm vi**: thiết kế lại UI swap flow, real-time vehicle tracking, migration dữ liệu lịch sử (các ca cũ giữ nguyên 1 segment)

## Self-Review

- **Chỗ trống**: Không
- **Nhất quán nội bộ**: shift_vehicles có order_id liên kết với đơn hàng; km tính từ segments cho cả ca, theo xe, và theo đơn đều nhất quán
- **Phạm vi**: Tập trung vào vấn đề vehicle-tracking; thay đổi swap logic là tối thiểu
- **Mơ hồ**: "total_km từ segments" — luôn tính khi save, không lưu từ một công thức đơn lẻ
