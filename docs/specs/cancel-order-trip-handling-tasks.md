# Tasks: Cancel Order — Trip Handling

- [ ] Task 1: Add `Cancelled` case to `TripStatus` enum
  - Acceptance: `TripStatus::Cancelled->value === 'cancelled'`, label "Đã huỷ", danger color
  - Verify: `php artisan tinker --execute 'dump(App\Enums\TripStatus::Cancelled->value);'`
  - Files: `app/Enums/TripStatus.php`

- [ ] Task 2: Create migration — add `cancelled` to status ENUM + `cancelled_at` column
  - Acceptance: `cancelled` is a valid status value; `cancelled_at` is nullable datetime
  - Verify: `php artisan migrate --force` succeeds
  - Files: `database/migrations/*_add_cancelled_to_trips_status.php`

- [ ] Task 3: Update Trip model — add `cancelled_at` cast + `isCancelled()` method
  - Acceptance: `$trip->isCancelled()` returns true when status is Cancelled; `cancelled_at` is Carbon
  - Verify: `php artisan tinker --execute '...'`
  - Files: `app/Models/Trip.php`

- [ ] Task 4: Update `CancelOrderAction` — detach trip_id, cancel trip if last order
  - Acceptance: Order trip_id set to null on cancel; trip cancelled if no orders left
  - Verify: `php artisan test --compact --filter=OrderCancelTest`
  - Files: `app/Filament/Resources/Orders/Actions/CancelOrderAction.php`

- [ ] Task 5: Add `cancelled` filter to `ListTrips` page
  - Acceptance: Filter pill shows "Đã huỷ" with count, filtering works
  - Verify: Visual check + `php artisan test --compact`
  - Files: `app/Filament/Resources/Trips/Pages/ListTrips.php`

- [ ] Task 6: Write tests
  - Acceptance: Tests cover: (a) cancel order detaches trip_id, (b) cancel last order cancels trip, (c) cancel with other orders leaves trip intact
  - Verify: `php artisan test --compact --filter=OrderCancelTest` passes
  - Files: `tests/Feature/OrderCancelTest.php`

- [ ] Task 7: Pint format
  - Acceptance: All PHP files properly formatted
  - Verify: `vendor/bin/pint --format agent`
  - Files: (all modified files)
