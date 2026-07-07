# Spec: Fix KM Calculation — Per-Order Tracking + Recalculation

## [S1] Problem

1. Trip KM cannot be recalculated after driver swap due to `total_km_loaded !== null` idempotency guard
2. No per-order `loaded_km` tracking — cannot query how many km an individual order traveled
3. Return trips have no KM calculation triggered

## [S2] Solution overview

1. Remove the idempotency guard so trip KM can be recalculated
2. Add `loaded_km` column to `orders` table, set when order completes
3. Return trips use existing `Trip::complete()` flow (loaded=0, empty=total)

## [S3] Migration

- Add `loaded_km DECIMAL(10,1) NULL` to `orders` table
- Add `loaded_km` to `Order::$fillable` and casts

## [S4] TripKmCalculatorService changes

- Remove `total_km_loaded !== null` guard (line ~14)
- After sweep-line calculation: for each unique `order_id` in events, compute and set `order.loaded_km = completed.km_reading - arrived_pickup.km_reading`

## [S5] CompletedHandler changes

- In `completeOrders()`: after an order transitions to `Completed`, call a method to record `order.loaded_km` from checkpoint data

## [S6] ShiftKmCalculatorService

- Already has no guard (correct)
- Add same per-order `loaded_km` recording logic

## [S7] ReturnTrip handling

- No changes needed: `Trip::complete()` computes `total_km = end_km - start_km`
- `TripKmCalculatorService` finds no orders → `loaded_km = 0`, `empty_km = total_km`

## [S8] Files to modify

| File | Change |
|------|--------|
| New migration | Add `loaded_km` to orders |
| `app/Models/Order.php` | Add `loaded_km` to fillable, casts |
| `app/Services/TripKmCalculatorService.php` | Remove guard, add order.loaded_km recording |
| `app/Services/Trip/Handlers/CompletedHandler.php` | Call order.loaded_km recording on order complete |
| `app/Services/ShiftKmCalculatorService.php` | Add order.loaded_km recording |

## [S9] Verification

1. Create trip with 2 orders → complete both → verify `order.loaded_km` is set for each
2. Driver swap mid-trip → verify trip KM can be recalculated after new driver completes
3. End shift → verify `shift.total_km_loaded` and `shift.total_km_empty` are correct
4. Return trip complete → verify `empty_km = total_km`, `loaded_km = 0`
