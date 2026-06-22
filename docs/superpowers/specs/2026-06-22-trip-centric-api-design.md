# Trip-Centric API for Mobile Drivers

**Date:** 2026-06-22
**Status:** Approved Design

## 1. Motivation

Current API forces mobile drivers to post checkpoints by `order_id`, even though most checkpoint events are trip-level (started, arrived_pickup, left_pickup). Each trip can carry multiple orders; posting per-order for trip-level events creates confusion and unnecessary API calls.

This spec redesigns the mobile API to be **trip-centric**: drivers interact with a single trip resource, and checkpoint events broadcast to all orders within the trip automatically.

## 2. New API Endpoint

### 2.1 Replace

```
DELETE   POST /api/driver/checkpoints
```

### 2.2 With

```
POST   /api/trips/{trip}/checkpoints
```

Still under `auth:sanctum` + `role:driver` middleware group.

### 2.3 Request Body

| Field | Type | Required | Applies To |
|---|---|---|---|
| `checkpoint_type` | string | ✓ | All |
| `order_id` | integer | conditional | `arrived_delivery`, `completed` |
| `delivery_point_id` | integer | conditional | `arrived_delivery`, `completed` |
| `km_reading` | numeric | conditional | `arrived_pickup` (required), `completed` (required) |
| `occurred_at` | ISO datetime | ✗ (default: now) | All |
| `gps_lat` | numeric | ✗ | All |
| `gps_lng` | numeric | ✗ | All |
| `voice_note` | string | ✗ | All |
| `photos` | array of images | ✗ | All |

### 2.4 Authorization

- Driver must be `$trip->driver_id === $user->id`
- Exception: if `$trip->status === driver_swap`, allow if admin reassigned (already set `$trip->driver_id`)

### 2.5 Response

```json
{
  "checkpoint": { ... TripCheckpointResource ... }
}
```

## 3. Checkpoint Type Handler Logic

### 3.1 `started`

1. Load all orders in trip with status IN (sent, assigned)
2. Create one `TripCheckpoint` per order (all share same `trip_id`, `driver_id`, `occurred_at`)
3. If trip is `pending`:
   - `trip.status = started`
   - `trip.start_km = vehicle.current_mileage`
   - `trip.started_at = occurred_at`
4. **Update `trip.shift_id`** = driver's current active shift (if null)
5. Each checkpoint: `shift_id = trip.shift_id`

### 3.2 `arrived_pickup`

1. Create single trip-level checkpoint (no `order_id`)
2. `trip.status = arrived_pickup`
3. KM reading required (validated at request level)

### 3.3 `left_pickup`

1. Create single trip-level checkpoint
2. `trip.status = delivering`

### 3.4 `arrived_delivery`

1. Requires `order_id` + `delivery_point_id`
2. Create checkpoint with `order_id`, `delivery_point_id`
3. `trip.status = arrived_delivery`
4. Update delivery point → `Arrived`

### 3.5 `completed`

1. Requires `order_id` + `delivery_point_id` + `km_reading`
2. Create checkpoint
3. Update delivery point → `Delivered`
4. `order.status = completed`
5. Check if **all orders in trip are completed**:
   - Yes: `trip.status = completed`, `trip.end_km`, `trip.completed_at`, `vehicle.status = On`, create trip-level `completed` checkpoint
   - No: keep trip as-is, set only that order

### 3.6 `driver_swap`

Created internally by shift-end logic and admin swap actions. Not exposed to mobile POST.

## 4. Driver Shift End + Swap

### 4.1 `POST /api/driver/shifts/end` (modified)

When driver ends shift, for each incomplete trip (has orders without completed status):

1. `trip.status = driver_swap`
2. `trip.shift_id = null`
3. Create `TripCheckpoint` with `checkpoint_type = driver_swap`
4. End the shift (`shift.end_time = now`)

### 4.2 Admin Swap (Filament)

Operator uses `AssignTransportAction` (new `TripResource`) or `DriverSwapAction`:

1. Select trip, assign new driver
2. `trip.driver_id = new_driver_id`
3. `trip.status = pending`
4. Create `DriverSwap` record with `trip_id`

### 4.3 New Driver Flow (Mobile)

1. `GET /api/driver/trips/active` returns trip (admin set `driver_id`)
2. `POST /api/trips/{trip}/checkpoints` with `checkpoint_type: started` to resume

### 4.4 KM Tracking

- `trip_checkpoints.shift_id` differentiates Driver A (shift_A) from Driver B (shift_B) checkpoints
- `ShiftKmCalculatorService` already groups by `shift_id` — unchanged

## 5. Filament Actions — Move to Trip

### 5.1 `AssignTransportAction`

Currently works on Order, creates Trip implicitly. Move to `TripResource`:

- New `TripResource` with form: select vehicle, driver, shift
- Add orders to trip via relation manager or multi-select
- Trip created with `status = pending`

### 5.2 `DriverSwapAction`

Currently swaps driver on Order. Move to Trip:

- Select trip (filter by `status = driver_swap`)
- Select new driver
- Sets `trip.driver_id`, `trip.status = pending`, creates `DriverSwap`

### 5.3 `OrdersTable` edit action

Remove `vehicle_id` / `driver_id` fields (no longer on orders). Only `trip_id` assignment.

## 6. Bug Fixes (Same Scope)

| Bug | Location | Fix |
|---|---|---|
| DriverSwap.create uses `order_id` not `trip_id` | `DriverSwapController@store` | `'trip_id' => $order->trip_id` |
| DriverSwapController uses `$order->vehicle_id` | `DriverSwapController@store` (lines 62, 75) | `$order->trip?->vehicle_id` |
| DriverSwapController missing `trip_id` on checkpoint | `DriverSwapController@store` (line 93) | Add `'trip_id' => $order->trip_id` |
| DriverSwapController sets `$order->shift_id` (no column) | `DriverSwapController@store` (line 129) | Remove line — shift goes on trip |
| User::orders() references non-existent `driver_id` | `app/Models/User.php` | Replace with `whereHas('trip', fn => ...)` or mark deprecated |

## 7. Test Plan

### 7.1 TripCheckpoint Tests

- `POST /api/trips/{trip}/checkpoints` with `started` → creates checkpoints for all orders, updates trip status + shift_id
- `POST` with `arrived_pickup` → updates trip status
- `POST` with `arrived_delivery` + `order_id` + `delivery_point_id` → updates point + trip status
- `POST` with `completed` + `order_id` + `delivery_point_id` → completes order, auto-completes trip if last order
- `POST` with unauthorized driver → 403
- `POST` with missing required fields → 422

### 7.2 Shift End Tests

- End shift with incomplete trip → trip status = driver_swap
- End shift with all trips completed → no driver_swap

### 7.3 Admin Swap Tests

- Swap driver on trip → trip assigns to new driver
- New driver can `started` the trip

## 8. Migration Path / Order

1. Create `TripResource` in Filament (so actions can be moved)
2. Add route `POST /api/trips/{trip}/checkpoints` (+ new controller/move old)
3. Remove route `POST /api/driver/checkpoints`
4. Update `DriverShiftController@end` — ensure trip-level swap logic
5. Fix `DriverSwapController` bugs
6. Update Filament actions (AssignTransportAction, DriverSwapAction) to use Trip
7. Update `OrdersTable` edit action (remove vehicle/driver field)
8. Fix `User::orders()` relationship
9. Update `OrderFullFlowTest` and run full test suite
10. Run `vendor/bin/pint --format agent`, run `php artisan test --compact`
