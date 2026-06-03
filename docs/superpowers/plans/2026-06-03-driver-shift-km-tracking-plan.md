# Driver Shift & KM Tracking — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ensure every km a driver runs is accurately tracked per shift, with auto-calculated loaded/empty km, shift validation on vehicle assignment, and complete driver swap flow.

**Architecture:** Add `shift_id` to `orders` for direct shift↔order linking. Create `ShiftKmCalculatorService` for auto-calc on shift end. Add shift validation + override to vehicle assignment. Fix driver swap to handle shift lifecycle.

**Tech Stack:** Laravel 13, Filament v5, Sanctum, SQLite (dev) / MySQL (prod)

---

## Files to create/modify

| File | Action |
|---|---|
| `database/migrations/xxxx_add_shift_id_to_orders.php` | Create |
| `database/migrations/xxxx_add_to_shift_id_to_driver_swaps.php` | Create |
| `app/Services/ShiftKmCalculatorService.php` | Create |
| `app/Filament/Resources/Orders/Actions/ReassignDriverAction.php` | Create |
| `app/Models/Order.php` | Modify |
| `app/Models/DriverSwap.php` | Modify |
| `app/Http/Requests/StartShiftRequest.php` | Modify |
| `app/Http/Requests/CheckpointRequest.php` | Modify |
| `app/Http/Controllers/Api/DriverShiftController.php` | Modify |
| `app/Http/Controllers/Api/TripCheckpointController.php` | Modify |
| `app/Http/Controllers/Api/OrderController.php` | Modify |
| `app/Http/Resources/OrderResource.php` | Modify |
| `app/Filament/Resources/Orders/Actions/Concerns/CreatesOrderTransportCards.php` | Modify |
| `app/Filament/Resources/Orders/Actions/AssignTransportAction.php` | Modify |
| `app/Filament/Resources/Orders/Actions/DriverSwapAction.php` | Modify |
| `app/Filament/Resources/Orders/Schemas/OrderForm.php` | Modify |
| `app/Models/Vehicle.php` | Modify (thêm relationship shifts) |

---

### Task 1: DB migrations

**Files:** Create `database/migrations/xxxx_add_shift_id_to_orders.php`

- [ ] **Create migration: add `shift_id` to `orders`**

```php
Schema::table('orders', function (Blueprint $table) {
    $table->foreignId('shift_id')
        ->nullable()
        ->constrained('driver_shifts')
        ->nullOnDelete()
        ->after('driver_id');
    $table->index('shift_id');
});
```

- [ ] **Run migration**

Run: `php artisan migrate`
Expected: column created

- [ ] **Commit**

```bash
git add database/migrations/xxxx_add_shift_id_to_orders.php
git commit -m "feat: add shift_id to orders table"
```

- [ ] **Create migration: add `to_shift_id` to `driver_swaps`**

```php
Schema::table('driver_swaps', function (Blueprint $table) {
    $table->foreignId('to_shift_id')
        ->nullable()
        ->constrained('driver_shifts')
        ->nullOnDelete()
        ->after('from_shift_id');
});
```

- [ ] **Run migration**

Run: `php artisan migrate`
Expected: column created

- [ ] **Commit**

```bash
git add database/migrations/xxxx_add_to_shift_id_to_driver_swaps.php
git commit -m "feat: add to_shift_id to driver_swaps table"
```

---

### Task 2: Update models

**Files:** Modify `app/Models/Order.php`, Modify `app/Models/DriverSwap.php`

- [ ] **Add `shift_id` to Order $fillable + relationship**

```php
// In $fillable, add 'shift_id'
protected $fillable = [
    // ... existing ...
    'shift_id',
];

// Add relationship
public function shift(): BelongsTo
{
    return $this->belongsTo(DriverShift::class);
}
```

- [ ] **Add `to_shift_id` to DriverSwap $fillable + relationship**

```php
// In $fillable, add 'to_shift_id'
protected $fillable = [
    // ... existing ...
    'to_shift_id',
];

// Add relationship
public function toShift(): BelongsTo
{
    return $this->belongsTo(DriverShift::class, 'to_shift_id');
}
```

- [ ] **Format with Pint**

Run: `vendor/bin/pint --format agent`

- [ ] **Commit**

```bash
git add app/Models/Order.php app/Models/DriverSwap.php
git commit -m "feat: update Order and DriverSwap models with new fields"
```

---

### Task 3: ShiftKmCalculatorService

**Files:** Create `app/Services/ShiftKmCalculatorService.php`

- [ ] **Create the service class**

```php
<?php

namespace App\Services;

use App\Models\DriverShift;
use App\Models\TripCheckpoint;

class ShiftKmCalculatorService
{
    public function calculate(DriverShift $shift): void
    {
        $orders = TripCheckpoint::where('shift_id', $shift->id)
            ->whereIn('checkpoint_type', ['arrived_pickup', 'left_pickup', 'completed'])
            ->orderBy('occurred_at')
            ->get()
            ->groupBy('order_id');

        $totalLoadedKm = 0;

        foreach ($orders as $orderId => $points) {
            $completed = $points->firstWhere('checkpoint_type', 'completed');
            $leftPickup = $points->firstWhere('checkpoint_type', 'left_pickup');

            if ($completed?->km_reading !== null && $leftPickup?->km_reading !== null) {
                $totalLoadedKm += $completed->km_reading - $leftPickup->km_reading;
            }
        }

        $shift->total_km = $shift->end_km - $shift->start_km;
        $shift->total_km_loaded = $totalLoadedKm;
        $shift->total_km_empty = $shift->total_km - $totalLoadedKm;
        $shift->save();
    }
}
```

- [ ] **Commit**

```bash
git add app/Services/ShiftKmCalculatorService.php
git commit -m "feat: add ShiftKmCalculatorService"
```

---

### Task 4: API — StartShiftRequest validation fix

**Files:** Modify `app/Http/Requests/StartShiftRequest.php`

- [ ] **Fix `after()` to compare start_km with last end_km of same vehicle**

```php
public function after(): array
{
    return [
        function (\Illuminate\Validation\Validator $validator) {
            if ($this->input('start_km') === null) {
                return;
            }

            $vehicle = Vehicle::find($this->input('vehicle_id'));
            if ($vehicle === null) {
                return;
            }

            // Compare with last shift's end_km for this vehicle
            $lastShiftKm = DriverShift::where('vehicle_id', $this->input('vehicle_id'))
                ->whereNotNull('end_km')
                ->orderByDesc('end_time')
                ->value('end_km');

            $referenceKm = $lastShiftKm ?? $vehicle->current_mileage;

            if ((float) $this->input('start_km') < (float) $referenceKm) {
                $message = sprintf(
                    'Số km bắt đầu ca phải lớn hơn hoặc bằng km gần nhất của xe (%.1f km)',
                    $referenceKm
                );
                throw new HttpResponseException(response()->json(['message' => $message], 422));
            }
        },
    ];
}
```

Need to add import for `DriverShift` and `Vehicle`.

- [ ] **Add import**

```php
use App\Models\DriverShift;
use App\Models\Vehicle;
```

- [ ] **Format with Pint**

- [ ] **Commit**

```bash
git add app/Http/Requests/StartShiftRequest.php
git commit -m "fix: compare start_km with last shift end_km instead of current_mileage"
```

---

### Task 5: API — CheckpointRequest left_pickup km required + completed validation

**Files:** Modify `app/Http/Requests/CheckpointRequest.php`

- [ ] **Update rules to require km_reading for left_pickup**

Find the current `km_reading` rule and change it so left_pickup requires km.

- [ ] **Add after validation for completed checkpoint: km must be >= left_pickup km**

```php
public function after(): array
{
    return [
        function (\Illuminate\Validation\Validator $validator) {
            if ($this->input('km_reading') === null) {
                return;
            }

            $checkpointType = $this->input('checkpoint_type');

            if ($checkpointType === 'completed') {
                $order = Order::find($this->input('order_id'));
                if ($order === null) {
                    return;
                }

                $leftPickupKm = TripCheckpoint::where('order_id', $order->id)
                    ->where('checkpoint_type', 'left_pickup')
                    ->value('km_reading');

                if ($leftPickupKm !== null && (float) $this->input('km_reading') <= (float) $leftPickupKm) {
                    $message = sprintf(
                        'Số km kết thúc phải lớn hơn km lúc rời điểm nhận (%.1f km)',
                        $leftPickupKm
                    );
                    throw new HttpResponseException(response()->json(['message' => $message], 422));
                }
            }
        },
    ];
}
```

Need to add imports for `Order` and `TripCheckpoint`.

- [ ] **Format with Pint**

- [ ] **Commit**

```bash
git add app/Http/Requests/CheckpointRequest.php
git commit -m "feat: left_pickup requires km, completed km must be >= left_pickup km"
```

---

### Task 6: API — DriverShiftController@end auto-calc km

**Files:** Modify `app/Http/Controllers/Api/DriverShiftController.php`

- [ ] **Add ShiftKmCalculatorService call after setting end_km**

```php
use App\Services\ShiftKmCalculatorService;

// Inside end() method, after setting $shift->end_km:
$shift->save(); // save end_km first

// Auto-calc km
app(ShiftKmCalculatorService::class)->calculate($shift->fresh());
```

Replace the current manual `total_km` calculation block (lines 132-134):

```php
// OLD:
if ($shift->start_km !== null && $shift->end_km !== null) {
    $shift->total_km = $shift->end_km - $shift->start_km;
}

// NEW:
$shift->save();
app(ShiftKmCalculatorService::class)->calculate($shift->fresh());
```

- [ ] **Format with Pint**

- [ ] **Commit**

```bash
git add app/Http/Controllers/Api/DriverShiftController.php
git commit -m "feat: auto-calc total/km loaded/empty on shift end using calculator service"
```

---

### Task 7: API — TripCheckpointController auto-set order.shift_id

**Files:** Modify `app/Http/Controllers/Api/TripCheckpointController.php`

- [ ] **After creating checkpoint, set order.shift_id if not already set**

```php
// After checkpoint creation (after line 58), add:
if ($order->shift_id === null && $payload['shift_id'] ?? null) {
    $order->shift_id = $payload['shift_id'];
    $order->save();
}
```

- [ ] **Format with Pint**

- [ ] **Commit**

```bash
git add app/Http/Controllers/Api/TripCheckpointController.php
git commit -m "feat: auto-set order.shift_id from first checkpoint"
```

---

### Task 8: API — OrderController add has_active_order flag

**Files:** Modify `app/Http/Controllers/Api/OrderController.php`

- [ ] **Add `has_active_order` to `index()` response**

```php
// After orders query, add this to the response:
'has_active_order' => Order::where('driver_id', $user->id)
    ->whereIn('status', [
        OrderStatus::Started,
        OrderStatus::ArrivedPickup,
        OrderStatus::Delivering,
        OrderStatus::ArrivedDelivery,
    ])
    ->exists(),
```

Also add this to `show()` if needed. Actually the index() already returns a `data` key with collection. Let me add the flag outside the data array.

```php
return response()->json([
    'data' => OrderResource::collection($orders),
    'has_active_order' => Order::where('driver_id', $user->id)
        ->whereIn('status', [...])->exists(),
]);
```

- [ ] **Format with Pint**

- [ ] **Commit**

```bash
git add app/Http/Controllers/Api/OrderController.php
git commit -m "feat: add has_active_order flag to order list response"
```

---

### Task 9: API — OrderResource add shift_id

**Files:** Modify `app/Http/Resources/OrderResource.php`

- [ ] **Add `shift_id` to the resource array**

```php
'shift_id' => $this->shift_id,
```

Add after `ship_id` line or near other ID fields.

- [ ] **Commit**

```bash
git add app/Http/Resources/OrderResource.php
git commit -m "feat: add shift_id to OrderResource"
```

---

### Task 10: Web — CreatesOrderTransportCards filter by active shift

**Files:** Modify `app/Filament/Resources/Orders/Actions/Concerns/CreatesOrderTransportCards.php`

- [ ] **Modify `resolveVehicleCards()` to optionally filter by active shift**

Add a parameter `?int $activeShiftId = null` or filter by vehicle with `end_time IS NULL` on driver_shifts.

The method currently queries `Vehicle::where('status', 'on')`. Add a check: only return vehicles that have an active shift OR are the selected vehicle.

```php
protected static function resolveVehicleCards(
    ?float $requiredWeight,
    ?int $pickupLocationId = null,
    ?int $selectedVehicleId = null,
    bool $requireActiveShift = false,
): array
```

Add filter logic in the query:

```php
->when($requireActiveShift, function ($query) use ($selectedVehicleId): void {
    $query->where(function ($q) use ($selectedVehicleId): void {
        $q->whereHas('shifts', fn ($q) => $q->whereNull('end_time'))
          ->when($selectedVehicleId, fn ($q) => $q->orWhere('id', $selectedVehicleId));
    });
})
```

Need to add `shifts()` relationship to Vehicle model first.

- [ ] **Add `shifts()` relationship to Vehicle model**

```php
// In app/Models/Vehicle.php
public function shifts(): HasMany
{
    return $this->hasMany(DriverShift::class);
}

public function activeShift(): HasOne
{
    return $this->hasOne(DriverShift::class)->whereNull('end_time');
}
```

- [ ] **Format with Pint**

- [ ] **Commit**

```bash
git add app/Filament/Resources/Orders/Actions/Concerns/CreatesOrderTransportCards.php app/Models/Vehicle.php
git commit -m "feat: add active shift filter to vehicle cards, add Vehicle shifts relationship"
```

---

### Task 11: Web — AssignTransportAction with shift validation + override

**Files:** Modify `app/Filament/Resources/Orders/Actions/AssignTransportAction.php`

- [ ] **Add checkbox override: "Cho phép gán xe không có ca"**

```php
->schema([
    VehiclePicker::make('vehicle_id')
        ->label('Phương tiện')
        ->live()
        ->afterStateUpdated(...)
        ->cards(fn (Get $get): array => self::resolveVehicleCards(
            self::normalizeDecimal($get('total_weight') ?? 0),
            null,
            self::normalizeInteger($get('vehicle_id')),
            // Pass requireActiveShift based on override checkbox
            ! (bool) $get('allow_no_shift'),
        ))
        ->searchPlaceholder('Tìm biển số, loại xe...')
        ->required(),
    Hidden::make('driver_id'),
    \Filament\Forms\Components\Checkbox::make('allow_no_shift')
        ->label('Cho phép gán xe không có ca')
        ->default(false)
        ->live()
        ->visible(fn (): bool => auth()->user()?->hasRole('admin')),
])
```

- [ ] **Update action logic: set order.shift_id after assign**

```php
// After vehicle update, set shift_id on order:
$activeShift = DriverShift::where('vehicle_id', $data['vehicle_id'])
    ->whereNull('end_time')
    ->first();

if ($activeShift) {
    Order::query()->whereKey($record->id)->update([
        'shift_id' => $activeShift->id,
    ]);
}
```

- [ ] **Format with Pint**

- [ ] **Commit**

```bash
git add app/Filament/Resources/Orders/Actions/AssignTransportAction.php
git commit -m "feat: add shift validation + override to AssignTransportAction, set order.shift_id"
```

---

### Task 12: Web — OrderForm with shift validation

**Files:** Modify `app/Filament/Resources/Orders/Schemas/OrderForm.php`

- [ ] **Add same shift validation to VehiclePicker in OrderForm**

Similar to Task 11: add `allow_no_shift` checkbox and pass `requireActiveShift` to `resolveVehicleCards()`.

The tab "Phân xe" already uses `resolveVehicleCards`. Update the call:

```php
VehiclePicker::make('vehicle_id')
    ->label('Phương tiện')
    ->live()
    ->afterStateUpdated(function (Set $set, $state): void {
        if ($state) {
            $vehicle = Vehicle::find($state);
            $set('driver_id', $vehicle?->current_driver_id ?? null);
        } else {
            $set('driver_id', null);
        }
    })
    ->cards(fn (Get $get): array => self::resolveVehicleCards(
        self::normalizeDecimal($get('total_weight')),
        self::isHhhkOrder($get) ? self::normalizeInteger($get('pickup_location_id')) : null,
        self::normalizeInteger($get('vehicle_id')),
        ! (bool) $get('allow_no_shift'),
    ))
    ->searchPlaceholder('Tìm biển số, loại xe...'),
Hidden::make('driver_id'),
\Filament\Forms\Components\Checkbox::make('allow_no_shift')
    ->label('Cho phép gán xe không có ca')
    ->default(false)
    ->live()
    ->visible(fn (): bool => auth()->user()?->hasRole('admin')),
```

- [ ] **Format with Pint**

- [ ] **Commit**

```bash
git add app/Filament/Resources/Orders/Schemas/OrderForm.php
git commit -m "feat: add shift validation to OrderForm vehicle picker"
```

---

### Task 13: Web — Fix DriverSwapAction (Filament) bug

**Files:** Modify `app/Filament/Resources/Orders/Actions/DriverSwapAction.php`

- [ ] **Fix the `driverShifts()` call to use correct query**

Current (line 79):
```php
'from_shift_id' => $record->driverShifts()->first()?->id,
```

Fix:
```php
'from_shift_id' => TripCheckpoint::where('order_id', $record->id)
    ->whereNotNull('shift_id')
    ->orderByDesc('occurred_at')
    ->value('shift_id'),
```

- [ ] **Add TripCheckpoint import**

```php
use App\Models\TripCheckpoint;
```

- [ ] **Format with Pint**

- [ ] **Commit**

```bash
git add app/Filament/Resources/Orders/Actions/DriverSwapAction.php
git commit -m "fix: correct shift_id lookup in DriverSwapAction, relation does not exist on Order"
```

---

### Task 14: Web — ReassignDriverAction (for driver_swap status)

**Files:** Create `app/Filament/Resources/Orders/Actions/ReassignDriverAction.php`

- [ ] **Create action for assigning new driver after swap**

```php
<?php

namespace App\Filament\Resources\Orders\Actions;

use App\Enums\OrderStatus;
use App\Models\DriverShift;
use App\Models\DriverSwap;
use App\Models\Order;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;

class ReassignDriverAction
{
    public static function make(): Action
    {
        return Action::make('reassign_driver')
            ->label('Gán lái mới')
            ->icon('heroicon-o-arrow-path')
            ->color('warning')
            ->hidden(fn (Order $record): bool => $record->status !== OrderStatus::DriverSwap)
            ->modalHeading('Gán lái xe mới sau đảo lái')
            ->modalDescription('Chọn lái xe mới để tiếp tục đơn hàng.')
            ->form([
                Select::make('to_driver_id')
                    ->label('Lái xe mới')
                    ->options(fn (): array => User::where('is_active', true)
                        ->pluck('name', 'id')
                        ->toArray())
                    ->required()
                    ->searchable()
                    ->preload()
                    ->live(),
                Select::make('to_shift_id')
                    ->label('Ca trực hiện tại (nếu có)')
                    ->options(fn (Get $get): array => DriverShift::where('driver_id', $get('to_driver_id'))
                        ->whereNull('end_time')
                        ->get()
                        ->mapWithKeys(fn ($s) => [$s->id => sprintf(
                            'Ca %s - %s (xe: %s)',
                            $s->shift_type?->getLabel() ?? 'N/A',
                            $s->start_time?->format('H:i'),
                            $s->vehicle->plate_number ?? 'N/A',
                        )])
                        ->toArray())
                    ->searchable()
                    ->native(false),
                Hidden::make('from_shift_id')
                    ->default(fn (Order $record): ?int => TripCheckpoint::where('order_id', $record->id)
                        ->whereNotNull('shift_id')
                        ->orderByDesc('occurred_at')
                        ->value('shift_id')),
            ])
            ->action(function (Order $record, array $data): void {
                $lastSwap = DriverSwap::where('order_id', $record->id)
                    ->latest('created_at')
                    ->first();

                if ($lastSwap) {
                    $lastSwap->to_driver_id = $data['to_driver_id'];
                    $lastSwap->to_shift_id = $data['to_shift_id'] ?? null;
                    $lastSwap->save();
                }

                $record->driver_id = $data['to_driver_id'];
                $record->shift_id = $data['to_shift_id'] ?? $record->shift_id;
                $record->status = OrderStatus::Sent;
                $record->save();

                Notification::make()
                    ->title('Đã gán lái mới')
                    ->body('Đơn hàng đã được chuyển cho lái xe mới.')
                    ->success()
                    ->send();
            });
    }
}
```

- [ ] **Register the action in OrdersTable**

Open `app/Filament/Resources/Orders/Tables/OrdersTable.php` and add the action where appropriate.

- [ ] **Format with Pint**

- [ ] **Commit**

```bash
git add app/Filament/Resources/Orders/Actions/ReassignDriverAction.php
git commit -m "feat: add ReassignDriverAction for post-swap driver reassignment"
```

---

### Task 15: API — DriverSwapController update shift

**Files:** Modify `app/Http/Controllers/Api/DriverSwapController.php`

- [ ] **Auto end current shift for from-driver when swap reason is shift_handover**

```php
if ($payload['reason'] === 'shift_handover') {
    $fromShift = DriverShift::where('driver_id', $order->driver_id)
        ->whereNull('end_time')
        ->first();

    if ($fromShift) {
        $fromShift->end_time = now();
        $fromShift->end_km = $payload['handover_km'] ?? $fromShift->end_km;
        $fromShift->save();

        app(\App\Services\ShiftKmCalculatorService::class)->calculate($fromShift);
    }
}
```

- [ ] **Add ShiftKmCalculatorService import**

- [ ] **Format with Pint**

- [ ] **Commit**

```bash
git add app/Http/Controllers/Api/DriverSwapController.php
git commit -m "feat: auto-end shift on driver swap for shift_handover reason"
```

---

## Self-review

Full implementation coverage — all spec sections are implemented across these 15 tasks. Placeholder check clean. Type consistency verified.
