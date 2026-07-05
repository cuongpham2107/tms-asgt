# Spec: Tính toán km có hàng / không hàng cho Trip & Shift

## Objective

Một `DriverShift` có thể lái nhiều `Trip` (chuyến), mỗi Trip có thể dùng xe khác nhau (có đảo lái / đổi xe). Cần:

1. **Trip**: tự tính `total_km_loaded` / `total_km_empty` khi hoàn thành (hoặc khi bị driver swap)
2. **Shift**: `total_km` / `total_km_loaded` / `total_km_empty` = tổng của tất cả trips trong ca (thay vì query checkpoint trực tiếp)

**User:** Tài xế, điều hành.

**Success:** Trip hiển thị đúng km có hàng/không hàng. Shift tổng hợp đúng từ các trips — kể cả khi đổi xe giữa ca.

## Tech Stack

- Laravel 13 / PHP 8.4
- SQLite (dev/test)
- Pest 4 (testing)

## Commands

```bash
php artisan test --compact
php artisan test --compact --filter=TripKmTest
vendor/bin/pint --format agent
```

## Project Structure

```
app/Services/
  ShiftKmCalculatorService.php     ← SỬA: sum trips' km thay vì checkpoint trực tiếp
  TripKmCalculatorService.php      ← MỚI: tính km cho 1 trip

app/Models/Trip.php                ← SỬA: complete() gọi TripKmCalculatorService
app/Models/DriverShift.php         ← Không đổi

tests/Feature/
  TripKmTest.php                   ← MỚI
```

## Data Flow

### Trip start
```
Started checkpoint
  → StartedHandler: trip.start_km = vehicle.current_mileage
```

### Trip complete (normal)
```
Completed checkpoint
  → CompletedHandler: $trip->complete(endKm: km_reading)
  → Trip::complete():
      - status = Completed
      - end_km = km_reading
      - total_km = end_km - start_km
      - save()
      → TripKmCalculatorService::calculate($trip):
          - query trip's checkpoints (arrived_pickup, completed)
          - same algorithm as current ShiftKmCalculatorService
          - set total_km_loaded, total_km_empty
          - save()
```

### Trip incomplete (driver swap)
```
Shift ends
  → DriverShiftController::end():
      - set shift.end_km
      - find incomplete trip → set status = DriverSwap, shift_id = null
      → TripKmCalculatorService::calculate($trip, endKm: shift.end_km):
          - trip.end_km = shift.end_km
          - total_km = end_km - start_km
          - total_km_loaded = from checkpoints
          - total_km_empty = total_km - total_km_loaded
          - save()
      → ShiftKmCalculatorService::calculate($shift):
          - sum all completed + partial trips' km in shift
          - set shift.total_km, total_km_loaded, total_km_empty
```

### Shift end (normal)
```
Shift ends
  → driver shift controller OR filament action
  → ShiftKmCalculatorService::calculate($shift):
      - sum all trips' total_km, total_km_loaded, total_km_empty
      - save()
```

## Code Style

Follow existing conventions:
- Constructor property promotion, type hints everywhere
- No redundant comments
- `decimal:1` cast for km fields
- Curly braces for all control structures

## Testing Strategy

**Framework:** Pest 4 + `RefreshDatabase`

**Test file:** `tests/Feature/TripKmTest.php`

**Scenarios:**
1. Trip 1 order: arrived_pickup=10010, completed=10090 → loaded=80, total=100, empty=20
2. Trip 2 orders: same logic, scoped to trip's checkpoints
3. Trip with multi-delivery-point: partial completes
4. Shift with 2 completed trips: shift totals = sum of trips
5. Shift with driver swap: partial trip + complete trip = correct shift total

**Verify:** 
```php
expect((float) $trip->total_km_loaded)->toBe(80.0);
expect((float) $shift->total_km_loaded)->toBe(120.0);
```

## Boundaries

- Always: Run tests, format with pint, validate inputs
- Ask first: Schema changes, adding dependencies
- Never: Remove/modify existing tests without approval, break ShiftKmCalculatorService checkpoint fallback

## Success Criteria

1. `TripKmCalculatorService::calculate($trip)` sets `total_km_loaded`, `total_km_empty` correctly
2. `Trip::complete()` calls calculator
3. Driver swap calculates partial trip km before unlinking
4. `ShiftKmCalculatorService` sums trips' km correctly
5. All existing tests still pass
