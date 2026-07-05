# Spec: Cancel Order — Trip Handling

## Objective

When an order belonging to a trip is cancelled, the system must:
1. Remove the `trip_id` reference from the cancelled order
2. If the cancelled order is the **only** order on that trip, also cancel the trip itself

This prevents orphaned trips and ensures the trip lifecycle is consistent with its orders.

## Tech Stack

- **Laravel 13** with PHP 8.4
- **Filament v5** (admin panel action)
- **SQLite** (dev/test), **Eloquent ORM**
- **Pest v4** (testing)

## Current State

- `CancelOrderAction` at `app/Filament/Resources/Orders/Actions/CancelOrderAction.php` sets order status to `Cancelled` but **does not touch `trip_id` or the trip**.
- `TripStatus` enum has no `Cancelled` case.
- Trip model uses `TripStatus` as string-backed enum.

## Changes Required

### 1. `TripStatus` enum: Add `Cancelled` case

New case with label "Đã huỷ" and danger color. Must be excluded from `activeStatuses()` (cancelled is not active). Add a new `isCancelled()` helper on `Trip` model.

### 2. `CancelOrderAction`: Trip cleanup logic

In the action handler, **after** cancelling the order:
- Set `trip_id = null` on the cancelled order
- Count remaining orders on that trip (excluding the cancelled one)
- If count === 0, set trip status to `Cancelled`

### 3. `ListTrips` page: Add cancelled filter

Add `cancelled` entry to `tripStatusFilters` array and `applyStatusFilterByKey()`.

### 4. `TripStatsOverviewWidget`: Optionally count cancelled

Minor — add a cancelled stat or let it fall under "Tổng chuyến".

### 5. Tests

New test covering:
- Cancel order with trip → trip_id becomes null
- Cancel last order on trip → trip status becomes Cancelled
- Cancel order when trip has other orders → trip stays as-is

## Files Touched

- `app/Enums/TripStatus.php` — add `Cancelled` case
- `app/Filament/Resources/Orders/Actions/CancelOrderAction.php` — add trip cleanup
- `app/Filament/Resources/Trips/Pages/ListTrips.php` — add cancelled filter
- `app/Models/Trip.php` — add `isCancelled()`, cast `cancelled_at`
- `database/migrations/xxxx_xx_xx_xxxxxx_add_cancelled_at_to_trips_table.php` — new migration
- `tests/Feature/OrderCancelTest.php` — new test file

## Boundaries

- **Always:** Run tests before finalizing; format with Pint
- **Ask first:** Adding DB migration (not needed — status is enum, trip_id already exists)
- **Never:** Delete orders or trips; modify production data directly

## Success Criteria

- [ ] `TripStatus::Cancelled` exists with label "Đã huỷ" and danger color
- [ ] Cancelling an order with `trip_id` set removes `trip_id` to null
- [ ] Cancelling the last order on a trip sets trip status to `Cancelled`
- [ ] Cancelling an order on a multi-order trip leaves trip unchanged
- [ ] All existing tests still pass
- [ ] Code formatted with Pint

## Open Questions

(Resolved: add `cancelled_at` nullable timestamp column to trips table)
