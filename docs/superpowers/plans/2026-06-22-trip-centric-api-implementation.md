# Trip-Centric API Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rewrite mobile checkpoint API from order-centric to trip-centric, and move related Filament actions to TripResource.

**Architecture:** Single `POST /api/trips/{trip}/checkpoints` replaces `POST /api/driver/checkpoints`. Trip-level events (started, arrived_pickup, left_pickup) auto-broadcast to all orders. Order-specific events (arrived_delivery, completed) need `order_id` + `delivery_point_id`. New `TripResource` in Filament for admin actions.

**Tech Stack:** Laravel 13, Filament 5, Pest 4

---

## File Structure

| File | Status | Responsibility |
|---|---|---|
| `routes/api.php` | Modify | Replace `POST /api/driver/checkpoints` with `POST /api/trips/{trip}/checkpoints` |
| `app/Http/Controllers/Api/TripCheckpointController.php` | Rewrite | Accept `{trip}` route param, handle all checkpoint types |
| `app/Http/Requests/TripCheckpointRequest.php` | Create | Validation for trip-based checkpoint posting |
| `app/Http/Controllers/Api/DriverShiftController.php` | Modify | `end()` sets incomplete trips to `driver_swap`, clears `trip.shift_id` |
| `app/Http/Controllers/Api/DriverSwapController.php` | Fix | `$order->vehicle_id` → `$order->trip?->vehicle_id`, add `trip_id` to create |
| `app/Http/Resources/DriverSwapResource.php` | Fix | `$this->order_id` → `$this->trip_id` |
| `app/Models/User.php` | Fix | `orders()` relationship references non-existent `driver_id` column |
| `app/Filament/Resources/TripResource.php` | Create | New Filament resource with TripForm, TripTable, relation managers |
| `app/Filament/Resources/Orders/Actions/AssignTransportAction.php` | Modify | Create Trip if needed, link orders via trip |
| `app/Filament/Resources/Orders/Actions/DriverSwapAction.php` | Modify | Swap driver on Trip, not Order |
| `app/Filament/Resources/Orders/Tables/OrdersTable.php` | Modify | Remove `vehicle_id`/`driver_id` from edit action |
| `app/Http/Resources/TripCheckpointResource.php` | Check | `order_id` field should be nullable |
| `tests/Feature/OrderFullFlowTest.php` | Rewrite | Adapt to trip-centric checkpoint posting |

---

### Task 1: Add route + Create TripCheckpointRequest

**Files:**
- Modify: `routes/api.php`
- Create: `app/Http/Requests/TripCheckpointRequest.php`

- [ ] **Step 1: Add new route in `routes/api.php`**

In the `auth:sanctum` driver group, after existing routes, add:

```php
use App\Http\Controllers\Api\TripCheckpointController;

Route::post('/trips/{trip}/checkpoints', [TripCheckpointController::class, 'checkpoint']);
```

Remove the old route line:
```php
Route::post('checkpoints', [TripCheckpointController::class, 'checkpoint'])->name('driver.checkpoints');
```

- [ ] **Step 2: Create `TripCheckpointRequest.php`**

```php
<?php

namespace App\Http\Requests;

use App\Enums\CheckpointType;
use App\Http\Requests\Concerns\NormalizesDecimalInput;
use App\Models\Trip;
use App\Models\TripCheckpoint;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class TripCheckpointRequest extends FormRequest
{
    use NormalizesDecimalInput;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $type = $this->input('checkpoint_type');

        $orderIdRules = ['nullable', 'exists:orders,id'];
        $deliveryPointIdRules = ['nullable', 'exists:order_delivery_points,id'];

        if (in_array($type, ['arrived_delivery', 'completed'], true)) {
            $orderIdRules = ['required', 'exists:orders,id'];
            $deliveryPointIdRules = ['required', 'exists:order_delivery_points,id'];
        }

        return [
            'checkpoint_type' => ['required', 'string', Rule::in(array_map(fn ($case) => $case->value, CheckpointType::cases()))],
            'order_id' => $orderIdRules,
            'delivery_point_id' => $deliveryPointIdRules,
            'occurred_at' => 'nullable|date',
            'km_reading' => [
                'nullable',
                'numeric',
                Rule::when($type === 'started', 'prohibited'),
                Rule::when(in_array($type, ['arrived_pickup', 'completed'], true), 'required'),
            ],
            'gps_lat' => 'nullable|numeric',
            'gps_lng' => 'nullable|numeric',
            'voice_note' => 'nullable|string',
            'photos' => 'nullable|array',
            'photos.*' => 'nullable|image|max:10240',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'km_reading' => $this->normalizeDecimal($this->input('km_reading')),
            'gps_lat' => $this->normalizeDecimal($this->input('gps_lat')),
            'gps_lng' => $this->normalizeDecimal($this->input('gps_lng')),
        ]);
    }

    public function after(): array
    {
        return [
            // KM must be >= last known KM for that order
            function (\Illuminate\Validation\Validator $validator) {
                if ($this->input('km_reading') === null || $this->input('order_id') === null) {
                    return;
                }

                $lastOrderKm = TripCheckpoint::where('order_id', $this->input('order_id'))
                    ->whereNotNull('km_reading')
                    ->orderBy('occurred_at', 'desc')
                    ->value('km_reading');

                if ($lastOrderKm !== null && (float) $this->input('km_reading') < (float) $lastOrderKm) {
                    $message = 'Số km phải lớn hơn hoặc bằng km gần nhất của đơn hàng này ('.number_format((float) $lastOrderKm, 1).' km)';
                    throw new HttpResponseException(response()->json(['message' => $message], 422));
                }
            },

            // Completed: km must be > km at left_pickup of same order
            function (\Illuminate\Validation\Validator $validator) {
                if ($this->input('checkpoint_type') !== 'completed' || $this->input('km_reading') === null || $this->input('order_id') === null) {
                    return;
                }

                $leftPickupKm = TripCheckpoint::where('order_id', $this->input('order_id'))
                    ->where('checkpoint_type', 'left_pickup')
                    ->value('km_reading');

                if ($leftPickupKm !== null && (float) $this->input('km_reading') <= (float) $leftPickupKm) {
                    $message = sprintf(
                        'Số km kết thúc phải lớn hơn km lúc rời điểm nhận (%.1f km)',
                        $leftPickupKm
                    );
                    throw new HttpResponseException(response()->json(['message' => $message], 422));
                }
            },
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'message' => $validator->errors(),
        ], 422));
    }
}
```

- [ ] **Step 3: Verify routes compile**

Run: `php artisan route:list --path=api --except-vendor | grep -c trips`
Expected: `1` (the new route)

- [ ] **Step 4: Commit**

```bash
git add routes/api.php app/Http/Requests/TripCheckpointRequest.php
git commit -m "feat: add POST /api/trips/{trip}/checkpoints route and request"
```

---

### Task 2: Rewrite TripCheckpointController

**Files:**
- Rewrite: `app/Http/Controllers/Api/TripCheckpointController.php`
- Test: `tests/Feature/TripCheckpointTest.php`

- [ ] **Step 1: Write failing test for started checkpoint**

Create `tests/Feature/TripCheckpointTest.php`:

```php
<?php

use App\Enums\CheckpointType;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\ShiftType;
use App\Enums\TripStatus;
use App\Enums\VehicleOwnerType;
use App\Enums\VehicleStatus;
use App\Enums\VehicleType;
use App\Models\Area;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderDeliveryPoint;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->driverRole = Role::create(['name' => 'driver', 'guard_name' => 'web']);

    $this->area = Area::factory()->create(['type' => OrderType::Hhhk]);
    $this->customer = Customer::factory()->create();
    $this->vehicle = Vehicle::factory()->create([
        'status' => VehicleStatus::On,
        'current_mileage' => 50000,
    ]);
    $this->driver = User::factory()->create();
    $this->driver->assignRole($this->driverRole);
    $this->vehicle->update(['current_driver_id' => $this->driver->id]);

    $this->trip = Trip::create([
        'trip_code' => 'TRIP-001',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'status' => TripStatus::Pending,
    ]);

    $this->order1 = Order::create([
        'order_code' => 'ORD-001',
        'type' => OrderType::Hhhk,
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'trip_id' => $this->trip->id,
        'status' => OrderStatus::Sent,
        'created_by' => $this->driver->id,
    ]);

    $this->order2 = Order::create([
        'order_code' => 'ORD-002',
        'type' => OrderType::Hhhk,
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'trip_id' => $this->trip->id,
        'status' => OrderStatus::Sent,
        'created_by' => $this->driver->id,
    ]);

    Sanctum::actingAs($this->driver);
});

test('started creates checkpoints for all orders in trip', function () {
    $this->postJson("/api/trips/{$this->trip->id}/checkpoints", [
        'checkpoint_type' => 'started',
        'occurred_at' => now()->toIso8601String(),
        'gps_lat' => 10.818889,
        'gps_lng' => 106.651944,
    ])->assertSuccessful();

    $this->trip->refresh();
    expect($this->trip->status)->toBe(TripStatus::Started);

    $checkpoints = $this->trip->checkpoints;
    expect($checkpoints)->toHaveCount(2);
    expect($checkpoints->pluck('order_id')->sort()->values()->toArray())->toBe([$this->order1->id, $this->order2->id]);
});

test('started updates trip.shift_id from driver active shift', function () {
    $shiftResponse = $this->postJson('/api/driver/shifts/start', [
        'shift_type' => 'full',
        'start_time' => now()->toIso8601String(),
        'vehicle_id' => $this->vehicle->id,
    ])->assertSuccessful();
    $shiftId = $shiftResponse->json('shift.id');

    $this->postJson("/api/trips/{$this->trip->id}/checkpoints", [
        'checkpoint_type' => 'started',
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();

    $this->trip->refresh();
    expect((int) $this->trip->shift_id)->toBe($shiftId);
});

test('arrived_pickup requires km_reading', function () {
    $this->postJson("/api/trips/{$this->trip->id}/checkpoints", [
        'checkpoint_type' => 'arrived_pickup',
        'occurred_at' => now()->toIso8601String(),
        'km_reading' => 50010,
    ])->assertSuccessful();

    $this->trip->refresh();
    expect($this->trip->status)->toBe(TripStatus::ArrivedPickup);
});

test('arrived_delivery requires order_id and delivery_point_id', function () {
    $dp = OrderDeliveryPoint::create([
        'order_id' => $this->order1->id,
        'sequence' => 1,
        'status' => 'pending',
    ]);

    $this->postJson("/api/trips/{$this->trip->id}/checkpoints", [
        'checkpoint_type' => 'arrived_delivery',
        'order_id' => $this->order1->id,
        'delivery_point_id' => $dp->id,
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();

    $this->trip->refresh();
    expect($this->trip->status)->toBe(TripStatus::ArrivedDelivery);
    expect($dp->fresh()->status)->toBe('arrived');
});

test('completed without order_id fails validation', function () {
    $this->postJson("/api/trips/{$this->trip->id}/checkpoints", [
        'checkpoint_type' => 'completed',
        'km_reading' => 50050,
    ])->assertStatus(422);
});

test('unauthorized driver gets 403', function () {
    $otherDriver = User::factory()->create();
    $otherDriver->assignRole($this->driverRole);
    Sanctum::actingAs($otherDriver);

    $this->postJson("/api/trips/{$this->trip->id}/checkpoints", [
        'checkpoint_type' => 'started',
    ])->assertStatus(403);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact tests/Feature/TripCheckpointTest.php`
Expected: FAIL (route exists but controller not updated yet)

- [ ] **Step 3: Rewrite TripCheckpointController**

The controller now accepts `Trip` route binding instead of request input for trip_id.

```php
<?php

namespace App\Http\Controllers\Api;

use App\Enums\CheckpointType;
use App\Enums\OrderDeliveryPointStatus;
use App\Enums\OrderStatus;
use App\Enums\TripStatus;
use App\Enums\VehicleStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\TripCheckpointRequest;
use App\Http\Resources\TripCheckpointResource;
use App\Models\DriverShift;
use App\Models\Location;
use App\Models\Order;
use App\Models\OrderDeliveryPoint;
use App\Models\Trip;
use App\Models\TripCheckpoint;
use App\Models\TripPhoto;
use App\Models\Vehicle;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class TripCheckpointController extends Controller
{
    public function checkpoint(TripCheckpointRequest $request, Trip $trip): JsonResponse
    {
        $user = $request->user();

        if ($trip->driver_id !== $user->id) {
            return response()->json(['message' => 'Bạn không phải tài xế được gán cho chuyến này'], 403);
        }

        $payload = $request->validated();
        DB::beginTransaction();
        try {
            $checkpointType = CheckpointType::from($payload['checkpoint_type']);

            if (in_array($checkpointType, [CheckpointType::ArrivedDelivery, CheckpointType::Completed], true)) {
                $order = Order::findOrFail($payload['order_id']);

                if ($order->trip_id !== $trip->id) {
                    return response()->json(['message' => 'Order không thuộc chuyến này'], 422);
                }
            }

            // Ensure trip.shift_id is set from driver's active shift
            if ($trip->shift_id === null) {
                $activeShift = DriverShift::where('driver_id', $user->id)
                    ->where('vehicle_id', $trip->vehicle_id)
                    ->whereNull('end_time')
                    ->first();
                if ($activeShift !== null) {
                    $trip->shift_id = $activeShift->id;
                    $trip->save();
                }
            }

            $checkpoint = $this->createCheckpoint($trip, $user, $payload, $checkpointType);

            $this->updateVehicleFromCheckpoint($trip, $payload);

            if ($request->hasFile('photos')) {
                $files = Arr::wrap($request->file('photos'));
                foreach ($files as $file) {
                    if ($file === null) {
                        continue;
                    }
                    $path = $file->store('trip_photos', 'public');
                    /** @var FilesystemAdapter $disk */
                    $disk = Storage::disk('public');
                    TripPhoto::create([
                        'trip_checkpoint_id' => $checkpoint->id,
                        'photo_path' => $path,
                        'photo_url' => $disk->url($path),
                    ]);
                }
            }

            match ($checkpointType) {
                CheckpointType::Started => $this->handleStarted($trip, $user, $payload),
                CheckpointType::ArrivedPickup => $this->handleArrivedPickup($trip),
                CheckpointType::LeftPickup => $this->handleLeftPickup($trip),
                CheckpointType::ArrivedDelivery => $this->handleArrivedDelivery($trip, $payload),
                CheckpointType::Completed => $this->handleCompleted($trip, $payload),
                CheckpointType::DriverSwap => null,
            };

            DB::commit();

            $checkpoint->load('photos');

            return response()->json(['checkpoint' => TripCheckpointResource::make($checkpoint)]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json(['message' => 'Unable to record checkpoint', 'error' => $e->getMessage()], 500);
        }
    }

    private function createCheckpoint(Trip $trip, $user, array $payload, CheckpointType $type): TripCheckpoint
    {
        $orders = $trip->orders;

        if ($type === CheckpointType::Started) {
            $checkpoint = null;
            foreach ($orders as $order) {
                $checkpoint = TripCheckpoint::create([
                    'trip_id' => $trip->id,
                    'order_id' => $order->id,
                    'driver_id' => $trip->driver_id,
                    'shift_id' => $trip->shift_id,
                    'checkpoint_type' => $type->value,
                    'occurred_at' => $payload['occurred_at'] ?? now(),
                    'km_reading' => $payload['km_reading'] ?? null,
                    'gps_lat' => $payload['gps_lat'] ?? null,
                    'gps_lng' => $payload['gps_lng'] ?? null,
                    'voice_note' => $payload['voice_note'] ?? null,
                ]);
            }

            return $checkpoint;
        }

        return TripCheckpoint::create([
            'trip_id' => $trip->id,
            'order_id' => $payload['order_id'] ?? null,
            'delivery_point_id' => $payload['delivery_point_id'] ?? null,
            'driver_id' => $trip->driver_id,
            'shift_id' => $trip->shift_id,
            'checkpoint_type' => $type->value,
            'occurred_at' => $payload['occurred_at'] ?? now(),
            'km_reading' => $payload['km_reading'] ?? null,
            'gps_lat' => $payload['gps_lat'] ?? null,
            'gps_lng' => $payload['gps_lng'] ?? null,
            'voice_note' => $payload['voice_note'] ?? null,
        ]);
    }

    private function handleStarted(Trip $trip, $user, array $payload): void
    {
        if ($trip->isPending()) {
            $vehicle = $trip->vehicle;
            $trip->status = TripStatus::Started;
            $trip->started_at = $payload['occurred_at'] ?? now();
            $trip->start_km = $vehicle?->current_mileage ?? $trip->start_km;
            $trip->save();
        }

        $trip->orders()
            ->where('status', OrderStatus::Sent)
            ->whereNotNull('sent_at', null)
            ->update(['sent_at' => $payload['occurred_at'] ?? now()]);
    }

    private function handleArrivedPickup(Trip $trip): void
    {
        $trip->status = TripStatus::ArrivedPickup;
        $trip->save();
    }

    private function handleLeftPickup(Trip $trip): void
    {
        $trip->status = TripStatus::Delivering;
        $trip->save();
    }

    private function handleArrivedDelivery(Trip $trip, array $payload): void
    {
        $trip->status = TripStatus::ArrivedDelivery;
        $trip->save();

        $this->updateDeliveryPoint($payload, OrderDeliveryPointStatus::Arrived);
    }

    private function handleCompleted(Trip $trip, array $payload): void
    {
        $this->updateDeliveryPoint($payload, OrderDeliveryPointStatus::Delivered);

        $order = Order::findOrFail($payload['order_id']);
        $order->status = OrderStatus::Completed;
        $order->save();

        $hasMoreActiveInTrip = $trip->orders()
            ->where('id', '!=', $order->id)
            ->whereIn('status', [OrderStatus::Assigned, OrderStatus::Sent])
            ->exists();

        if (! $hasMoreActiveInTrip) {
            $trip->complete(
                endKm: $payload['km_reading'] ?? null,
                completedAt: $payload['occurred_at'] ?? now(),
            );

            TripCheckpoint::create([
                'trip_id' => $trip->id,
                'driver_id' => $trip->driver_id,
                'shift_id' => $trip->shift_id,
                'checkpoint_type' => CheckpointType::Completed->value,
                'occurred_at' => $payload['occurred_at'] ?? now(),
                'km_reading' => $payload['km_reading'] ?? null,
                'gps_lat' => $payload['gps_lat'] ?? null,
                'gps_lng' => $payload['gps_lng'] ?? null,
            ]);
        }

        $hasMoreActiveOnVehicle = Order::whereHas('trip', fn ($q) => $q->where('vehicle_id', $trip->vehicle_id))
            ->where('id', '!=', $order->id)
            ->whereIn('status', [OrderStatus::Assigned, OrderStatus::Sent])
            ->exists();

        if (! $hasMoreActiveOnVehicle) {
            Vehicle::where('id', $trip->vehicle_id)->update(['status' => VehicleStatus::On]);
        }
    }

    private function updateVehicleFromCheckpoint(Trip $trip, array $payload): void
    {
        $vehicle = $trip->vehicle;
        if ($vehicle === null) {
            return;
        }

        $dirty = false;
        if (isset($payload['km_reading'])) {
            $vehicle->current_mileage = $payload['km_reading'];
            $dirty = true;
        }
        if (isset($payload['gps_lat'])) {
            $vehicle->gps_lat = $payload['gps_lat'];
            $dirty = true;
        }
        if (isset($payload['gps_lng'])) {
            $vehicle->gps_lng = $payload['gps_lng'];
            $dirty = true;
        }
        if ($dirty) {
            $vehicle->save();
        }
    }

    private function updateDeliveryPoint(array $payload, OrderDeliveryPointStatus $status): void
    {
        $deliveryPointId = $payload['delivery_point_id'] ?? null;
        if ($deliveryPointId === null) {
            return;
        }

        $point = OrderDeliveryPoint::find($deliveryPointId);
        if ($point === null) {
            return;
        }

        if ($status === OrderDeliveryPointStatus::Arrived && $point->status !== OrderDeliveryPointStatus::Pending) {
            return;
        }
        if ($status === OrderDeliveryPointStatus::Delivered && $point->status === OrderDeliveryPointStatus::Delivered && $point->delivered_at !== null) {
            return;
        }

        $point->status = $status;
        if ($status === OrderDeliveryPointStatus::Arrived) {
            $point->arrived_at = $payload['occurred_at'] ?? now();
        } elseif ($status === OrderDeliveryPointStatus::Delivered) {
            $point->delivered_at = $payload['occurred_at'] ?? now();
        }
        $point->save();
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact tests/Feature/TripCheckpointTest.php`
Expected: PASS (all 5-6 tests)

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/TripCheckpointController.php tests/Feature/TripCheckpointTest.php
git commit -m "feat: rewrite TripCheckpointController as trip-centric"
```

---

### Task 3: Update DriverShiftController@end for trip-level swap

**Files:**
- Modify: `app/Http/Controllers/Api/DriverShiftController.php`

- [ ] **Step 1: Update the `end()` method**

Find where `end()` sets driver_swap on orders. Replace order-level logic with trip-level logic. Current code likely iterates orders and sets `$order->status = DriverSwap`. Change to:

```php
// After ending the shift, set incomplete trips to driver_swap
$incompleteTrips = Trip::where('driver_id', $user->id)
    ->whereHas('orders', function ($q) {
        $q->whereIn('status', [OrderStatus::Sent, OrderStatus::Assigned]);
    })
    ->whereIn('status', [TripStatus::Started, TripStatus::ArrivedPickup, TripStatus::Delivering, TripStatus::ArrivedDelivery])
    ->get();

foreach ($incompleteTrips as $trip) {
    $trip->status = TripStatus::DriverSwap;
    $trip->shift_id = null;
    $trip->save();

    TripCheckpoint::create([
        'trip_id' => $trip->id,
        'driver_id' => $user->id,
        'shift_id' => $shift->id,
        'checkpoint_type' => CheckpointType::DriverSwap->value,
        'occurred_at' => now(),
        'km_reading' => $payload['end_km'] ?? null,
    ]);
}
```

- [ ] **Step 2: Run test to confirm no breakage**

Run: `php artisan test --compact tests/Feature/OrderFlowHHHKTest.php`
Expected: PASS

- [ ] **Step 3: Commit**

```bash
git add app/Http/Controllers/Api/DriverShiftController.php
git commit -m "fix: update shift end to handle trip-level driver swap"
```

---

### Task 4: Fix DriverSwapController bugs

**Files:**
- Modify: `app/Http/Controllers/Api/DriverSwapController.php`
- Modify: `app/Http/Resources/DriverSwapResource.php`

- [ ] **Step 1: Fix DriverSwapController**

In `DriverSwapController@store`:

Line 62: `$order->vehicle_id` → `$order->trip?->vehicle_id`
Line 75: `$order->vehicle_id` → `$order->trip?->vehicle_id`
Line 81-91: Replace `'order_id' => $order->id` with `'trip_id' => $order->trip_id`
Line 93-102: Add `'trip_id' => $order->trip_id` to TripCheckpoint create
Line 128-130: Remove `$order->shift_id = ...` (column doesn't exist), keep only `$order->status = OrderStatus::Assigned`

- [ ] **Step 2: Fix DriverSwapResource**

Find `$this->order_id` in the resource. Replace with `$this->trip_id`.

- [ ] **Step 3: Commit**

```bash
git add app/Http/Controllers/Api/DriverSwapController.php app/Http/Resources/DriverSwapResource.php
git commit -m "fix: DriverSwapController bugs (order_id->trip_id, vehicle_id->trip.vehicle_id)"
```

---

### Task 5: Fix User::orders() relationship

**Files:**
- Modify: `app/Models/User.php`

- [ ] **Step 1: Fix the `orders()` relationship**

Current broken code:
```php
public function orders(): HasMany
{
    return $this->hasMany(Order::class, 'driver_id');
}
```

Replace with:
```php
public function orders(): HasManyThrough
{
    return $this->hasManyThrough(Order::class, Trip::class, 'driver_id', 'trip_id');
}
```

Or simpler, just add a docblock deprecation and let the code use `trips()` instead:
```php
/**
 * @deprecated Use trips() relationship instead. This queries via Trip which may be slow.
 */
public function orders(): HasManyThrough
{
    return $this->hasManyThrough(Order::class, Trip::class, 'driver_id', 'trip_id');
}
```

- [ ] **Step 2: Check callers**

Run: `rg "\->orders\b" app/` — verify all callers of `$user->orders` (if any) handle the new return type.

- [ ] **Step 3: Commit**

```bash
git add app/Models/User.php
git commit -m "fix: User::orders() relationship - use hasManyThrough Trip instead of non-existent driver_id"
```

---

### Task 6: Create TripResource for Filament

**Files:**
- Create: `app/Filament/Resources/TripResource.php`
- Create: `app/Filament/Resources/TripResource/Pages/ListTrips.php`
- Create: `app/Filament/Resources/TripResource/Pages/ViewTrip.php`
- Create: `app/Filament/Resources/TripResource/Pages/EditTrip.php`

- [ ] **Step 1: Generate TripResource**

Run: `php artisan make:filament-resource Trip --soft-deletes --generate --no-interaction`

If the command asks questions, answer:
- Model: `Trip`
- Resource: `TripResource`
- Pages: ListTrips, ViewTrip, EditTrip

- [ ] **Step 2: Customize TripResource form**

Main fields: vehicle (select), driver (select), shift (select, nullable), status (select), trip_code (text, readonly)

- [ ] **Step 3: Add Order relation manager**

Trip has many Orders — show orders table inside Trip view/list.

- [ ] **Step 4: Commit**

```bash
git add app/Filament/Resources/TripResource.php app/Filament/Resources/TripResource/
git commit -m "feat: create TripResource in Filament"
```

---

### Task 7: Update Filament actions (AssignTransport + DriverSwap)

**Files:**
- Modify: `app/Filament/Resources/Orders/Actions/AssignTransportAction.php`
- Modify: `app/Filament/Resources/Orders/Actions/DriverSwapAction.php`

- [ ] **Step 1: Update AssignTransportAction**

Currently this creates a Trip for a single order. After the change, if order already has a trip, add to existing trip (create new trip if needed). Since orders can share a trip, the action should:

- If single order selected: check if trip exists, if not create one
- Set vehicle_id + driver_id on trip (already done)
- Link order to trip via `order.trip_id`

- [ ] **Step 2: Update DriverSwapAction**

Currently swaps driver on an Order. Change to swap on Trip:

- Select trip (filter by `status = driver_swap`)
- Select new driver
- Set `trip.driver_id`, `trip.status = pending`
- Create DriverSwap record with `trip_id`

- [ ] **Step 3: Commit**

```bash
git add app/Filament/Resources/Orders/Actions/
git commit -m "feat: update AssignTransportAction and DriverSwapAction for trip-centric"
```

---

### Task 8: Update OrdersTable edit action

**Files:**
- Modify: `app/Filament/Resources/Orders/Tables/OrdersTable.php`

- [ ] **Step 1: Remove vehicle/driver fields from edit action**

In the `before()` callback of the EditAction (around line 234), remove the vehicle_id validation that checks driver shifts. Orders no longer have vehicle_id/driver_id, so this check is meaningless.

- [ ] **Step 2: Commit**

```bash
git add app/Filament/Resources/Orders/Tables/OrdersTable.php
git commit -m "fix: remove vehicle/driver validation from Order edit action"
```

---

### Task 9: Update OrderFullFlowTest

**Files:**
- Modify: `tests/Feature/OrderFullFlowTest.php`

- [ ] **Step 1: Rewrite the first test (full lifecycle without swap)**

Change from posting to `/api/driver/checkpoints` with `order_id` to posting to `/api/trips/{trip}/checkpoints`:

```php
// Before: post to /api/driver/checkpoints with order_id
// After: create trip, post to /api/trips/{trip}/checkpoints

$trip = Trip::create([
    'trip_code' => 'TRIP-FULL-001',
    'vehicle_id' => $this->vehicle->id,
    'driver_id' => $driver->id,
    'status' => TripStatus::Pending,
]);

$order->update(['trip_id' => $trip->id]);

// Started
$this->postJson("/api/trips/{$trip->id}/checkpoints", [
    'checkpoint_type' => 'started',
    'occurred_at' => now()->toIso8601String(),
])->assertSuccessful();

// Assert trip status, not order status
expect($trip->fresh()->status)->toBe(TripStatus::Started);
```

Similar changes for the swap tests.

- [ ] **Step 2: Run the test**

Run: `php artisan test --compact tests/Feature/OrderFullFlowTest.php`
Expected: PASS

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/OrderFullFlowTest.php
git commit -m "test: update OrderFullFlowTest for trip-centric API"
```

---

### Task 10: Final cleanup and full test suite

**Files:**
- Any remaining files

- [ ] **Step 1: Check for any remaining references to old `POST /api/driver/checkpoints`**

Run: `rg "api/driver/checkpoints" app/ tests/`
Expected: No results (old endpoint removed)

- [ ] **Step 2: Run full test suite**

Run: `php artisan test --compact`
Expected: All relevant tests pass (pre-existing SQLite nested-transaction errors may still occur but no new failures)

- [ ] **Step 3: Run Pint**

Run: `vendor/bin/pint --format agent`

- [ ] **Step 4: Commit**

```bash
git add -A
git commit -m "chore: cleanup after trip-centric API migration"
```
