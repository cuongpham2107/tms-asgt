# KM Calculation Fix — Per-Order Tracking + Recalculation

> **For agentic workers:** REQUIRED SUB-SKILL: Use compose:subagent (recommended) or compose:execute to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add per-order `loaded_km` tracking, remove recalculation guard so trip KM updates after driver swap, and ensure return trips compute empty KM correctly.

**Architecture:** Migrate new `loaded_km` column on orders. In `TripKmCalculatorService` and `CompletedHandler`, compute `loaded_km = completed.km_reading - arrived_pickup.km_reading` per order. Remove the `total_km_loaded !== null` guard to allow recalculation.

**Tech Stack:** Laravel 13, PHP 8.4, SQLite

## Global Constraints

- Use `php artisan make:migration` to create migration
- All KM values are `decimal(10,1)` to match existing `km_reading` type
- Keep existing sweep-line UNION algorithm for trip/shift loaded_km
- Do NOT change `Trip::complete()` logic
- Run `vendor/bin/pint --format agent` after code changes

---

### Task 1: Migration + Order model

**Files:**
- Create: new migration
- Modify: `app/Models/Order.php:19-45, 47-59`

**Interfaces:**
- Produces: `Order::$fillable` includes `'loaded_km'`, casts includes `'loaded_km' => 'decimal:1'`

- [ ] **Step 1: Generate migration**

```bash
php artisan make:migration add_loaded_km_to_orders --table=orders --no-interaction
```

- [ ] **Step 2: Edit migration**

In the generated migration file, add the column:

```php
Schema::table('orders', function (Blueprint $table) {
    $table->decimal('loaded_km', 10, 1)->nullable()->after('status')
        ->comment('Km có hàng của đơn này trong chuyến');
});
```

- [ ] **Step 3: Run migration**

```bash
php artisan migrate --no-interaction
```

Expected: Migration runs successfully, `loaded_km` column added.

- [ ] **Step 4: Update Order model**

In `app/Models/Order.php`, add `'loaded_km'` to `$fillable` (line ~36) and casts (line ~54):

Edit fillable — after `'notes',` add `'loaded_km',`:

```
        'cancel_reason',
        'notes',
        'loaded_km',
    ];
```

Edit casts — after `'priority' => Priority::class,` add `'loaded_km' => 'decimal:1',`:

```
            'priority' => Priority::class,
            'loaded_km' => 'decimal:1',
        ];
```

- [ ] **Step 5: Format + commit**

```bash
vendor/bin/pint --format agent
git add database/migrations/ app/Models/Order.php
git commit -m "feat: add loaded_km column to orders"
```

---

### Task 2: TripKmCalculatorService — remove guard + record per-order KM

**Covers:** [S4]

**Files:**
- Modify: `app/Services/TripKmCalculatorService.php:10-84`

**Interfaces:**
- Consumes: `Order::$fillable` includes `loaded_km` (from Task 1)
- Produces: `TripKmCalculatorService::calculate()` no longer short-circuits; sets `order.loaded_km` for each order

- [ ] **Step 1: Remove the idempotency guard**

In `app/Services/TripKmCalculatorService.php`, delete lines 14-16:

```php
        // DELETE these 3 lines:
        if ($trip->total_km_loaded !== null) {
            return;
        }
```

- [ ] **Step 2: Add per-order loaded_km recording**

After the existing sweep-line loop (after line 77, before line 79), add order KM recording. Insert this block:

```php
        // Record per-order loaded_km
        $orderIds = $events->pluck('order_id')->unique();
        foreach ($orderIds as $orderId) {
            $pickup = $events
                ->where('order_id', $orderId)
                ->where('checkpoint_type', 'arrived_pickup')
                ->first();

            $complete = $events
                ->where('order_id', $orderId)
                ->where('checkpoint_type', 'completed')
                ->sortByDesc('km_reading')
                ->first();

            if ($pickup && $complete) {
                $orderLoadedKm = max(0, (float) $complete->km_reading - (float) $pickup->km_reading);
                \App\Models\Order::where('id', $orderId)->update(['loaded_km' => $orderLoadedKm]);
            }
        }
```

- [ ] **Step 3: Add `use App\Models\Order;` import**

Add at line 6 (after existing `use` statements):

```php
use App\Models\Order;
```

- [ ] **Step 4: Run existing tests to verify no regression**

```bash
php artisan test --compact --filter="TripKmTest"
```

Expected: All TripKmTest pass (may need updating if tests assert on `total_km_loaded !== null` guard behavior).

- [ ] **Step 5: Format + commit**

```bash
vendor/bin/pint --format agent
git add app/Services/TripKmCalculatorService.php
git commit -m "feat: remove recalculation guard, record per-order loaded_km"
```

---

### Task 3: CompletedHandler — record order.loaded_km on order complete

**Covers:** [S5]

**Files:**
- Modify: `app/Services/Trip/Handlers/CompletedHandler.php:48-61`

**Interfaces:**
- Consumes: `Order` has `loaded_km` fillable (from Task 1)
- Produces: `CompletedHandler::completeOrders()` also sets `order.loaded_km`

- [ ] **Step 1: Modify `completeOrders()` to record loaded_km**

In `app/Services/Trip/Handlers/CompletedHandler.php`, replace the `completeOrders` method (lines 48-61):

```php
    /** @param  Collection<int, TripCheckpoint>  $checkpoints */
    private function completeOrders(Collection $checkpoints): void
    {
        $orderIds = $checkpoints->pluck('order_id')->unique();

        foreach ($orderIds as $orderId) {
            $hasUndeliveredPoints = OrderDeliveryPoint::where('order_id', $orderId)
                ->where('status', '!=', OrderDeliveryPointStatus::Delivered)
                ->exists();

            if (! $hasUndeliveredPoints) {
                Order::where('id', $orderId)->update(['status' => OrderStatus::Completed]);

                // Record per-order loaded_km
                $pickupCheckpoint = TripCheckpoint::where('order_id', $orderId)
                    ->where('checkpoint_type', 'arrived_pickup')
                    ->whereNotNull('km_reading')
                    ->first();

                $completeCheckpoint = TripCheckpoint::where('order_id', $orderId)
                    ->where('checkpoint_type', 'completed')
                    ->whereNotNull('km_reading')
                    ->orderBy('km_reading', 'desc')
                    ->first();

                if ($pickupCheckpoint && $completeCheckpoint) {
                    $loadedKm = max(0, (float) $completeCheckpoint->km_reading - (float) $pickupCheckpoint->km_reading);
                    Order::where('id', $orderId)->update(['loaded_km' => $loadedKm]);
                }
            }
        }
    }
```

- [ ] **Step 2: Format + commit**

```bash
vendor/bin/pint --format agent
git add app/Services/Trip/Handlers/CompletedHandler.php
git commit -m "feat: record per-order loaded_km on order complete"
```

---

### Task 4: ShiftKmCalculatorService — record per-order KM

**Covers:** [S6]

**Files:**
- Modify: `app/Services/ShiftKmCalculatorService.php:10-90`

**Interfaces:**
- Consumes: `Order` has `loaded_km` fillable (from Task 1)
- Produces: Adds per-order loaded_km recording at shift level

- [ ] **Step 1: Add Order import**

In `app/Services/ShiftKmCalculatorService.php`, add after `use App\Models\DriverShift;`:

```php
use App\Models\Order;
```

- [ ] **Step 2: Add per-order loaded_km recording**

After the sweep-line loop (after line 84, before line 86), add the same order KM block:

```php
        // Record per-order loaded_km
        $orderIds = $events->pluck('order_id')->unique();
        foreach ($orderIds as $orderId) {
            $pickup = $events
                ->where('order_id', $orderId)
                ->where('checkpoint_type', 'arrived_pickup')
                ->first();

            $complete = $events
                ->where('order_id', $orderId)
                ->where('checkpoint_type', 'completed')
                ->sortByDesc('km_reading')
                ->first();

            if ($pickup && $complete) {
                $orderLoadedKm = max(0, (float) $complete->km_reading - (float) $pickup->km_reading);
                Order::where('id', $orderId)->update(['loaded_km' => $orderLoadedKm]);
            }
        }
```

- [ ] **Step 3: Format + commit**

```bash
vendor/bin/pint --format agent
git add app/Services/ShiftKmCalculatorService.php
git commit -m "feat: record per-order loaded_km in shift KM calculator"
```

---

### Task 5: Run full test suite + final verify

**Covers:** [S9]

- [ ] **Step 1: Run all tests**

```bash
php artisan test --compact
```

Expected: No new failures beyond pre-existing. TripKmTest, OrderFullFlowTest, TripCheckpointTest all pass.

- [ ] **Step 2: Run pint**

```bash
vendor/bin/pint --format agent
```

- [ ] **Step 3: Push**

```bash
git push
```
