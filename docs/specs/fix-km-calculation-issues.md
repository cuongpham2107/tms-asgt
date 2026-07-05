# Spec: Fix các lỗi km calculation

## Objective

Fix các lỗi được phát hiện trong code review của hệ thống tính toán km có hàng/không hàng.

## Danh sách lỗi

| # | Severity | Lỗi | File |
|---|----------|-----|------|
| 1 | Critical | EndShiftAction (Filament) không xử lý driver swap → mất km | `EndShiftAction.php` |
| 2 | Important | ShiftKmCalculatorService không fallback start_km = 0 → total_km sai | `ShiftKmCalculatorService.php` |
| 3 | Important | TripKmCalculatorService skip khi start_km = 0 → silent failure | `TripKmCalculatorService.php` |
| 4 | Medium | TripKmCalculatorService không kiểm tra status → có thể gọi sai | `TripKmCalculatorService.php` |
| 5 | Medium | Trip::complete() gọi calculator ngoài transaction → inconsistent | `Trip.php` |
| 6 | Nit | Validation thiếu check km của arrived_delivery | `TripCheckpointRequest.php` |
| 7 | Nit | preloadedIds không có comment | Cả 2 calculator |

## Tech Stack

- Laravel 13 / PHP 8.4
- SQLite (dev/test)
- Pest 4 (testing)

## Commands

```bash
php artisan test --compact
php artisan test --compact --filter=TestName
vendor/bin/pint --format agent
```

## Project Structure

```
app/Filament/Resources/DriverShifts/Actions/
  EndShiftAction.php              ← SỬA: thêm driver swap handling

app/Services/
  ShiftKmCalculatorService.php    ← SỬA: fallback start_km = 0
  TripKmCalculatorService.php     ← SỬA: status guard, start_km = 0

app/Models/
  Trip.php                        ← SỬA: complete() trong transaction

app/Http/Requests/
  TripCheckpointRequest.php       ← SỬA: validation arrived_delivery km

tests/Feature/
  ShiftEndViaFilamentTest.php     ← MỚI
  TripKmTest.php                  ← SỬA: thêm test edge cases
```

## Code Style

Như convention hiện tại. Giữ nguyên pattern của 2 calculator, không extract shared logic trừ khi thực sự giảm được code.

## Testing Strategy

**Mới:**
- `ShiftEndViaFilamentTest`: test EndShiftAction với driver swap scenario

**Sửa:**
- `TripKmTest`: thêm test start_km = 0, test calculator skip khi đã có data

## Boundaries

- Always: Chạy full test suite, format pint
- Ask first: Thay đổi schema, thêm dependency
- Never: Xoá/ sửa existing test không liên quan

## Success Criteria

1. EndShiftAction (Filament) tạo driver_swap + partial km cho incomplete trip
2. ShiftKmCalculatorService fallback về trip.start_km khi shift.start_km = 0
3. TripKmCalculatorService không silent fail khi start_km = 0
4. TripKmCalculatorService skip nếu trip đã có loaded/empty (trừ swap)
5. Trip::complete() gọi calculator trong cùng transaction
6. All existing tests pass
