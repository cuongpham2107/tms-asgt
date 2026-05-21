# TMS Phase 1: Hoàn thiện Điều hành

## Mục tiêu
Fix các Order actions theo đúng điều kiện trong spec, thêm OrderEditLog để tracking lịch sử chỉnh sửa.

## 1. OrderEditLog

### Database
```php
// Table: order_edit_logs
- id (bigInteger, primary)
- order_id (foreignId)
- user_id (foreignId)
- field (string) - tên field thay đổi
- old_value (text)
- new_value (text)
- created_at, updated_at
```

### Model
```php
class OrderEditLog extends Model
{
    protected $fillable = ['order_id', 'user_id', 'field', 'old_value', 'new_value'];
    
    public function order(): BelongsTo
    public function user(): BelongsTo
}
```

---

## 2. RecallOrderAction (Thu hồi)

### Điều kiện đúng
Chỉ thu hồi được khi status ∈ [Sent, Started, ArrivedPickup]
- Có thể thu hồi: gửi lệnh, bắt đầu, đến lấy hàng
- Không thể thu hồi: đang giao, đến giao, đã giao, hoàn thành, hủy

### Logic
```php
public function canRecall(): bool
{
    return in_array($this, [self::Sent, self::Started, self::ArrivedPickup]);
}
```

### Action behavior
- Đổi status về Assigned
- Xóa sent_at
- Gửi notification thành công

---

## 3. CancelOrderAction (Hủy đơn)

### Điều kiện đúng
Chỉ hủy được khi status ∉ [ArrivedDelivery, Delivered, Completed, Cancelled, Trashed]

Theo spec: "trước khi at_delivery" - tức là ArrivedDelivery trở đi thì KHÔNG được hủy.

### Logic
```php
public function canCancel(): bool
{
    return ! in_array($this, [
        self::ArrivedDelivery, 
        self::Delivered, 
        self::Completed, 
        self::Cancelled, 
        self::Trashed
    ]);
}
```

### Action behavior
- Đổi status thành Cancelled
- Set cancelled_at = now()
- Yêu cầu nhập lý do hủy (cancel_reason)

---

## 4. DeleteOrderAction (Xóa đơn)

### Điều kiện đúng
Chỉ xóa (soft delete) được khi status ∈ [Draft, Cancelled]

### Logic
```php
public function canDelete(): bool
{
    return in_array($this, [self::Draft, self::Cancelled]);
}
```

### Implementation
- Sử dụng Filament DeleteAction có hidden condition
- Hoặc tạo custom action với modal xác nhận

---

## 5. DriverSwapAction (Đảo lái)

### 4 nguyên tắc (spec §6.4)

| Tình huống | Xử lý |
|-----------|-------|
| Bàn giao ca (về nhà) | Nhập Km → Kết thúc ca → Điều hành gán lái mới |
| Hàng chưa hạ được | Nhập Km xe hiện tại → Đảo lái → Lái mới nhận từ Km đó |
| Đơn sau đảo lái | Không hiển thị trong app lái cũ. Điều hành gán trên web → app lái mới |
| Lái mới nhận đơn | Km bắt đầu = Km kết thúc của lái cũ |

### Điều kiện status
Chỉ đảo lái khi status ∈ [Started, ArrivedPickup, Delivering, ArrivedDelivery]

### Logic
```php
public function canSwapDriver(): bool
{
    return in_array($this, [
        self::Started, 
        self::ArrivedPickup, 
        self::Delivering, 
        self::ArrivedDelivery
    ]);
}
```

### Action behavior
- Tạo DriverSwap record (from_driver, to_driver, km_handover, reason)
- Đổi order.driver_id = to_driver_id
- Set order.status = DriverSwap (nếu cần tracking)
- Ghi log OrderEditLog

---

## 6. Order Edit Logs Integration

Khi điều hành sửa đơn (EditAction), tự động ghi log:

- Tạo Observer cho Order model
- Trong `updated()` method, so sánh các field thay đổi
- Tạo OrderEditLog cho mỗi field thay đổi
- Lưu user_id của người đang login

---

## Files cần tạo/sửa

| File | Action |
|------|--------|
| `app/Models/OrderEditLog.php` | Tạo mới |
| `database/migrations/2026_05_17_000000_create_order_edit_logs_table.php` | Tạo mới |
| `app/Observers/OrderObserver.php` | Tạo mới (cho edit logs) |
| `app/Filament/Resources/Orders/Actions/RecallOrderAction.php` | Sửa - thêm validation |
| `app/Filament/Resources/Orders/Actions/CancelOrderAction.php` | Sửa - thêm validation |
| `app/Filament/Resources/Orders/Actions/DriverSwapAction.php` | Kiểm tra và fix nếu cần |
| `app/Filament/Resources/Orders/Tables/OrdersTable.php` | Thêm DeleteAction |

---

## Thứ tự implementation

1. Migration + Model OrderEditLog
2. OrderObserver cho edit logs
3. Fix RecallOrderAction (test: Sent → Assigned)
4. Fix CancelOrderAction (test: Assigned → Cancelled)
5. Fix/kiểm tra DriverSwapAction
6. Thêm DeleteAction vào OrdersTable
7. Chạy Pint format + Test