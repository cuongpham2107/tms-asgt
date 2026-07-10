# Trip Date Filter & Mobile Order Display Fix

> **For agentic workers:** REQUIRED SUB-SKILL: Use compose:subagent (recommended) or compose:execute to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix two bugs: (1) TripTable defaults date filter to null showing all trips; (2) Mobile API excludes Assigned orders from driver view — only Sent+ orders appear.

**Architecture:** Two independent changes: remove default date assignment in ListTrips::mount(), and add OrderStatus::Assigned to the exclusion list in TripController API methods.

**Tech Stack:** Laravel 13, Filament v5, Livewire, Pest v4

## Global Constraints

- Test with `php artisan test --compact` from repo root
- Format PHP with `vendor/bin/pint --format agent` before finalizing
- Follow existing code conventions (no new abstractions, minimal changes)

---

### Task 1: Remove default date filter in TripTable

**Covers:** TripTable date filter defaults to null

**Files:**
- Modify: `app/Filament/Resources/Trips/Pages/ListTrips.php`
- Test: `tests/Feature/TripResourceTest.php` (add assertions)

**Interfaces:**
- Consumes: none
- Produces: ListTrips no longer auto-sets dateFrom/dateTo in mount()

- [ ] **Step 1: Remove default date assignment in mount()**

In `app/Filament/Resources/Trips/Pages/ListTrips.php`, remove the lines that set default date range in `mount()`. Change from:

```php
public function mount(): void
{
    if (blank($this->dateFrom)) {
        $this->dateFrom = Carbon::today()->format('Y-m-d');
    }

    if (blank($this->dateTo)) {
        $this->dateTo = Carbon::today()->addDay()->format('Y-m-d');
    }

    $this->dateRange = [
        'start' => $this->dateFrom,
        'end' => $this->dateTo,
    ];

    parent::mount();
}
```

To:

```php
public function mount(): void
{
    $this->dateRange = [
        'start' => $this->dateFrom,
        'end' => $this->dateTo,
    ];

    parent::mount();
}
```

- [ ] **Step 2: Add test assertion that trips render without default date filter**

Add a test to `tests/Feature/TripResourceTest.php` that verifies trips with `started_at = null` (pending) still show up when no date filter is applied:

```php
test('trips list shows pending trips when no date filter applied', function () {
    $vehicle = Vehicle::create([
        'plate_number' => '51C-777.77',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'ASGT',
        'is_active' => true,
        'status' => VehicleStatus::On,
        'type' => VehicleOwnerType::Company,
    ]);

    Trip::create([
        'trip_code' => 'TRIP-PENDING-1',
        'vehicle_id' => $vehicle->id,
        'status' => TripStatus::Pending,
        'started_at' => null,
    ]);

    Livewire::test(ListTrips::class)
        ->assertStatus(200)
        ->assertHasNoErrors()
        ->assertSee('TRIP-PENDING-1');
});
```

- [ ] **Step 3: Run tests to verify**

```bash
php artisan test --compact --filter=TripResourceTest
```

Expected: All tests PASS

- [ ] **Step 4: Format and commit**

```bash
vendor/bin/pint --format agent
git add app/Filament/Resources/Trips/Pages/ListTrips.php tests/Feature/TripResourceTest.php
git commit -m "fix: remove default date filter in TripTable, show all trips by default"
```

---

### Task 2: Exclude Assigned orders from driver mobile API

**Covers:** Mobile driver app shows orders with Assigned status before they are sent

**Files:**
- Modify: `app/Http/Controllers/Api/TripController.php`
- Test: `tests/Feature/TripHistoryTest.php` (existing, verify still passes)

**Interfaces:**
- Consumes: OrderStatus enum (app/Enums/OrderStatus.php) — already imported
- Produces: TripController::active/current/show/history exclude OrderStatus::Assigned from orders

- [ ] **Step 1: Add Assigned to order exclusion list in all 4 API methods**

In `app/Http/Controllers/Api/TripController.php`, change `->whereNotIn('status', [OrderStatus::Draft])` to `->whereNotIn('status', [OrderStatus::Draft, OrderStatus::Assigned])` in these four methods:

**active()** (line 37):
```php
'orders' => fn ($q) => $q->whereNull('deleted_at')->whereNotIn('status', [OrderStatus::Draft, OrderStatus::Assigned])->with([
```

**current()** (line 69):
```php
'orders' => fn ($q) => $q->whereNull('deleted_at')->whereNotIn('status', [OrderStatus::Draft, OrderStatus::Assigned])->with([
```

**show()** (line 112):
```php
'orders' => fn ($q) => $q->whereNull('deleted_at')->whereNotIn('status', [OrderStatus::Draft, OrderStatus::Assigned])->with([
```

**history()** (line 160):
```php
'orders' => fn ($q) => $q->whereNull('deleted_at')->whereNotIn('status', [OrderStatus::Draft, OrderStatus::Assigned])->with([
```

- [ ] **Step 2: Run existing tests to verify no regression**

```bash
php artisan test --compact --filter=TripHistoryTest
php artisan test --compact --filter=FullWorkflowTest
php artisan test --compact --filter=OrderFullFlowTest
```

Expected: All tests PASS

- [ ] **Step 3: Verify the fix by running the full TripController test suite**

```bash
php artisan test --compact --filter=TripController
```

- [ ] **Step 4: Format and commit**

```bash
vendor/bin/pint --format agent
git add app/Http/Controllers/Api/TripController.php
git commit -m "fix: exclude Assigned orders from driver mobile API, only show Sent+ orders"
```
