# Trip History API Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add `GET /api/driver/trips/history` endpoint returning paginated trip history for the authenticated driver.

**Architecture:** Add `history()` method to existing `TripController`, reuse `TripResource`, add `driverSwaps` to resource. One new test file.

**Tech Stack:** Laravel, PHP 8.4, Pest

---

### Task 1: Update TripResource to include driverSwaps

**Files:**
- Modify: `app/Http/Resources/TripResource.php:24`

- [ ] **Step 1: Add driverSwaps to TripResource**

Add after `checkpoints` line:

```php
            'driverSwaps' => DriverSwapResource::collection($this->whenLoaded('driverSwaps')),
```

- [ ] **Step 2: Run existing tests to confirm no regression**

Run: `php artisan test --compact --filter=TripResourceTest`
Expected: PASS (4 tests)

- [ ] **Step 3: Commit**

```bash
git add app/Http/Resources/TripResource.php
git commit -m "feat: add driverSwaps to TripResource"
```

---

### Task 2: Add history route (before trips/{trip})

**Files:**
- Modify: `routes/api.php`

- [ ] **Step 1: Add trip history route**

Find the existing trip routes block and add the `history` route BEFORE `trips/{trip}`:

```php
Route::get('/driver/trips/history', [TripController::class, 'history']);
```

- [ ] **Step 2: Verify route order**

Run: `php artisan route:list --path=api --method=GET`
Expected: `trips/history` appears BEFORE `trips/{trip}`

- [ ] **Step 3: Commit**

```bash
git add routes/api.php
git commit -m "feat: add trip history route"
```

---

### Task 3: Add history() method to TripController

**Files:**
- Modify: `app/Http/Controllers/Api/TripController.php:86`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/TripHistoryTest.php`:

```php
<?php

use App\Enums\OrderStatus;
use App\Enums\ShiftType;
use App\Enums\TripStatus;
use App\Enums\VehicleOwnerType;
use App\Enums\VehicleStatus;
use App\Enums\VehicleType;
use App\Models\DriverShift;
use App\Models\Order;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $driverRole = Role::create(['name' => 'driver', 'guard_name' => 'web']);
    $this->driver = User::factory()->create();
    $this->driver->assignRole($driverRole);

    $this->vehicle = Vehicle::create([
        'plate_number' => '51C-123.45',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'ASGT',
        'is_active' => true,
        'status' => VehicleStatus::On,
        'type' => VehicleOwnerType::Company,
        'current_mileage' => 15000,
    ]);

    Sanctum::actingAs($this->driver);
});

it('returns paginated completed trips for the driver', function () {
    Trip::factory()->count(3)->create([
        'driver_id' => $this->driver->id,
        'vehicle_id' => $this->vehicle->id,
        'status' => TripStatus::Completed,
        'started_at' => now()->subDays(2),
        'completed_at' => now()->subDay(),
        'start_km' => 15000,
        'end_km' => 15400,
    ]);

    $response = $this->getJson('/api/driver/trips/history');

    $response->assertSuccessful()
        ->assertJsonStructure([
            'data' => [
                '*' => ['id', 'trip_code', 'status', 'started_at', 'completed_at', 'start_km', 'end_km', 'vehicle', 'checkpoints', 'orders'],
            ],
            'meta' => ['current_page', 'last_page', 'per_page', 'total'],
        ])
        ->assertJsonPath('meta.total', 3);
});

it('filters by status', function () {
    Trip::factory()->create([
        'driver_id' => $this->driver->id,
        'vehicle_id' => $this->vehicle->id,
        'status' => TripStatus::Completed,
        'started_at' => now()->subDay(),
    ]);
    Trip::factory()->create([
        'driver_id' => $this->driver->id,
        'vehicle_id' => $this->vehicle->id,
        'status' => TripStatus::DriverSwap,
        'started_at' => now()->subDay(),
    ]);

    $response = $this->getJson('/api/driver/trips/history?status=DriverSwap');

    $response->assertSuccessful()
        ->assertJsonPath('meta.total', 1);
});

it('filters by date range', function () {
    Trip::factory()->create([
        'driver_id' => $this->driver->id,
        'vehicle_id' => $this->vehicle->id,
        'status' => TripStatus::Completed,
        'started_at' => now()->subDays(10),
    ]);
    Trip::factory()->create([
        'driver_id' => $this->driver->id,
        'vehicle_id' => $this->vehicle->id,
        'status' => TripStatus::Completed,
        'started_at' => now()->subDays(3),
    ]);
    Trip::factory()->create([
        'driver_id' => $this->driver->id,
        'vehicle_id' => $this->vehicle->id,
        'status' => TripStatus::Completed,
        'started_at' => now()->subDay(),
    ]);

    $response = $this->getJson('/api/driver/trips/history?from_date=' . now()->subDays(7)->format('Y-m-d') . '&to_date=' . now()->format('Y-m-d'));

    $response->assertSuccessful()
        ->assertJsonPath('meta.total', 2);
});

it('filters by vehicle_id', function () {
    $vehicle2 = Vehicle::create([
        'plate_number' => '51C-999.99',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'ASGT',
        'is_active' => true,
        'status' => VehicleStatus::On,
        'type' => VehicleOwnerType::Company,
    ]);
    Trip::factory()->create([
        'driver_id' => $this->driver->id,
        'vehicle_id' => $this->vehicle->id,
        'status' => TripStatus::Completed,
        'started_at' => now()->subDay(),
    ]);
    Trip::factory()->create([
        'driver_id' => $this->driver->id,
        'vehicle_id' => $vehicle2->id,
        'status' => TripStatus::Completed,
        'started_at' => now()->subDay(),
    ]);

    $response = $this->getJson("/api/driver/trips/history?vehicle_id={$this->vehicle->id}");

    $response->assertSuccessful()
        ->assertJsonPath('meta.total', 1);
});

it('returns empty result for driver with no trips', function () {
    $response = $this->getJson('/api/driver/trips/history');

    $response->assertSuccessful()
        ->assertJsonPath('meta.total', 0)
        ->assertJsonPath('data', []);
});

it('does not return trips belonging to other drivers', function () {
    $otherDriver = User::factory()->create();
    Trip::factory()->create([
        'driver_id' => $otherDriver->id,
        'vehicle_id' => $this->vehicle->id,
        'status' => TripStatus::Completed,
    ]);

    $response = $this->getJson('/api/driver/trips/history');

    $response->assertSuccessful()
        ->assertJsonPath('meta.total', 0);
});

it('requires authentication', function () {
    $this->getJson('/api/driver/trips/history')
        ->assertUnauthorized();
});

it('throws 422 for invalid status filter', function () {
    $this->getJson('/api/driver/trips/history?status=InvalidStatus')
        ->assertStatus(422);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact tests/Feature/TripHistoryTest.php`
Expected: FAIL — "history method not found" or similar

- [ ] **Step 3: Add history() method to TripController**

Add after `show()` method (before closing `}`):

```php
    /**
     * Lịch sử các chuyến đã kết thúc của lái xe.
     *
     * Trả về danh sách trip có trạng thái Completed/DriverSwap/Cancelled,
     * kèm orders, checkpoints, driverSwaps. Có phân trang và filter.
     *
     * @queryParam per_page int Số bản ghi mỗi trang (mặc định 15). Example: 10
     * @queryParam from_date string Lọc từ ngày (started_at >=, ISO date). Example: 2026-06-01
     * @queryParam to_date string Lọc đến ngày (started_at <=, ISO date). Example: 2026-06-23
     * @queryParam status string Lọc theo trạng thái trip (Completed, DriverSwap, Cancelled). Example: Completed
     * @queryParam vehicle_id int Lọc theo ID phương tiện. Example: 1
     *
     * @response array{data: TripResource[], meta: array{current_page: int, last_page: int, per_page: int, total: int}}
     */
    public function history(Request $request): JsonResponse
    {
        $user = $request->user();

        $validStatuses = [TripStatus::Completed, TripStatus::DriverSwap, TripStatus::Cancelled];

        $request->validate([
            'status' => ['nullable', 'string', Rule::in(array_map(fn ($s) => $s->value, $validStatuses))],
            'vehicle_id' => ['nullable', 'integer', 'exists:vehicles,id'],
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $trips = Trip::query()
            ->with([
                'vehicle',
                'shift',
                'driver',
                'driverSwaps.toDriver',
                'orders' => fn ($q) => $q->with([
                    'customer',
                    'pickupLocation',
                    'deliveryPoints.location',
                    'tripCheckpoints' => fn ($q) => $q->with('photos')->orderBy('occurred_at'),
                ]),
                'checkpoints' => fn ($q) => $q->with('photos')->orderBy('occurred_at'),
            ])
            ->where('driver_id', $user->id)
            ->whereIn('status', $validStatuses)
            ->when($request->filled('from_date'), fn ($q) => $q->whereDate('started_at', '>=', $request->from_date))
            ->when($request->filled('to_date'), fn ($q) => $q->whereDate('started_at', '<=', $request->to_date))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('vehicle_id'), fn ($q) => $q->where('vehicle_id', $request->vehicle_id))
            ->orderBy('started_at', 'desc')
            ->paginate($request->integer('per_page', 15));

        return response()->json([
            'data' => TripResource::collection($trips),
            'meta' => [
                'current_page' => $trips->currentPage(),
                'last_page' => $trips->lastPage(),
                'per_page' => $trips->perPage(),
                'total' => $trips->total(),
            ],
        ]);
    }
```

Add the missing import at the top:

```php
use Illuminate\Validation\Rule;
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact tests/Feature/TripHistoryTest.php`
Expected: PASS (8 tests)

- [ ] **Step 5: Run full suite to verify no regression**

Run: `php artisan test --compact`
Expected: 50+ tests, all PASS

- [ ] **Step 6: Run Pint**

Run: `vendor/bin/pint --format agent`
Expected: no issues or auto-fixed

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Api/TripController.php tests/Feature/TripHistoryTest.php
git commit -m "feat: add trip history API endpoint"
```
