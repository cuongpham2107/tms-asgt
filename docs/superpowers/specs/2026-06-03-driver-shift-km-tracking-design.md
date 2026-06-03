# Driver Shift & KM Tracking — Design Spec

## Mục tiêu

Đảm bảo mỗi km lái xe chạy được ghi nhận chính xác, phục vụ tính lương sau này:

- **Km có hàng** = km chạy từ lúc rời điểm nhận đến lúc giao xong
- **Km không hàng** = tổng km ca − km có hàng
- Gán xe/lái phải kiểm tra ca đang hoạt động (có override cho điều hành)
- Đảo lái hoàn chỉnh: kết thúc ca cho lái cũ, giao ca, điều hành gán lái mới

---

## 1. DB Changes (3 migrations)

### 1.1 Thêm `shift_id` vào `orders`

```php
Schema::table('orders', function (Blueprint $table) {
    $table->foreignId('shift_id')
        ->nullable()
        ->constrained('driver_shifts')
        ->nullOnDelete()
        ->after('driver_id');
    $table->index('shift_id');
});
```

**Mục đích:** Biết ngay đơn thuộc ca nào mà không cần join checkpoint.

### 1.2 Thêm `to_shift_id` vào `driver_swaps`

```php
Schema::table('driver_swaps', function (Blueprint $table) {
    $table->foreignId('to_shift_id')
        ->nullable()
        ->constrained('driver_shifts')
        ->nullOnDelete()
        ->after('from_shift_id');
});
```

**Mục đích:** Khi đảo lái, link được cả shift cũ (from) và shift mới (to).

### 1.3 Update `Order` model

- Thêm `shift_id` vào `$fillable`
- Thêm relationship `shift(): BelongsTo`

### 1.4 Update `DriverSwap` model

- Thêm `to_shift_id` vào `$fillable`
- Thêm relationship `toShift(): BelongsTo`

---

## 2. ShiftKmCalculatorService

`app/Services/ShiftKmCalculatorService.php`

### Logic

```php
class ShiftKmCalculatorService
{
    public function calculate(DriverShift $shift): void
    {
        // Lấy tất cả checkpoint completed và left_pickup trong shift, group theo order
        $orders = TripCheckpoint::where('shift_id', $shift->id)
            ->whereIn('checkpoint_type', ['arrived_pickup', 'left_pickup', 'completed'])
            ->orderBy('occurred_at')
            ->get()
            ->groupBy('order_id');

        $totalLoadedKm = 0;

        foreach ($orders as $points) {
            $completed = $points->firstWhere('checkpoint_type', 'completed');
            $leftPickup = $points->firstWhere('checkpoint_type', 'left_pickup');

            if ($completed?->km_reading && $leftPickup?->km_reading) {
                $totalLoadedKm += $completed->km_reading - $leftPickup->km_reading;
            }
        }

        $shift->total_km = $shift->end_km - $shift->start_km;
        $shift->total_km_loaded = $totalLoadedKm;
        $shift->total_km_empty = $shift->total_km - $totalLoadedKm;
        $shift->save();
    }
}
```

### Khi nào gọi

- `DriverShiftController@end()`: sau khi set `end_km`
- `DriverShiftResource@toArray`: trả về 3 cột km (đã có)

---

## 3. API Changes

### 3.1 `StartShiftRequest` — validation `start_km`

Sửa `after()`: so `start_km` với `end_km` của shift gần nhất của **cùng xe đó** (không chỉ `vehicle.current_mileage`):

```php
$lastKm = DriverShift::where('vehicle_id', $this->input('vehicle_id'))
    ->whereNotNull('end_km')
    ->orderByDesc('end_time')
    ->value('end_km');

if ($lastKm !== null && (float) $this->input('start_km') < (float) $lastKm) {
    // báo lỗi
}
```

### 3.2 `DriverShiftController@end()` — auto-calc

```php
$shift->end_km = $payload['end_km'];

// Auto-calc km
app(ShiftKmCalculatorService::class)->calculate($shift);
```

Không cho nhập tay `total_km`, `total_km_loaded`, `total_km_empty` từ form nữa.

### 3.3 `CheckpointRequest` — `left_pickup` bắt buộc km

```php
'km_reading' => 'required_if:checkpoint_type,left_pickup|numeric',
'km_reading' => 'nullable|numeric',  // các loại khác vẫn nullable
```

Validation `completed.km_reading ≥ left_pickup.km_reading` (cùng order):

```php
if ($payload['checkpoint_type'] === 'completed' && $payload['km_reading'] !== null) {
    $order = Order::find($payload['order_id']);
    $leftPickupKm = TripCheckpoint::where('order_id', $order->id)
        ->where('checkpoint_type', 'left_pickup')
        ->value('km_reading');

    if ($leftPickupKm !== null && (float) $payload['km_reading'] <= (float) $leftPickupKm) {
        // báo lỗi
    }
}
```

### 3.4 Auto-set `order.shift_id` khi tạo checkpoint

`TripCheckpointController@checkpoint`: sau khi tạo checkpoint, nếu `order.shift_id === null` và checkpoint có `shift_id`:

```php
if ($order->shift_id === null && $payload['shift_id'] ?? null) {
    $order->shift_id = $payload['shift_id'];
    $order->save();
}
```

### 3.5 `TripCheckpointController@handleLeftPickup` — cập nhật km

Không cần thay đổi logic (chỉ chuyển status `Delivering`), km được ghi ở checkpoint create.

### 3.6 `OrderResource` — thêm `shift_id`

```php
'shift_id' => $this->shift_id,
```

---

## 4. Web Changes (Filament)

### 4.1 `AssignTransportAction` — kiểm tra ca + override

- VehiclePicker: filter xe đang có ca mở (`end_time IS NULL`)
- Thêm checkbox/switch: "Cho phép gán xe không có ca" (mặc định `false`) — cho điều hành override
- Nếu không override mà xe không có ca mở → báo lỗi, chặn gán

Sau khi gán thành công:
- Set `order.shift_id` = shift_id của ca đang mở cho xe đó

### 4.2 `OrderForm` — tương tự

Tab "Phân xe":
- VehiclePicker filter + override
- Sau khi lưu: set `order.shift_id`

### 4.3 `DriverSwapAction` (Filament) — fix bug

Sửa `$record->driverShifts()->first()?->id` → query đúng:

```php
$shift = TripCheckpoint::where('order_id', $record->id)
    ->whereNotNull('shift_id')
    ->orderByDesc('occurred_at')
    ->value('shift_id');
```

### 4.4 `DriverSwapAction` — thêm gán shift cho lái mới

Sau khi swap, điều hành có thể chọn ca của lái mới (nếu có) để set `to_shift_id`.

---

## 5. Đảo lái hoàn chỉnh

### 5.1 Mobile: lái cũ swap (`POST /driver/driver-swap`)

- End shift hiện tại của lái cũ (set `end_time`, `end_km`, `end_gps`)
- Gọi `ShiftKmCalculatorService::calculate()` cho shift cũ
- Tạo `DriverSwap` record
- Tạo checkpoint `driver_swap` (đã có)
- Order `status` → `driver_swap` (đã có)
- **Không** tự động out app — dựa vào `driver_id` đã đổi nên app cũ không thấy

### 5.2 Web: điều hành gán lái mới

Sau khi lái cũ swap, order ở status `driver_swap`. Điều hành dùng **một action riêng** (không phải `AssignTransportAction`) để gán lái mới:

- Tên action: `ReassignDriverAction`
- Chỉ hiển thị khi `status === driver_swap`
- Chọn lái mới + chọn shift (nếu lái mới có ca đang mở)
- Set `order.driver_id = lái mới, to_shift_id = shift của lái mới`
- Set `order.status = sent` (gửi lệnh cho lái mới)

---

## 6. Kiểm soát "1 đơn 1 lần"

API không chặn, chỉ cảnh báo. Thêm vào `GET /driver/orders`:

```php
'has_active_order' => Order::where('driver_id', $user->id)
    ->whereIn('status', ['started', 'arrived_pickup', 'delivering', 'arrived_delivery'])
    ->exists(),
```

Mobile app tự quyết định có cảnh báo hay không dựa vào flag này.

---

## 7. Non-goals (làm sau)

- Ghi âm → tự chuyển text
- Tính lương theo km
- Dashboard tổng km cho điều hành
