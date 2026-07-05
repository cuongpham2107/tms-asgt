# Plan: Cancel Order — Trip Handling

## Dependencies

```
TripStatus enum ─→ migration ─→ Trip model ─→ ListTrips filter
                                        ↓
                               CancelOrderAction
                                        ↓
                                    Tests
```

- **TripStatus** has no deps (standalone enum change)
- **Migration** depends on TripStatus (adds `cancelled` to enum + `cancelled_at`)
- **Trip model** depends on TripStatus + migration (add cast + helper)
- **ListTrips** depends on TripStatus (add filter key)
- **CancelOrderAction** depends on Trip model (check order count, cancel trip)
- **Tests** depend on everything above

## Implementation Order

1. **TripStatus enum** — add `Cancelled` case with label + color
2. **Migration** — add `cancelled` to ENUM, add `cancelled_at` column
3. **Trip model** — add `cancelled_at` to casts, add `isCancelled()` helper
4. **CancelOrderAction** — add trip cleanup logic (detach trip_id, cancel trip if last order)
5. **ListTrips** — add `cancelled` filter entry
6. **Tests** — full test coverage
7. **Pint** — format all changed files

## Risks

- **SQLite ENUM change**: Must use separate MigrationBuilder or raw SQL to modify CHECK constraint. Test thoroughly.
- **Existing data**: Trips cancelled before this change won't have `cancelled_at` set — acceptable.
- **Race condition**: Cancelling two orders on same trip simultaneously — extremely unlikely in Filament admin context (user-driven, one at a time). No locking needed.

## Verification Checkpoints

- After step 1: `TripStatus::Cancelled` exists, `->value` returns `'cancelled'`
- After step 2: New migration runs cleanly, rollback works
- After step 3: `$trip->isCancelled()` returns correct boolean
- After step 4: Manual test via Filament UI (or test)
- After step 6: `php artisan test --compact --filter=OrderCancelTest`
- After step 7: `vendor/bin/pint --format agent` passes
