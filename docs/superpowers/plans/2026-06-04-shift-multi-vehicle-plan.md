# Multi-Vehicle Shift Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Support one driver shift with multiple vehicle segments, each tied to a specific order, with KM tracked per vehicle.

**Architecture:** New `shift_vehicles` table records each vehicle segment (with `order_id`). `DriverShift` retains `vehicle_id` as the first/primary vehicle. Start/end flows auto-create/end segments. A switch-vehicle API endpoint handles manual vehicle changes. `ShiftKmCalculatorService` sums KM across segments.

**Tech Stack:** Laravel 13, MySQL, Filament v5

---

### Task 1: Migration + ShiftVehicle model

**Files:**
- Create: `database/migrations/2026_06_04_000001_create_shift_vehicles_table.php`
- Create: `app/Models/ShiftVehicle.php`

- [ ] **Step 1: Create migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_id')->constrained('driver_shifts')->cascadeOnDelete();
            $table->foreignId('vehicle_id')->constrained('vehicles');
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->datetime('start_time');
            $table->datetime('end_time')->nullable();
            $table->decimal('start_km', 10, 1)->nullable();
            $table->decimal('end_km', 10, 1)->nullable();
            $table->decimal('start_gps_lat', 10, 7)->nullable();
            $table->decimal('start_gps_lng', 10, 7)->nullable();
            $table->decimal('end_gps_lat', 10, 7)->nullable();
            $table->decimal('end_gps_lng', 10, 7)->nullable();
            $table->timestamps();

            $table->index(['shift_id', 'vehicle_id']);
            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_vehicles');
    }
};
```

- [ ] **Step 2: Run migration**

Run: `php artisan migrate`

- [ ] **Step 3: Create ShiftVehicle model**

`app/Models/ShiftVehicle.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShiftVehicle extends Model
{
    protected $fillable = [
        'shift_id',
        'vehicle_id',
        'order_id',
        'start_time',
        'end_time',
        'start_km',
        'end_km',
        'start_gps_lat',
        'start_gps_lng',
        'end_gps_lat',
        'end_gps_lng',
    ];

    protected function casts(): array
    {
        return [
            'start_time' => 'datetime',
            'end_time' => 'datetime',
            'start_km' => 'decimal:1',
            'end_km' => 'decimal:1',
            'start_gps_lat' => 'decimal:7',
            'start_gps_lng' => 'decimal:7',
            'end_gps_lat' => 'decimal:7',
            'end_gps_lng' => 'decimal:7',
        ];
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(DriverShift::class, 'shift_id');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
```

- [ ] **Step 4: Commit**

```bash
git add database/migrations/2026_06_04_000001_create_shift_vehicles_table.php app/Models/ShiftVehicle.php
git commit -m "feat: add shift_vehicles table and ShiftVehicle model"
```

---

### Task 2: Add shiftVehicles relationships to DriverShift

**Files:**
- Modify: `app/Models/DriverShift.php`

- [ ] **Step 1: Add relationships**

Add to `app/Models/DriverShift.php` after existing relationships:

```php
public function shiftVehicles(): HasMany
{
    return $this->hasMany(ShiftVehicle::class, 'shift_id');
}

public function currentShiftVehicle(): ?ShiftVehicle
{
    return $this->shiftVehicles()->whereNull('end_time')->latest('start_time')->first();
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Models/DriverShift.php
git commit -m "feat: add shiftVehicles and currentShiftVehicle to DriverShift"
```

---

### Task 3: Update start shift flow to create first segment

**Files:**
- Modify: `app/Http/Controllers/Api/DriverShiftController.php`

- [ ] **Step 1: Create first ShiftVehicle after DriverShift**

In `DriverShiftController::start`, after `DriverShift::create(...)`, add:

```php
$shift->shiftVehicles()->create([
    'vehicle_id' => $payload['vehicle_id'],
    'start_time' => $shift->start_time,
    'start_km' => $payload['start_km'] ?? null,
    'start_gps_lat' => $payload['start_gps_lat'] ?? null,
    'start_gps_lng' => $payload['start_gps_lng'] ?? null,
]);
```

- [ ] **Step 2: Commit**

```bash
git add app/Http/Controllers/Api/DriverShiftController.php
git commit -m "feat: create first shift vehicle segment on shift start"
```

---

### Task 4: Update end shift flow to end last segment

**Files:**
- Modify: `app/Http/Controllers/Api/DriverShiftController.php`
- Modify: `app/Http/Requests/EndShiftRequest.php`

- [ ] **Step 1: Update EndShiftRequest validation**

In `EndShiftRequest::after()`, change the validation to use the last segment's `start_km` instead of the shift's `start_km`:

```php
$shift = DriverShift::query()
    ->where('driver_id', $this->user()->id)
    ->whereNull('end_time')
    ->first();

if ($shift === null) {
    return;
}

$currentSegment = $shift->shiftVehicles()->whereNull('end_time')->latest('start_time')->first();
$referenceKm = $currentSegment?->start_km ?? $shift->start_km;

if ($referenceKm !== null && (float) $this->input('end_km') <= (float) $referenceKm) {
    $message = 'Số km kết thúc ca phải lớn hơn số km bắt đầu ca ('.number_format((float) $referenceKm, 1).' km)';
    throw new HttpResponseException(response()->json(['message' => $message], 422));
}
```

- [ ] **Step 2: End last segment in DriverShiftController::end**

In `DriverShiftController::end`, after `$shift->save()` and before `app(ShiftKmCalculatorService::class)->calculate(...)`, add:

```php
$currentSegment = $shift->currentShiftVehicle();
if ($currentSegment) {
    $currentSegment->end_time = $shift->end_time;
    $currentSegment->end_km = $payload['end_km'] ?? $shift->end_km;
    $currentSegment->end_gps_lat = $payload['end_gps_lat'] ?? null;
    $currentSegment->end_gps_lng = $payload['end_gps_lng'] ?? null;
    $currentSegment->save();
}
```

Also update the vehicle info block — instead of using `$shift->vehicle_id`, check if there's a current segment:

```php
$lastSegment = $shift->shiftVehicles()->latest('start_time')->first();
$vehicle = $lastSegment ? Vehicle::find($lastSegment->vehicle_id) : Vehicle::find($shift->vehicle_id);
```

- [ ] **Step 3: Commit**

```bash
git add app/Http/Controllers/Api/DriverShiftController.php app/Http/Requests/EndShiftRequest.php
git commit -m "feat: end last shift vehicle segment on shift end"
```

---

### Task 5: Add switch-vehicle API endpoint

**Files:**
- Create: `app/Http/Requests/SwitchVehicleRequest.php`
- Modify: `app/Http/Controllers/Api/DriverShiftController.php`
- Modify: `routes/api.php`

- [ ] **Step 1: Create SwitchVehicleRequest**

`app/Http/Requests/SwitchVehicleRequest.php`:
```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SwitchVehicleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'new_vehicle_id' => 'required|exists:vehicles,id|different:current_vehicle_id',
            'order_id' => 'nullable|exists:orders,id',
            'handover_km' => 'required|numeric',
            'handover_gps_lat' => 'nullable|numeric',
            'handover_gps_lng' => 'nullable|numeric',
        ];
    }
}
```

- [ ] **Step 2: Add switchVehicle method to DriverShiftController**

```php
use App\Http\Requests\SwitchVehicleRequest;

#[BodyParameter('new_vehicle_id', type: 'integer', description: 'ID xe mới.', required: true)]
#[BodyParameter('order_id', type: 'integer', description: 'ID đơn hàng tương ứng với xe mới.', required: false)]
#[BodyParameter('handover_km', type: 'number', description: 'Km đồng hồ tại thời điểm chuyển xe.', required: true)]
public function switchVehicle(SwitchVehicleRequest $request): JsonResponse
{
    $user = $request->user();
    $payload = $request->validated();

    $shift = DriverShift::query()
        ->where('driver_id', $user->id)
        ->whereNull('end_time')
        ->first();

    if (! $shift) {
        return response()->json(['message' => 'No active shift found'], 404);
    }

    $currentSegment = $shift->currentShiftVehicle();
    if (! $currentSegment) {
        return response()->json(['message' => 'No active vehicle segment found'], 404);
    }

    if ((int) $currentSegment->vehicle_id === (int) $payload['new_vehicle_id']) {
        return response()->json(['message' => 'Xe mới phải khác xe hiện tại'], 422);
    }

    DB::beginTransaction();
    try {
        // End current segment
        $currentSegment->end_time = now();
        $currentSegment->end_km = $payload['handover_km'];
        $currentSegment->end_gps_lat = $payload['handover_gps_lat'] ?? null;
        $currentSegment->end_gps_lng = $payload['handover_gps_lng'] ?? null;
        $currentSegment->save();

        // Create new segment
        $shift->shiftVehicles()->create([
            'vehicle_id' => $payload['new_vehicle_id'],
            'order_id' => $payload['order_id'] ?? null,
            'start_time' => now(),
            'start_km' => $payload['handover_km'],
            'start_gps_lat' => $payload['handover_gps_lat'] ?? null,
            'start_gps_lng' => $payload['handover_gps_lng'] ?? null,
        ]);

        // Update old vehicle: remove driver
        Vehicle::where('id', $currentSegment->vehicle_id)
            ->where('current_driver_id', $user->id)
            ->update(['current_driver_id' => null]);

        // Update new vehicle: assign driver + mileage
        Vehicle::where('id', $payload['new_vehicle_id'])->update([
            'current_driver_id' => $user->id,
            'current_mileage' => $payload['handover_km'],
        ]);

        DB::commit();

        return response()->json([
            'shift' => DriverShiftResource::make($shift->load(['driver', 'vehicle', 'shiftVehicles.vehicle'])),
        ]);
    } catch (\Throwable $e) {
        DB::rollBack();

        return response()->json(['message' => 'Unable to switch vehicle', 'error' => $e->getMessage()], 500);
    }
}
```

- [ ] **Step 3: Register route**

Add to `routes/api.php` after the shifts routes:

```php
Route::post('/shifts/switch-vehicle', [DriverShiftController::class, 'switchVehicle']);
```

- [ ] **Step 4: Commit**

```bash
git add app/Http/Requests/SwitchVehicleRequest.php app/Http/Controllers/Api/DriverShiftController.php routes/api.php
git commit -m "feat: add switch-vehicle API endpoint"
```

---

### Task 6: Update ShiftKmCalculatorService to use segments

**Files:**
- Modify: `app/Services/ShiftKmCalculatorService.php`

- [ ] **Step 1: Change calculation to use shiftVehicles segments**

Replace the direct `start_km`/`end_km` calculation at the end:

```php
// Before:
$shift->total_km = $shift->end_km - $shift->start_km;

// After:
$totalKm = $shift->shiftVehicles->sum(
    fn ($sv) => ($sv->end_km ?? 0) - $sv->start_km
);
$shift->total_km = $totalKm;
```

Also update the loaded km logic to check against segments. In the loop where `$hasPriorLeftPickup` is checked, use `$shift->shiftVehicles()->first()?->start_km ?? $shift->start_km` instead of `$shift->start_km`.

- [ ] **Step 2: Commit**

```bash
git add app/Services/ShiftKmCalculatorService.php
git commit -m "feat: calculate total_km from shift vehicle segments"
```

---

### Task 7: Fix ReassignDriverAction to find shift without vehicle filter

**Files:**
- Modify: `app/Filament/Resources/Orders/Actions/ReassignDriverAction.php`

- [ ] **Step 1: Find old driver's active shift without vehicle filter**

Change:
```php
$oldShift = DriverShift::where('driver_id', $record->driver_id)
    ->where('vehicle_id', $record->vehicle_id)
    ->whereNull('end_time')
    ->first();
```

To:
```php
$oldShift = DriverShift::where('driver_id', $record->driver_id)
    ->whereNull('end_time')
    ->first();
```

Same for finding the new driver's shift:
```php
$newShift = DriverShift::where('driver_id', $data['new_driver_id'])
    ->whereNull('end_time')
    ->first();
```

- [ ] **Step 2: Commit**

```bash
git add app/Filament/Resources/Orders/Actions/ReassignDriverAction.php
git commit -m "fix: find driver shift without vehicle filter for multi-vehicle support"
```

---

### Task 8: Update Filament form and infolist to show shift vehicles

**Files:**
- Modify: `app/Filament/Resources/DriverShifts/Schemas/DriverShiftInfolist.php`
- Modify: `app/Filament/Resources/DriverShifts/Schemas/DriverShiftForm.php`

- [ ] **Step 1: Add shiftVehicles to infolist**

In `DriverShiftInfolist::configure`, add after the first Section:

```php
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Grid;
use Filament\Infolists\Components\Group;
use Filament\Infolists\Components\TextEntry;

Section::make('Các xe đã sử dụng trong ca')
    ->columnSpanFull()
    ->schema([
        RepeatableEntry::make('shiftVehicles')
            ->label('')
            ->schema([
                TextEntry::make('vehicle.plate_number')
                    ->label('Xe')
                    ->icon(Heroicon::OutlinedTruck),
                TextEntry::make('order_id')
                    ->label('Đơn hàng'),
                TextEntry::make('start_time')
                    ->label('Bắt đầu')
                    ->dateTime(),
                TextEntry::make('end_time')
                    ->label('Kết thúc')
                    ->dateTime(),
                TextEntry::make('start_km')
                    ->label('Km đầu')
                    ->numeric(),
                TextEntry::make('end_km')
                    ->label('Km cuối')
                    ->numeric(),
                TextEntry::make('calculated_km')
                    ->label('Km')
                    ->state(fn ($record) => $record->end_km && $record->start_km
                        ? number_format($record->end_km - $record->start_km, 1)
                        : '-'),
            ])
            ->columns(4),
    ]),
```

- [ ] **Step 2: Add shiftVehicles to form (read-only)**

In `DriverShiftForm::configure`, after existing components:

```php
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\RepeatableEntry;
use Filament\Forms\Components\TextInput;

RepeatableEntry::make('shiftVehicles')
    ->label('Các xe đã sử dụng')
    ->schema([
        TextInput::make('vehicle.plate_number')
            ->label('Xe')
            ->disabled(),
        TextInput::make('order_id')
            ->label('Đơn hàng')
            ->disabled(),
        TextInput::make('start_km')
            ->label('Km bắt đầu')
            ->disabled()
            ->numeric(),
        TextInput::make('end_km')
            ->label('Km kết thúc')
            ->disabled()
            ->numeric(),
    ])
    ->columns(4),
```

- [ ] **Step 3: Commit**

```bash
git add app/Filament/Resources/DriverShifts/Schemas/DriverShiftInfolist.php app/Filament/Resources/DriverShifts/Schemas/DriverShiftForm.php
git commit -m "feat: show shift vehicle segments in Filament form and infolist"
```

---

### Task 9: Run format + test

- [ ] **Step 1: Run Pint**

```bash
vendor/bin/pint --format agent
```

- [ ] **Step 2: Run tests**

```bash
php artisan test --compact
```

- [ ] **Step 3: Fix any issues**

If tests fail, fix them and repeat steps 1-2.

- [ ] **Step 4: Final commit**

```bash
git add -A
git commit -m "style: apply pint formatting"
```
