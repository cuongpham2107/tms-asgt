# Nối driver_swaps với mốc bàn giao — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Km của từng tài xế trong một chuyến có nhiều lần đảo lái luôn đúng, kể cả sau khi điều hành sửa km/giờ của mốc bàn giao.

**Architecture:** Mốc `driver_swap` (trip_checkpoints) là nguồn duy nhất cho **km và giờ** bàn giao. Bảng `driver_swaps` giữ **ai giao → ai nhận, ca, lý do, người thao tác** và trỏ về mốc qua cột mới `checkpoint_id`. `TripKmSplitService::segments()` duyệt các "điểm bàn giao" theo giờ của mốc thay vì đoán ghép hai nguồn (`HandoverKmResolver`, `syncMissingSwapsFromCheckpoints`).

**Tech Stack:** Laravel 12 / PHP 8.4, Filament v4, Pest, SQLite (dev/test).

**Spec:** Thống nhất trong hội thoại ngày 2026-10-17: "mốc giữ km/giờ, driver_swaps giữ ai→ai và gắn checkpoint_id, gán lại không nhập km lần 2".

## Global Constraints

- Không thêm dependency mới.
- Migration tạo bằng `php artisan make:migration --no-interaction`.
- Chạy `vendor/bin/pint --dirty --format agent` sau khi sửa PHP.
- Không xoá test hiện có khi chưa được người dùng duyệt (`HandoverKmResolverTest` giữ nguyên trong plan này).
- Km tài xế cộng lại phải bằng km chuyến (bất biến, được test kiểm tra).
- Luồng thật đang chạy: `TripController::complete` (bàn giao chuyến chưa xong), `EndHandler`, `EndShiftAction` tạo **mốc** với `driver_id` = người giao; `DriverSwapAction` (web) tạo cả mốc + swap; `ReassignDriverAction` (web) tạo swap. `DriverSwapController` **không có route** → không sửa trong plan này.

## Bối cảnh cho người thực hiện

- Một lần bàn giao tạo **N mốc driver_swap** (một mốc cho mỗi đơn của chuyến, cùng `occurred_at`, cùng km) — xem `CheckpointFactory::createForAllOrders()` (`app/Services/Trip/CheckpointFactory.php:41`). Gọi đây là **nhóm mốc đồng hành**. `checkpoint_id` trỏ tới mốc có `id` nhỏ nhất trong nhóm.
- Bàn giao diễn ra 2 bước: (1) tài xế cũ bàn giao → chỉ có mốc (chưa biết ai nhận, gọi là "mốc chờ gán"); (2) điều hành gán tài xế mới → tạo `driver_swaps` gắn vào mốc chờ gán.
- `ReassignDriverAction` hiện đã ẩn ô `handover_km` và lấy mặc định từ `vehicle.current_mileage` (`ReassignDriverAction.php:88-97`) — đó là "km lần 2" cần bỏ.

## File Structure

| File | Trách nhiệm | Task |
|---|---|---|
| `tests/Feature/TripMultiDriverSwapKmTest.php` | Kịch bản 5 lần bàn giao (đã viết, 1 xanh / 3 đỏ) | 1 |
| `database/migrations/*_add_checkpoint_id_to_driver_swaps_table.php` | Cột `checkpoint_id` | 2 |
| `app/Models/DriverSwap.php` | fillable + quan hệ `checkpoint()` | 2 |
| `app/Services/TripKmSplitService.php` | `segments()` dựa trên điểm bàn giao; bỏ `syncMissingSwapsFromCheckpoints()`; thêm `matchUnlinkedSwaps()` | 3 |
| `app/Services/ShiftKmCalculatorService.php` | bỏ lời gọi sync (dòng 18) | 3 |
| `app/Filament/Resources/Trips/Actions/ReassignDriverAction.php` | gắn swap vào mốc chờ gán, bỏ km lần 2 | 4 |
| `app/Filament/Resources/Trips/Actions/DriverSwapAction.php` | tạo mốc trước, swap gắn `checkpoint_id` | 5 |
| `app/Observers/TripCheckpointObserver.php` + `AppServiceProvider` | sửa 1 mốc bàn giao → cả nhóm đồng hành đổi theo | 6 |
| `app/Console/Commands/LinkDriverSwapsCommand.php` | gắn `checkpoint_id` cho dữ liệu cũ | 7 |
| `app/Http/Controllers/Api/DriverShiftController.php` | km bắt đầu ca lấy từ mốc của swap | 8 |

---

### Task 1: Test kịch bản 5 lần bàn giao (đã có)

**Files:**
- Test: `tests/Feature/TripMultiDriverSwapKmTest.php` (đã tạo)

Kịch bản: chuyến 1.000 → 1.300, bốc 1.010, giao xong 1.150; bàn giao A→B 1.050, B→C 1.100, C→A 1.200, A→D 1.230, D→E 1.260. Kỳ vọng A 80/40, B 50/50, C 100/50, D 30/0, E 40/0 (tổng / có hàng).

- [ ] **Step 1: Chạy để xác nhận trạng thái đỏ**

Run: `vendor/bin/pest tests/Feature/TripMultiDriverSwapKmTest.php`
Expected: 1 passed (`splits km per driver across five handovers`), 3 failed:
`correcting a handover checkpoint km…` (C ra 295, E ra 0), `handover km comes from the checkpoint…`, `shift km of each driver follows…`.

- [ ] **Step 2: Commit**

```bash
git add tests/Feature/TripMultiDriverSwapKmTest.php docs/superpowers/plans/2026-10-17-driver-swap-checkpoint-link.md
git commit -m "test: characterize per-driver km split across five driver handovers"
```

---

### Task 2: Cột `checkpoint_id` trên driver_swaps

**Files:**
- Create: `database/migrations/<timestamp>_add_checkpoint_id_to_driver_swaps_table.php`
- Modify: `app/Models/DriverSwap.php`
- Test: `tests/Feature/TripMultiDriverSwapKmTest.php`

**Interfaces:**
- Produces: `DriverSwap::checkpoint(): BelongsTo<TripCheckpoint>`; cột `driver_swaps.checkpoint_id` (nullable, `nullOnDelete`).

- [ ] **Step 1: Viết test đỏ** — thêm vào cuối `TripMultiDriverSwapKmTest.php`:

```php
test('swap record links to its handover checkpoint', function () {
    $swap = DriverSwap::query()
        ->where('trip_id', $this->trip->id)
        ->where('from_driver_id', $this->drivers['B']->id)
        ->first();

    expect($swap->checkpoint)->not->toBeNull()
        ->and($swap->checkpoint->is($this->handovers['B->C']))->toBeTrue();
});
```

- [ ] **Step 2: Chạy, xác nhận đỏ**

Run: `vendor/bin/pest tests/Feature/TripMultiDriverSwapKmTest.php --filter=links`
Expected: FAIL (`Call to undefined relationship [checkpoint]` hoặc tương đương).

- [ ] **Step 3: Tạo migration**

Run: `php artisan make:migration add_checkpoint_id_to_driver_swaps_table --table=driver_swaps --no-interaction`

```php
public function up(): void
{
    Schema::table('driver_swaps', function (Blueprint $table) {
        $table->foreignId('checkpoint_id')
            ->nullable()
            ->after('trip_id')
            ->constrained('trip_checkpoints')
            ->nullOnDelete()
            ->comment('Mốc driver_swap chứa km/giờ bàn giao');
    });
}

public function down(): void
{
    Schema::table('driver_swaps', function (Blueprint $table) {
        $table->dropConstrainedForeignId('checkpoint_id');
    });
}
```

- [ ] **Step 4: Sửa model** `app/Models/DriverSwap.php` — thêm `'checkpoint_id'` vào `$fillable` (sau `'trip_id'`) và quan hệ:

```php
public function checkpoint(): BelongsTo
{
    return $this->belongsTo(TripCheckpoint::class, 'checkpoint_id');
}
```

- [ ] **Step 5: Chạy migration + test**

Run: `php artisan migrate --no-interaction && vendor/bin/pest tests/Feature/TripMultiDriverSwapKmTest.php --filter=links`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add database/migrations app/Models/DriverSwap.php tests/Feature/TripMultiDriverSwapKmTest.php
git commit -m "feat(trips): link driver swaps to their handover checkpoint"
```

---

### Task 3: Chia km theo điểm bàn giao

**Files:**
- Modify: `app/Services/TripKmSplitService.php` (`segments()`, bỏ `syncMissingSwapsFromCheckpoints()`, thêm `handoverPoints()` + `matchUnlinkedSwaps()`)
- Modify: `app/Services/ShiftKmCalculatorService.php:17-18` (bỏ lời gọi sync)
- Test: `tests/Feature/TripMultiDriverSwapKmTest.php`, `tests/Feature/TripKmSplitServiceTest.php`, `tests/Feature/ShiftKmCalculatorTest.php`, `tests/Feature/HandoverKmResolverTest.php`, `tests/Feature/TripWebCompletionKmTest.php`

**Interfaces:**
- Consumes: `DriverSwap::checkpoint()` (Task 2).
- Produces:
  - `TripKmSplitService::segments(Trip): list<array{0: float, 1: float, 2: ?int, 3: ?int}>` — chữ ký giữ nguyên.
  - `TripKmSplitService::matchUnlinkedSwaps(Trip): array<int, int>` — map `driver_swap_id => checkpoint_id` cho các swap chưa gắn (dùng lại ở Task 7).

**Quy tắc tính:**
1. Nhóm các mốc `driver_swap` có km theo `occurred_at` (đến giây) → mỗi nhóm là 1 điểm bàn giao; km = km của mốc được swap trỏ tới, nếu không có thì mốc `id` nhỏ nhất.
2. Swap đã gắn → lấy `from/to driver/shift` từ swap. Swap chưa gắn → ghép tạm với nhóm mốc chưa gắn có cùng km (sai lệch ≤ 0,05), theo thứ tự thời gian (`matchUnlinkedSwaps`).
3. Nhóm mốc không có swap (mốc chờ gán, hoặc điều hành thêm trên form) → người giao = `driver_id` của mốc; người nhận = `driver_id` của mốc **không phải driver_swap** đầu tiên sau đó (dữ kiện, không đoán); không có thì `null` (km sau đó không thuộc ai).
4. Swap cũ không có mốc nào khớp → điểm bàn giao với km = `handover_km`, giờ = `created_at` (giữ tương thích dữ liệu cũ và `HandoverKmResolverTest`; nếu `handover_km` trống thì dùng `HandoverKmResolver::resolve($trip, $swap)` như hiện nay).
5. Sắp các điểm theo giờ rồi theo km; bỏ self-swap (cùng tài xế + cùng ca); cắt chuyến thành đoạn như hiện tại.

- [ ] **Step 1: Xác nhận test đỏ** (đã có từ Task 1)

Run: `vendor/bin/pest tests/Feature/TripMultiDriverSwapKmTest.php`
Expected: 3 failed như Task 1.

- [ ] **Step 2: Viết lại `segments()` và thêm 2 hàm private/public** trong `TripKmSplitService`:

```php
public static function segments(Trip $trip): array
{
    $points = self::handoverPoints($trip);

    if ($points === []) {
        return [];
    }

    $startKm = (float) ($trip->start_km ?? 0);
    $tripEndKm = self::effectiveEndKm($trip, $startKm);

    $firstCheckpoint = TripCheckpoint::where('trip_id', $trip->id)
        ->whereNotNull('driver_id')
        ->orderBy('occurred_at')
        ->orderBy('id')
        ->first();

    $activeDriverId = $points[0]['from_driver_id'] ?? $firstCheckpoint?->driver_id ?? $trip->driver_id;
    $activeShiftId = $points[0]['from_shift_id'] ?? $firstCheckpoint?->shift_id ?? $trip->shift_id;
    $prevKm = $startKm;
    $segments = [];

    foreach ($points as $point) {
        $segments[] = [$prevKm, $point['km'], $activeShiftId, $activeDriverId];
        $activeDriverId = $point['to_driver_id'];
        $activeShiftId = $point['to_shift_id'];
        $prevKm = $point['km'];
    }

    $segments[] = [$prevKm, $tripEndKm, $activeShiftId, $activeDriverId];

    return $segments;
}

/**
 * Các điểm bàn giao của chuyến, sắp theo giờ.
 *
 * @return list<array{km: float, at: Carbon, from_driver_id: ?int, to_driver_id: ?int, from_shift_id: ?int, to_shift_id: ?int}>
 */
private static function handoverPoints(Trip $trip): array
{
    $swaps = DriverSwap::where('trip_id', $trip->id)->orderBy('id')->get();
    $matched = self::matchUnlinkedSwaps($trip);

    $groups = TripCheckpoint::where('trip_id', $trip->id)
        ->where('checkpoint_type', CheckpointType::DriverSwap)
        ->whereNotNull('km_reading')
        ->orderBy('occurred_at')
        ->orderBy('id')
        ->get()
        ->groupBy(fn (TripCheckpoint $cp): string => $cp->occurred_at->format('Y-m-d H:i:s'));

    $points = [];
    $usedSwapIds = [];

    foreach ($groups as $group) {
        $ids = $group->pluck('id')->all();
        $swap = $swaps->first(fn (DriverSwap $s): bool => in_array($s->checkpoint_id ?? $matched[$s->id] ?? null, $ids, true));
        $anchor = $swap ? $group->firstWhere('id', $swap->checkpoint_id ?? $matched[$swap->id]) : $group->first();

        if ($swap) {
            $usedSwapIds[] = $swap->id;
        }

        $next = $swap ? null : TripCheckpoint::where('trip_id', $trip->id)
            ->where('checkpoint_type', '!=', CheckpointType::DriverSwap)
            ->where('occurred_at', '>', $anchor->occurred_at)
            ->whereNotNull('driver_id')
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->first();

        $points[] = [
            'km' => (float) $anchor->km_reading,
            'at' => $anchor->occurred_at,
            'from_driver_id' => $swap?->from_driver_id ?? $anchor->driver_id,
            'to_driver_id' => $swap ? $swap->to_driver_id : $next?->driver_id,
            'from_shift_id' => $swap?->from_shift_id ?? $anchor->shift_id,
            'to_shift_id' => $swap ? $swap->to_shift_id : $next?->shift_id,
        ];
    }

    foreach ($swaps->whereNotIn('id', $usedSwapIds) as $swap) {
        $km = (float) ($swap->handover_km ?: HandoverKmResolver::resolve($trip, $swap));

        if ($km <= 0) {
            continue;
        }

        $points[] = [
            'km' => $km,
            'at' => $swap->created_at ? Carbon::parse($swap->created_at) : now(),
            'from_driver_id' => $swap->from_driver_id,
            'to_driver_id' => $swap->to_driver_id,
            'from_shift_id' => $swap->from_shift_id,
            'to_shift_id' => $swap->to_shift_id,
        ];
    }

    $points = array_values(array_filter(
        $points,
        fn (array $p): bool => ! ($p['from_driver_id'] === $p['to_driver_id'] && $p['from_shift_id'] === $p['to_shift_id']),
    ));

    usort($points, fn (array $a, array $b): int => [$a['at'], $a['km']] <=> [$b['at'], $b['km']]);

    return $points;
}

/**
 * Ghép swap chưa có checkpoint_id với nhóm mốc driver_swap chưa gắn, cùng km, theo thứ tự thời gian.
 *
 * @return array<int, int> driver_swap_id => checkpoint_id
 */
public static function matchUnlinkedSwaps(Trip $trip): array
{
    $linkedIds = DriverSwap::where('trip_id', $trip->id)->whereNotNull('checkpoint_id')->pluck('checkpoint_id')->all();

    $freeAnchors = TripCheckpoint::where('trip_id', $trip->id)
        ->where('checkpoint_type', CheckpointType::DriverSwap)
        ->whereNotNull('km_reading')
        ->orderBy('occurred_at')
        ->orderBy('id')
        ->get()
        ->groupBy(fn (TripCheckpoint $cp): string => $cp->occurred_at->format('Y-m-d H:i:s'))
        ->reject(fn ($group): bool => $group->pluck('id')->intersect($linkedIds)->isNotEmpty())
        ->map(fn ($group): TripCheckpoint => $group->first())
        ->values();

    $matches = [];

    foreach (DriverSwap::where('trip_id', $trip->id)->whereNull('checkpoint_id')->orderBy('id')->get() as $swap) {
        $index = $freeAnchors->search(fn (TripCheckpoint $cp): bool => abs((float) $cp->km_reading - (float) $swap->handover_km) <= 0.05);

        if ($index === false) {
            continue;
        }

        $matches[$swap->id] = $freeAnchors[$index]->id;
        $freeAnchors->forget($index);
    }

    return $matches;
}
```

Thêm `use Carbon\Carbon;`. Xoá hàm `syncMissingSwapsFromCheckpoints()` và lời gọi của nó ở đầu `segments()` cũ.

- [ ] **Step 3: Bỏ lời gọi sync** trong `ShiftKmCalculatorService::calculateForTrip()` — xoá 2 dòng:

```php
// Auto-sync missing swaps if driver swap checkpoints exist
TripKmSplitService::syncMissingSwapsFromCheckpoints($trip);
```

`calculateForTrip()` còn gom `allDriverIds` từ `driverSwaps()` và `checkpoints()` — giữ nguyên; tài xế nhận suy ra từ mốc đã có trong `checkpoints()->pluck('driver_id')`.

- [ ] **Step 4: Chạy test mới**

Run: `vendor/bin/pest tests/Feature/TripMultiDriverSwapKmTest.php`
Expected: 5 passed.

- [ ] **Step 5: Chạy test km cũ**

Run: `vendor/bin/pest tests/Feature/TripKmSplitServiceTest.php tests/Feature/ShiftKmCalculatorTest.php tests/Feature/HandoverKmResolverTest.php tests/Feature/TripWebCompletionKmTest.php tests/Feature/TripStatsTest.php tests/Feature/OrderFullFlowTest.php`
Expected: all pass. `TripWebCompletionKmTest` Scenario 3 (mốc driver_swap không có bản ghi swap) phải ra A=50, B=100 qua quy tắc 3. Nếu test nào assert "đã tạo bản ghi DriverSwap" thì đổi assert sang kết quả km (không xoá test).

- [ ] **Step 6: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/TripKmSplitService.php app/Services/ShiftKmCalculatorService.php tests
git commit -m "fix(trips): split driver km by handover checkpoints instead of guessing swaps"
```

---

### Task 4: Gán lại tài xế không nhập km lần 2

**Files:**
- Modify: `app/Filament/Resources/Trips/Actions/ReassignDriverAction.php` (form dòng 88-97, action dòng ~190-260)
- Test: `tests/Feature/ReassignDriverHandoverTest.php` (mới)

**Interfaces:**
- Consumes: `DriverSwap::checkpoint()`, cột `checkpoint_id`.

**Hành vi mới:**
- Tìm "mốc chờ gán" = mốc `driver_swap` mới nhất của chuyến mà nhóm đồng hành chưa có swap nào trỏ tới.
- Có mốc chờ gán → tạo `DriverSwap` với `checkpoint_id` = mốc đó, `handover_km` = km của mốc; **không** ghi đè `vehicle.current_mileage`.
- Không có mốc chờ gán nhưng mốc bàn giao mới nhất đã có swap **và** tài xế nhận chưa nhập mốc nào sau đó (điều hành đổi người nhận) → cập nhật `to_driver_id`/`to_shift_id` của swap đó, không tạo swap mới.
- Không có mốc bàn giao nào → báo lỗi "Chuyến chưa có mốc bàn giao, hãy dùng chức năng Đảo lái" và dừng.
- Bỏ field `handover_km` khỏi form; thêm `Placeholder::make('handover_info')` hiển thị "Km bàn giao: {km} ({giờ})" lấy từ mốc.

- [ ] **Step 1: Viết test đỏ** `tests/Feature/ReassignDriverHandoverTest.php` (tạo bằng `php artisan make:test --pest ReassignDriverHandoverTest --no-interaction`), dựng: admin có quyền `ViewAny:Trip`, `Update:Trip` (như `TripResourceTest` beforeEach), xe, chuyến trạng thái `DriverSwap` có mốc `started` 1.000 (A) và mốc `driver_swap` 1.050 (A, chưa có swap), `vehicle.current_mileage` = 1.070 (cố ý khác). Ba test:

```php
test('reassigning links the swap to the pending handover checkpoint without a second km', function () {
    Livewire::test(ListTrips::class, ['orderType' => 'all', 'activePlaceFilter' => 'all', 'activeStatusFilter' => 'all'])
        ->callTableAction('reassign_driver', $this->trip, data: [
            'new_driver_id' => $this->driverB->id,
            'reason' => DriverSwapReason::CargoNotUnloaded->value,
        ])
        ->assertHasNoTableActionErrors();

    $swap = DriverSwap::where('trip_id', $this->trip->id)->sole();

    expect($swap->checkpoint_id)->toBe($this->handoverCheckpoint->id)
        ->and((float) $swap->handover_km)->toBe(1050.0)
        ->and((float) $this->vehicle->fresh()->current_mileage)->toBe(1070.0);
});

test('changing the receiver before they start updates the existing swap', function () {
    // gán B rồi đổi sang C trước khi B nhập mốc nào
    foreach ([$this->driverB, $this->driverC] as $driver) {
        Livewire::test(ListTrips::class, ['orderType' => 'all', 'activePlaceFilter' => 'all', 'activeStatusFilter' => 'all'])
            ->callTableAction('reassign_driver', $this->trip->fresh(), data: [
                'new_driver_id' => $driver->id,
                'reason' => DriverSwapReason::CargoNotUnloaded->value,
            ]);
        $this->trip->update(['status' => TripStatus::DriverSwap]);
    }

    $swap = DriverSwap::where('trip_id', $this->trip->id)->sole();

    expect($swap->to_driver_id)->toBe($this->driverC->id);
});

test('reassigning a trip without any handover checkpoint is refused', function () {
    $this->handoverCheckpoint->delete();

    Livewire::test(ListTrips::class, ['orderType' => 'all', 'activePlaceFilter' => 'all', 'activeStatusFilter' => 'all'])
        ->callTableAction('reassign_driver', $this->trip, data: [
            'new_driver_id' => $this->driverB->id,
            'reason' => DriverSwapReason::CargoNotUnloaded->value,
        ]);

    expect(DriverSwap::where('trip_id', $this->trip->id)->exists())->toBeFalse();
});
```

Lưu ý khi viết: `ListTrips` mặc định ẩn chuyến Completed/Delivered/Cancelled và cần `orderType`/`activePlaceFilter` = `all`; ô chọn tài xế lọc theo role `driver` → gán role cho driverB/driverC; bộ lọc `'all'` mặc định vẫn hiện chuyến Đảo lái.

- [ ] **Step 2: Chạy, xác nhận đỏ**

Run: `vendor/bin/pest tests/Feature/ReassignDriverHandoverTest.php`
Expected: test 1 FAIL (`checkpoint_id` null, km xe bị ghi 1.070→ giá trị handover), test 2 FAIL (2 swap), test 3 FAIL (swap vẫn được tạo).

- [ ] **Step 3: Sửa action** — trong `ReassignDriverAction`:

Thay field `handover_km` bằng:

```php
Placeholder::make('handover_info')
    ->label('Km bàn giao')
    ->content(function (Trip $record): string {
        $handover = static::latestHandoverCheckpoint($record);

        return $handover
            ? number_format((float) $handover->km_reading, 1, ',', '.').' km lúc '.$handover->occurred_at->format('H:i d/m')
            : 'Chưa có mốc bàn giao';
    }),
```

Thêm helper:

```php
/**
 * Mốc driver_swap mới nhất (mốc đầu của nhóm đồng hành) của chuyến.
 */
protected static function latestHandoverCheckpoint(Trip $trip): ?TripCheckpoint
{
    $latest = TripCheckpoint::where('trip_id', $trip->id)
        ->where('checkpoint_type', CheckpointType::DriverSwap)
        ->whereNotNull('km_reading')
        ->latest('occurred_at')
        ->first();

    if ($latest === null) {
        return null;
    }

    return TripCheckpoint::where('trip_id', $trip->id)
        ->where('checkpoint_type', CheckpointType::DriverSwap)
        ->where('occurred_at', $latest->occurred_at)
        ->orderBy('id')
        ->first();
}
```

Trong `->action(...)`, thay khối từ `$handoverKm = (float) $data['handover_km'];` đến hết `DriverSwap::create([...])` và `$record->vehicle->update([...current_mileage...])` bằng:

```php
$handover = static::latestHandoverCheckpoint($record);

if ($handover === null) {
    Notification::make()
        ->danger()
        ->title('Chuyến chưa có mốc bàn giao')
        ->body('Hãy dùng chức năng Đảo lái để ghi km bàn giao trước.')
        ->send();

    return;
}

$handoverKm = (float) $handover->km_reading;
$companionIds = TripCheckpoint::where('trip_id', $record->id)
    ->where('checkpoint_type', CheckpointType::DriverSwap)
    ->where('occurred_at', $handover->occurred_at)
    ->pluck('id');

$existingSwap = DriverSwap::where('trip_id', $record->id)->whereIn('checkpoint_id', $companionIds)->first();
$receiverStarted = $existingSwap !== null && TripCheckpoint::where('trip_id', $record->id)
    ->where('driver_id', $existingSwap->to_driver_id)
    ->where('occurred_at', '>', $handover->occurred_at)
    ->exists();

$swapData = [
    'to_driver_id' => $data['new_driver_id'],
    'to_shift_id' => $newShift?->id,
    'reason' => $data['reason'],
    'created_by' => auth()->id(),
];

if ($existingSwap !== null && ! $receiverStarted) {
    $existingSwap->update($swapData);
} else {
    DriverSwap::create([
        ...$swapData,
        'trip_id' => $record->id,
        'checkpoint_id' => $handover->id,
        'from_driver_id' => $record->driver_id,
        'from_shift_id' => $oldShift?->id,
        'handover_km' => $handoverKm,
    ]);
}
```

Giữ nguyên phần còn lại (đổi trạng thái chuyến, khôi phục trạng thái đơn, thông báo, chuyến quay đầu); biến `$handoverKm` vẫn dùng cho `sendTripDriverSwapped(...)`. Thêm `use App\Models\TripCheckpoint;` nếu chưa có.

- [ ] **Step 4: Chạy test**

Run: `vendor/bin/pest tests/Feature/ReassignDriverHandoverTest.php tests/Feature/TripMultiDriverSwapKmTest.php tests/Feature/DriverFcmNotificationTest.php`
Expected: all pass.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Filament/Resources/Trips/Actions/ReassignDriverAction.php tests/Feature/ReassignDriverHandoverTest.php
git commit -m "feat(trips): reassign driver reuses the handover checkpoint km"
```

---

### Task 5: Nút "Đảo lái" trên web gắn swap vào mốc

**Files:**
- Modify: `app/Filament/Resources/Trips/Actions/DriverSwapAction.php:144-164`
- Test: `tests/Feature/ReassignDriverHandoverTest.php` (thêm test)

- [ ] **Step 1: Viết test đỏ** — thêm vào `ReassignDriverHandoverTest.php` (chuyến ở trạng thái đang chạy, ví dụ `Delivering`, chưa có mốc driver_swap):

```php
test('web driver swap creates the handover checkpoint and links the swap to it', function () {
    $this->handoverCheckpoint->delete();
    $this->trip->update(['status' => TripStatus::Delivering, 'driver_id' => $this->driverA->id]);

    Livewire::test(ListTrips::class, ['orderType' => 'all', 'activePlaceFilter' => 'all', 'activeStatusFilter' => 'all'])
        ->callTableAction('driver_swap', $this->trip, data: [
            'to_driver_id' => $this->driverB->id,
            'handover_km' => 1060,
            'reason' => DriverSwapReason::ShiftHandover->value,
        ])
        ->assertHasNoTableActionErrors();

    $swap = DriverSwap::where('trip_id', $this->trip->id)->sole();

    expect($swap->checkpoint)->not->toBeNull()
        ->and((float) $swap->checkpoint->km_reading)->toBe(1060.0);
});
```

- [ ] **Step 2: Chạy, xác nhận đỏ**

Run: `vendor/bin/pest tests/Feature/ReassignDriverHandoverTest.php --filter="web driver swap"`
Expected: FAIL (`checkpoint` null).

- [ ] **Step 3: Đảo thứ tự tạo** trong `DriverSwapAction` — tạo mốc trước, lấy mốc đầu nhóm, rồi tạo swap:

```php
$handoverCheckpoints = app(CheckpointFactory::class)->create(
    $record,
    ['occurred_at' => now(), 'km_reading' => $handoverKm],
    CheckpointType::DriverSwap,
);

DriverSwap::create([
    'trip_id' => $record->id,
    'checkpoint_id' => $handoverCheckpoints->min('id'),
    'from_driver_id' => $record->driver_id,
    'to_driver_id' => $toDriverId,
    'from_shift_id' => $record->shift_id,
    'handover_km' => $handoverKm,
    'reason' => $data['reason'],
    'note' => $data['note'] ?? null,
    'created_by' => Auth::id(),
]);

$record->vehicle->update(['current_mileage' => $handoverKm]);
```

(xoá khối `CheckpointFactory::create` cũ đứng sau `DriverSwap::create`).

- [ ] **Step 4: Chạy test**

Run: `vendor/bin/pest tests/Feature/ReassignDriverHandoverTest.php`
Expected: all pass.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Filament/Resources/Trips/Actions/DriverSwapAction.php tests/Feature/ReassignDriverHandoverTest.php
git commit -m "fix(trips): web driver swap links the swap to its handover checkpoint"
```

---

### Task 6: Sửa một mốc bàn giao → cả nhóm đồng hành đổi theo

**Files:**
- Create: `app/Observers/TripCheckpointObserver.php`
- Modify: `app/Providers/AppServiceProvider.php:47-49` (đăng ký observer)
- Test: `tests/Feature/TripMultiDriverSwapKmTest.php`

**Lý do:** chuyến nhiều đơn có N mốc driver_swap cho một lần bàn giao. Form sửa chuyến hiển thị N dòng; điều hành sửa 1 dòng thì các dòng kia phải đổi theo, nếu không nhóm bị tách thành 2 điểm bàn giao.

- [ ] **Step 1: Viết test đỏ** — thêm vào `TripMultiDriverSwapKmTest.php`:

```php
test('editing one handover row updates its companion rows of the same handover', function () {
    $secondOrder = Order::create([
        'order_code' => 'ORD-SWAP5-B',
        'type' => OrderType::Hhhk,
        'area_id' => $this->order->area_id,
        'customer_id' => $this->order->customer_id,
        'trip_id' => $this->trip->id,
        'status' => OrderStatus::Completed,
        'created_by' => $this->drivers['A']->id,
    ]);

    $companion = TripCheckpoint::create([
        ...$this->handovers['B->C']->only(['trip_id', 'driver_id', 'shift_id', 'checkpoint_type', 'km_reading', 'occurred_at']),
        'order_id' => $secondOrder->id,
    ]);

    $this->handovers['B->C']->update(['km_reading' => 1105]);

    expect((float) $companion->fresh()->km_reading)->toBe(1105.0);
});
```

- [ ] **Step 2: Chạy, xác nhận đỏ**

Run: `vendor/bin/pest tests/Feature/TripMultiDriverSwapKmTest.php --filter=companion`
Expected: FAIL (companion vẫn 1100).

- [ ] **Step 3: Tạo observer** `app/Observers/TripCheckpointObserver.php`:

```php
<?php

namespace App\Observers;

use App\Enums\CheckpointType;
use App\Models\TripCheckpoint;

class TripCheckpointObserver
{
    /**
     * Một lần bàn giao có nhiều mốc driver_swap (mỗi đơn một mốc). Sửa km/giờ của một mốc thì đồng bộ cả nhóm.
     */
    public function updated(TripCheckpoint $checkpoint): void
    {
        if ($checkpoint->checkpoint_type !== CheckpointType::DriverSwap
            || ! $checkpoint->wasChanged(['km_reading', 'occurred_at'])) {
            return;
        }

        TripCheckpoint::query()
            ->where('trip_id', $checkpoint->trip_id)
            ->where('checkpoint_type', CheckpointType::DriverSwap)
            ->where('id', '!=', $checkpoint->id)
            ->where('occurred_at', $checkpoint->getOriginal('occurred_at'))
            ->update([
                'km_reading' => $checkpoint->km_reading,
                'occurred_at' => $checkpoint->occurred_at,
            ]);
    }
}
```

(`->update()` của query builder không bắn event → không đệ quy.)

Đăng ký trong `AppServiceProvider::boot()` cạnh các observer khác:

```php
TripCheckpoint::observe(TripCheckpointObserver::class);
```

- [ ] **Step 4: Chạy test**

Run: `vendor/bin/pest tests/Feature/TripMultiDriverSwapKmTest.php tests/Feature/TripKmReportTest.php`
Expected: all pass (`TripKmAdjustmentService::resolveReport` đã tự cập nhật đồng hành bằng query builder, không xung đột).

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Observers/TripCheckpointObserver.php app/Providers/AppServiceProvider.php tests/Feature/TripMultiDriverSwapKmTest.php
git commit -m "fix(trips): keep companion handover checkpoints in sync on edit"
```

---

### Task 7: Gắn checkpoint_id cho dữ liệu cũ

**Files:**
- Create: `app/Console/Commands/LinkDriverSwapsCommand.php` (`php artisan make:command LinkDriverSwapsCommand --no-interaction`)
- Test: `tests/Feature/LinkDriverSwapsCommandTest.php`

**Interfaces:**
- Consumes: `TripKmSplitService::matchUnlinkedSwaps(Trip): array<int, int>` (Task 3).

- [ ] **Step 1: Viết test đỏ** `tests/Feature/LinkDriverSwapsCommandTest.php`: dựng chuyến có mốc driver_swap 1.050 + swap chưa gắn `handover_km` 1.050, và một swap chưa gắn 1.300 không có mốc.

```php
test('links legacy swaps to matching handover checkpoints and reports the rest', function () {
    $this->artisan('trips:link-driver-swaps')
        ->expectsOutputToContain('Đã gắn: 1')
        ->expectsOutputToContain('Không khớp: 1')
        ->assertSuccessful();

    expect($this->matchedSwap->fresh()->checkpoint_id)->toBe($this->handoverCheckpoint->id)
        ->and($this->orphanSwap->fresh()->checkpoint_id)->toBeNull();
});

test('dry run changes nothing', function () {
    $this->artisan('trips:link-driver-swaps --dry-run')->assertSuccessful();

    expect($this->matchedSwap->fresh()->checkpoint_id)->toBeNull();
});
```

- [ ] **Step 2: Chạy, xác nhận đỏ**

Run: `vendor/bin/pest tests/Feature/LinkDriverSwapsCommandTest.php`
Expected: FAIL (command không tồn tại).

- [ ] **Step 3: Viết command**

```php
<?php

namespace App\Console\Commands;

use App\Models\DriverSwap;
use App\Models\Trip;
use App\Services\TripKmSplitService;
use Illuminate\Console\Command;

class LinkDriverSwapsCommand extends Command
{
    protected $signature = 'trips:link-driver-swaps {--dry-run : Chỉ báo cáo, không ghi}';

    protected $description = 'Gắn checkpoint_id cho các bản ghi driver_swaps cũ theo mốc bàn giao cùng km';

    public function handle(): int
    {
        $linked = 0;
        $unmatched = [];

        $tripIds = DriverSwap::whereNull('checkpoint_id')->distinct()->pluck('trip_id');

        foreach (Trip::whereIn('id', $tripIds)->get() as $trip) {
            $matches = TripKmSplitService::matchUnlinkedSwaps($trip);

            foreach (DriverSwap::where('trip_id', $trip->id)->whereNull('checkpoint_id')->get() as $swap) {
                if (! isset($matches[$swap->id])) {
                    $unmatched[] = [$trip->trip_code, $swap->id, $swap->handover_km];

                    continue;
                }

                if (! $this->option('dry-run')) {
                    $swap->update(['checkpoint_id' => $matches[$swap->id]]);
                }

                $linked++;
            }
        }

        $this->info("Đã gắn: {$linked}");
        $this->info('Không khớp: '.count($unmatched));

        if ($unmatched !== []) {
            $this->table(['Chuyến', 'Swap ID', 'Km trên swap'], $unmatched);
        }

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Chạy test**

Run: `vendor/bin/pest tests/Feature/LinkDriverSwapsCommandTest.php`
Expected: PASS.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Console/Commands/LinkDriverSwapsCommand.php tests/Feature/LinkDriverSwapsCommandTest.php
git commit -m "feat(trips): command to link legacy driver swaps to handover checkpoints"
```

Khi deploy: chạy `php artisan trips:link-driver-swaps --dry-run`, gửi bảng "Không khớp" cho điều hành xem, rồi chạy không `--dry-run`.

---

### Task 8: Km bắt đầu ca của tài xế nhận lấy từ mốc

**Files:**
- Modify: `app/Http/Controllers/Api/DriverShiftController.php:78-95`
- Test: test API start shift hiện có cho fallback km (tìm bằng `grep -rn "shifts/start" tests`); nếu chưa có, thêm vào `tests/Feature/ReassignDriverHandoverTest.php`.

- [ ] **Step 1: Viết test đỏ**: tài xế B có swap đang chờ (`to_driver_id` = B, `checkpoint_id` → mốc 1.050, `handover_km` = 1.040 cố ý lệch), xe không xác định (`startKm <= 0`); B gọi `POST /api/driver/shifts/start` → kỳ vọng `start_km` = 1.050.

- [ ] **Step 2: Chạy, xác nhận đỏ** (ra 1.040).

- [ ] **Step 3: Sửa** — trong khối `if ($pendingSwap)`:

```php
$handoverKm = (float) ($pendingSwap->checkpoint?->km_reading ?? $pendingSwap->handover_km ?? 0);
```

thay cho 2 bước lấy `handover_km` rồi fallback mốc driver_swap đầu tiên.

- [ ] **Step 4: Chạy test** → PASS.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/Api/DriverShiftController.php tests
git commit -m "fix(shifts): receiving driver start km comes from the handover checkpoint"
```

---

## Kiểm tra cuối

- [ ] `vendor/bin/pest tests/Feature/TripMultiDriverSwapKmTest.php tests/Feature/ReassignDriverHandoverTest.php tests/Feature/LinkDriverSwapsCommandTest.php tests/Feature/TripKmSplitServiceTest.php tests/Feature/ShiftKmCalculatorTest.php tests/Feature/HandoverKmResolverTest.php tests/Feature/TripWebCompletionKmTest.php tests/Feature/TripStatsTest.php tests/Feature/OrderFullFlowTest.php tests/Feature/TripKmReportTest.php`
- [ ] Nhờ người dùng chạy toàn bộ: `php artisan test --compact`.

## Ngoài phạm vi (đề xuất riêng)

- `DriverSwapController` không có route → đề xuất xoá (cần duyệt).
- `HandoverKmResolver` + `HandoverKmResolverTest` → xoá sau khi Task 7 đã chạy trên production và không còn swap chưa gắn (cần duyệt xoá test).
- Lọc ca theo cột `vehicle_id` không tồn tại trong `driver_shifts` (`TripsTable` EditAction, `TripForm::fillCheckpointRelationshipData`, `DriverSwapController`).
- Luồng sửa chuyến (km xe theo chuyến mới nhất, chỉ tính lại khi km đổi) — đã bàn, chưa làm.
