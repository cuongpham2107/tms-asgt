# Sửa chuyến tính lại km đúng chuẩn + Báo sai km — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Điều hành sửa chuyến (xe mất sóng, sửa km sai, sửa chuyến cũ) thì km chuyến/ca/xe luôn đúng một chuẩn; luồng báo sai km không còn gửi thông báo cho tài xế, không đánh dấu nhầm thông báo chuyến khác, không spam khi báo lại.

**Architecture:** Mốc hành trình là gốc của km. Km bắt đầu/kết thúc của chuyến (xe công ty) tự lấy từ mốc sớm nhất/muộn nhất. Sửa chuyến chỉ tính lại khi dữ liệu km thay đổi, và km xe chỉ cập nhật khi chuyến đang sửa là chuyến mới nhất của xe. Thông báo báo sai km mang `km_report_id` trong `viewData` để tìm chính xác.

**Tech Stack:** Laravel 12 / PHP 8.4, Filament v4, Pest, SQLite.

**Spec:** Quyết định trong hội thoại 2026-10-17:
- Km xe chỉ quan tâm khi không có chuyến nào tiếp sau; sửa chuyến cũ chỉ đụng km chuyến + mốc (+ ca).
- Chỉ tính lại khi km/mốc thay đổi; chặn km mốc đi lùi theo giờ.
- Ô Km bắt đầu / Km kết thúc **tự lấy từ mốc**, chỉ để xem.
- Sửa km qua form khi có báo cáo chờ → **tự đóng báo cáo**, chuyến hết tạm dừng.
- Thông báo gửi **mọi user trừ tài xế**.
- Báo lại khi còn chờ → **ghi đè, chỉ báo 1 lần** (lưu lần trước vào ghi chú, cập nhật thông báo cũ thay vì gửi mới).

**Nền:** nhánh `fix/driver-swap-checkpoint-link` (đã có `driver_swaps.checkpoint_id`, `TripKmSplitService` mới, `TripCheckpointObserver`). Làm tiếp trên nhánh này.

## Global Constraints

- Không thêm dependency mới.
- `database/database.sqlite` là dữ liệu production thật — KHÔNG chạy `migrate`, `db:*`, tinker hay lệnh ghi DB mặc định. Pest dùng SQLite in-memory.
- Chạy `vendor/bin/pint --dirty --format agent` sau khi sửa PHP.
- Không xoá test hiện có khi chưa được người dùng duyệt; được sửa assert khi hành vi đổi theo spec.
- Xe thuê ngoài (`VehicleOwnerType::Rent`) giữ nguyên cách nhập `total_km` tay — không áp quy tắc km đầu/cuối từ mốc.
- Không đổi các luồng app tài xế (`TripCheckpointService`, `Trip::complete`, `EndHandler`, `TripController`) trừ chỗ plan nêu.
- Commit message kết thúc bằng dòng trống rồi `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Bối cảnh cho người thực hiện

- Sửa chuyến: `app/Filament/Resources/Trips/Tables/TripsTable.php` — `EditAction` (~dòng 325-403) gọi `->after(fn () => self::recalculateKm($record))`; `recalculateKm()` (~dòng 843) tính lại chuyến, gán `vehicle.current_mileage = end_km` **vô điều kiện** (sửa chuyến cũ làm km xe lùi), rồi tính ca.
- Thứ tự lifecycle Filament v4 của EditAction: `before()` chạy **trước** khi repeater mốc được lưu; `using()` lưu trip; `after()` chạy cuối cùng (cùng transaction).
- Form: `app/Filament/Resources/Trips/Schemas/TripForm.php` — `start_km`/`end_km` (~dòng 98-126, ẩn với xe thuê), repeater `checkpoints` (xe công ty, ~dòng 250+, cột `km_reading` có mask `$money` nên giá trị state có thể chứa dấu phẩy).
- Báo sai km: `app/Http/Controllers/Api/TripKmReportController.php` (tạo/ghi đè báo cáo chờ, pause chuyến); `app/Observers/TripKmReportObserver.php` (gửi thông báo khi tạo / đổi `reported_km`, gỡ nút khi đổi trạng thái); `app/Observers/TripObserver.php` (`notifyUsersTripKmReported`, `clearKmReportNotificationActions` dùng `LIKE` quá rộng — `%tableActionRecord%{id}%` khớp gần như mọi thông báo; `sendTripNotification` gửi `User::all()`).
- Dữ liệu thật: 63.431 thông báo, 61.587 (97%) gửi cho tài xế; 113 user trong đó 110 có role `driver`.
- Tài xế = user có role Spatie `driver` (`User::role('driver')`).

## File Structure

| File | Trách nhiệm | Task |
|---|---|---|
| `app/Services/TripKmAdjustmentService.php` | `recalculateAfterEdit()`, `kmSnapshot()`, `closePendingReportsAfterEdit()`, km xe theo chuyến mới nhất | 1 |
| `tests/Feature/TripEditKmRecalculationTest.php` (mới) | test service + EditAction | 1, 2 |
| `app/Filament/Resources/Trips/Tables/TripsTable.php` | EditAction: snapshot trước/sau, đóng báo cáo, bỏ `recalculateKm()` | 2 |
| `app/Filament/Resources/Trips/Schemas/TripForm.php` | `start_km`/`end_km` chỉ xem; rule km mốc tăng dần | 2, 3 |
| `app/Observers/TripObserver.php` | người nhận trừ tài xế; `viewData` km_report_id; tìm thông báo chính xác; cập nhật thông báo khi báo lại | 4, 5 |
| `app/Observers/TripKmReportObserver.php` | báo lại → cập nhật thông báo cũ | 5 |
| `app/Http/Controllers/Api/TripKmReportController.php` | lưu lần báo trước vào ghi chú | 5 |
| `tests/Feature/TripKmReportTest.php` | test thông báo + báo lại | 4, 5 |

---

### Task 1: Service tính lại km sau khi sửa chuyến

**Files:**
- Modify: `app/Services/TripKmAdjustmentService.php`
- Test: `tests/Feature/TripEditKmRecalculationTest.php` (tạo bằng `php artisan make:test --pest TripEditKmRecalculationTest --no-interaction`)

**Interfaces — Produces (Task 2 dùng):**
- `TripKmAdjustmentService::recalculateAfterEdit(Trip $trip): void`
- `TripKmAdjustmentService::kmSnapshot(Trip $trip): array` (mảng đã chuẩn hóa, so sánh bằng `!==`)
- `TripKmAdjustmentService::closePendingReportsAfterEdit(Trip $trip, ?int $adminId): void`

**Quy tắc:**
1. Xe công ty có mốc có km > 0: `start_km` = km mốc **sớm nhất theo giờ**; `end_km` = km mốc **muộn nhất theo giờ** nếu chuyến đã có `end_km` hoặc trạng thái `Completed` (chuyến đang chạy chưa có `end_km` thì giữ null). Xe thuê: không đổi start/end.
2. Sau đó chạy `TripKmCalculatorService::calculate()` + `ShiftKmCalculatorService::calculateForTrip()` như hiện nay.
3. Km xe: nếu **không có chuyến nào khác của cùng xe có `started_at` muộn hơn** chuyến này → `vehicle.current_mileage` = max(end_km, km lớn nhất của mốc chuyến này) (kể cả khi thấp hơn hiện tại — để sửa số nhập lố). Có chuyến sau → **không đụng km xe**. Chuyến chưa có `started_at` → không đụng.
4. `resolveReport()` dùng cùng quy tắc km xe (thay `syncVehicleMileage()` lấy max mọi chuyến, và bỏ dòng gán thẳng `current_mileage = correctedKm` ở nhánh không có checkpoint).

- [ ] **Step 1: Viết test đỏ** — `tests/Feature/TripEditKmRecalculationTest.php`. Dựng: xe công ty `current_mileage` 102.000; chuyến cũ A (`started_at` 3 ngày trước, Delivering, start 100.000, end 100.120, mốc started 100.000 / arrived_pickup 100.050 / arrived_delivery 100.120); chuyến mới B cùng xe (`started_at` 2 giờ trước, start 101.800, end 102.000, mốc started 101.800).

```php
test('recalculating an old trip leaves vehicle mileage alone', function () {
    TripCheckpoint::where('trip_id', $this->oldTrip->id)->where('checkpoint_type', CheckpointType::ArrivedDelivery)->update(['km_reading' => 100150]);

    app(TripKmAdjustmentService::class)->recalculateAfterEdit($this->oldTrip->fresh());

    expect((float) $this->oldTrip->fresh()->end_km)->toBe(100150.0)
        ->and((float) $this->oldTrip->fresh()->total_km)->toBe(150.0)
        ->and((float) $this->vehicle->fresh()->current_mileage)->toBe(102000.0);
});

test('recalculating the latest trip moves vehicle mileage to its final km, even downwards', function () {
    $this->vehicle->update(['current_mileage' => 150000]); // từng bị nhập lố
    TripCheckpoint::create([
        'trip_id' => $this->newTrip->id, 'driver_id' => $this->driver->id,
        'checkpoint_type' => CheckpointType::Completed, 'km_reading' => 102000, 'occurred_at' => now()->subHour(),
    ]);

    app(TripKmAdjustmentService::class)->recalculateAfterEdit($this->newTrip->fresh());

    expect((float) $this->vehicle->fresh()->current_mileage)->toBe(102000.0);
});

test('start and end km are taken from the earliest and latest checkpoints', function () {
    $this->oldTrip->updateQuietly(['start_km' => 99000, 'end_km' => 999999]);

    app(TripKmAdjustmentService::class)->recalculateAfterEdit($this->oldTrip->fresh());

    $trip = $this->oldTrip->fresh();
    expect((float) $trip->start_km)->toBe(100000.0)
        ->and((float) $trip->end_km)->toBe(100120.0)
        ->and((float) $trip->total_km)->toBe(120.0);
});

test('km snapshot ignores formatting noise but sees a km change', function () {
    $service = app(TripKmAdjustmentService::class);
    $before = $service->kmSnapshot($this->oldTrip);

    TripCheckpoint::where('trip_id', $this->oldTrip->id)->update(['occurred_at' => DB::raw("strftime('%Y-%m-%d %H:%M:00', occurred_at)")]);
    expect($service->kmSnapshot($this->oldTrip))->toBe($before);

    TripCheckpoint::where('trip_id', $this->oldTrip->id)->where('checkpoint_type', CheckpointType::ArrivedPickup)->update(['km_reading' => 100060]);
    expect($service->kmSnapshot($this->oldTrip))->not->toBe($before);
});

test('closing pending reports after an edit resolves them and unpauses the trip', function () {
    $report = TripKmReport::create([
        'trip_id' => $this->oldTrip->id, 'driver_id' => $this->driver->id, 'vehicle_id' => $this->vehicle->id,
        'reported_km' => 100150, 'system_km' => 100120, 'status' => TripKmReportStatus::Pending,
    ]);
    $this->oldTrip->update(['is_paused' => true, 'paused_reason' => 'Chờ điều chỉnh báo sai số Km']);

    app(TripKmAdjustmentService::class)->closePendingReportsAfterEdit($this->oldTrip->fresh(), $this->admin->id);

    expect($report->fresh()->status)->toBe(TripKmReportStatus::Resolved)
        ->and($report->fresh()->resolved_by)->toBe($this->admin->id)
        ->and($report->fresh()->admin_note)->toBe('Đã sửa km qua form sửa chuyến')
        ->and($this->oldTrip->fresh()->is_paused)->toBeFalse();
});
```

(Dựng thêm `$this->admin` = user thường không có role driver. Chỉnh `occurred_at` các mốc có giây khác 0, ví dụ `now()->subDays(3)->setSeconds(17)`, để test snapshot có ý nghĩa.)

- [ ] **Step 2: Chạy, xác nhận đỏ**

Run: `vendor/bin/pest tests/Feature/TripEditKmRecalculationTest.php`
Expected: FAIL — các method chưa tồn tại.

- [ ] **Step 3: Viết code** trong `TripKmAdjustmentService` (thêm `use App\Enums\TripStatus; use App\Enums\VehicleOwnerType; use Carbon\Carbon;`):

```php
/**
 * Tính lại km sau khi điều hành sửa chuyến: km đầu/cuối lấy từ mốc, rồi chuyến, ca, km xe (chỉ khi là chuyến mới nhất).
 */
public function recalculateAfterEdit(Trip $trip): void
{
    DB::transaction(function () use ($trip) {
        $this->syncBoundariesFromCheckpoints($trip);

        app(TripKmCalculatorService::class)->calculate($trip);
        $trip->refresh();

        app(ShiftKmCalculatorService::class)->calculateForTrip($trip);

        $this->syncVehicleMileageIfLatest($trip);
    });
}

/**
 * Dữ liệu đầu vào ảnh hưởng tới km chuyến/ca, đã chuẩn hóa (form lưu giờ theo phút, km dạng chuỗi).
 *
 * @return array{trip: array<string, mixed>, checkpoints: list<array<string, mixed>>}
 */
public function kmSnapshot(Trip $trip): array
{
    $km = fn ($value): ?float => $value === null ? null : round((float) $value, 1);
    $minute = fn ($value): ?string => $value === null ? null : Carbon::parse($value)->format('Y-m-d H:i');
    $id = fn ($value): ?int => $value === null ? null : (int) $value;

    $row = Trip::query()->whereKey($trip->getKey())->first();

    return [
        'trip' => [
            'start_km' => $km($row?->start_km),
            'end_km' => $km($row?->end_km),
            'driver_id' => $id($row?->driver_id),
            'shift_id' => $id($row?->shift_id),
            'started_at' => $minute($row?->started_at),
            'completed_at' => $minute($row?->completed_at),
        ],
        'checkpoints' => TripCheckpoint::query()
            ->where('trip_id', $trip->getKey())
            ->orderBy('id')
            ->get()
            ->map(fn (TripCheckpoint $cp): array => [
                'id' => $cp->id,
                'checkpoint_type' => $cp->getRawOriginal('checkpoint_type'),
                'order_id' => $id($cp->order_id),
                'driver_id' => $id($cp->driver_id),
                'km_reading' => $km($cp->km_reading),
                'occurred_at' => $minute($cp->occurred_at),
            ])
            ->all(),
    ];
}

/**
 * Điều hành đã sửa km trực tiếp → đóng các báo cáo sai km đang chờ của chuyến và bỏ tạm dừng.
 */
public function closePendingReportsAfterEdit(Trip $trip, ?int $adminId): void
{
    $pending = TripKmReport::where('trip_id', $trip->id)->where('status', TripKmReportStatus::Pending)->get();

    if ($pending->isEmpty()) {
        return;
    }

    $pending->each(fn (TripKmReport $report) => $report->update([
        'status' => TripKmReportStatus::Resolved,
        'admin_note' => 'Đã sửa km qua form sửa chuyến',
        'resolved_by' => $adminId,
        'resolved_at' => now(),
    ]));

    $trip->update(['is_paused' => false, 'paused_reason' => null]);
}

/**
 * Xe công ty: km bắt đầu = mốc sớm nhất, km kết thúc = mốc muộn nhất (khi chuyến đã có km kết thúc hoặc đã hoàn thành).
 */
private function syncBoundariesFromCheckpoints(Trip $trip): void
{
    $trip->loadMissing('vehicle');

    if ($trip->vehicle?->type === VehicleOwnerType::Rent) {
        return;
    }

    $kmCheckpoints = TripCheckpoint::where('trip_id', $trip->id)
        ->whereNotNull('km_reading')
        ->where('km_reading', '>', 0);

    $first = (clone $kmCheckpoints)->orderBy('occurred_at')->orderBy('id')->first();
    $last = (clone $kmCheckpoints)->orderByDesc('occurred_at')->orderByDesc('id')->first();

    if ($first === null) {
        return;
    }

    $trip->start_km = $first->km_reading;

    if ($trip->end_km !== null || $trip->status === TripStatus::Completed) {
        $trip->end_km = $last->km_reading;
    }

    $trip->save();
}

/**
 * Km xe chỉ đi theo chuyến mới nhất của xe; sửa chuyến cũ không đụng km xe.
 */
private function syncVehicleMileageIfLatest(Trip $trip): void
{
    $vehicle = $trip->vehicle;

    if ($vehicle === null || $trip->started_at === null) {
        return;
    }

    $hasLaterTrip = Trip::where('vehicle_id', $vehicle->id)
        ->whereKeyNot($trip->id)
        ->whereNotNull('started_at')
        ->where('started_at', '>', $trip->started_at)
        ->exists();

    if ($hasLaterTrip) {
        return;
    }

    $finalKm = max(
        (float) ($trip->end_km ?? 0),
        (float) (TripCheckpoint::where('trip_id', $trip->id)->max('km_reading') ?? 0),
    );

    if ($finalKm > 0) {
        $vehicle->update(['current_mileage' => $finalKm]);
    }
}
```

Trong `resolveReport()`: thay `$this->syncVehicleMileage($trip);` bằng `$this->syncVehicleMileageIfLatest($trip);`; ở nhánh `else` (không có checkpoint mục tiêu) xoá khối `if ($trip->vehicle) { $trip->vehicle->update(['current_mileage' => $correctedKm]); }`. Xoá hàm `syncVehicleMileage()` cũ nếu không còn ai gọi (`grep -rn syncVehicleMileage app`).

- [ ] **Step 4: Chạy test**

Run: `vendor/bin/pest tests/Feature/TripEditKmRecalculationTest.php tests/Feature/TripKmReportTest.php tests/Feature/TripWebCompletionKmTest.php tests/Feature/TripMultiDriverSwapKmTest.php`
Expected: all pass. Nếu test cũ của `TripKmReportTest` assert km xe theo kiểu "max mọi chuyến", sửa assert theo quy tắc chuyến mới nhất và ghi vào report.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/TripKmAdjustmentService.php tests/Feature/TripEditKmRecalculationTest.php tests/Feature/TripKmReportTest.php
git commit -m "feat(trips): recalc km after edit from checkpoints, vehicle km only for the latest trip"
```

---

### Task 2: Nối EditAction — chỉ tính lại khi km đổi, đóng báo cáo chờ, km đầu/cuối chỉ xem

**Files:**
- Modify: `app/Filament/Resources/Trips/Tables/TripsTable.php` (`configure()` + EditAction ~325-403, xoá `recalculateKm()` ~843)
- Modify: `app/Filament/Resources/Trips/Schemas/TripForm.php` (`start_km`, `end_km`)
- Test: `tests/Feature/TripEditKmRecalculationTest.php`

**Interfaces — Consumes:** `recalculateAfterEdit`, `kmSnapshot`, `closePendingReportsAfterEdit` (Task 1).

- [ ] **Step 1: Viết test đỏ** — thêm vào `TripEditKmRecalculationTest.php`. Admin cần quyền `ViewAny:Trip`, `View:Trip`, `Update:Trip` (xem beforeEach của `tests/Feature/TripResourceTest.php`); tài xế cần role `driver` (option select lọc theo role). Gọi `Livewire::test(ListTrips::class, ['orderType' => 'all', 'activePlaceFilter' => 'all', 'activeStatusFilter' => 'all'])` (mặc định ẩn Completed/Delivered/Cancelled → chuyến test để `Delivering`). Key của repeater là `record-{id}`.

```php
test('editing a trip without km changes does not recalculate', function () {
    $this->oldTrip->updateQuietly(['total_km' => 999]);

    editTripTable()->callTableAction('edit', $this->oldTrip, data: ['trip_code' => 'TRIP-OLD-RENAMED'])
        ->assertHasNoTableActionErrors();

    expect((float) $this->oldTrip->fresh()->total_km)->toBe(999.0)
        ->and((float) $this->vehicle->fresh()->current_mileage)->toBe(102000.0);
});

test('editing checkpoint km of an old trip recalculates it without touching vehicle km', function () {
    editTripTable()->mountTableAction('edit', $this->oldTrip)
        ->fillForm(['checkpoints.record-'.$this->deliveryCheckpoint->id.'.km_reading' => 100150])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    expect((float) $this->oldTrip->fresh()->total_km)->toBe(150.0)
        ->and((float) $this->vehicle->fresh()->current_mileage)->toBe(102000.0);
});

test('editing km closes a pending km report', function () {
    $report = TripKmReport::create([
        'trip_id' => $this->oldTrip->id, 'driver_id' => $this->driver->id, 'vehicle_id' => $this->vehicle->id,
        'reported_km' => 100150, 'system_km' => 100120, 'status' => TripKmReportStatus::Pending,
    ]);

    editTripTable()->mountTableAction('edit', $this->oldTrip)
        ->fillForm(['checkpoints.record-'.$this->deliveryCheckpoint->id.'.km_reading' => 100150])
        ->callMountedTableAction();

    expect($report->fresh()->status)->toBe(TripKmReportStatus::Resolved);
});

test('start and end km inputs are read-only and not saved from the form', function () {
    editTripTable()->mountTableAction('edit', $this->oldTrip)
        ->assertTableActionDataSet(['start_km' => 100000.0])
        ->fillForm(['end_km' => 555555])
        ->callMountedTableAction();

    expect((float) $this->oldTrip->fresh()->end_km)->toBe(100120.0);
});
```

với helper:

```php
function editTripTable()
{
    return Livewire::test(ListTrips::class, ['orderType' => 'all', 'activePlaceFilter' => 'all', 'activeStatusFilter' => 'all']);
}
```

(`$this->deliveryCheckpoint` = mốc arrived_delivery 100.120 của chuyến cũ, đặt trong beforeEach. Nếu `assertTableActionDataSet` không có trong bản Filament đang cài, bỏ assert đó, giữ assert end_km.)

- [ ] **Step 2: Chạy, xác nhận đỏ**

Run: `vendor/bin/pest tests/Feature/TripEditKmRecalculationTest.php`
Expected: 4 test mới FAIL (km xe bị đổi, total_km bị ghi đè, báo cáo vẫn pending, end_km bị lưu 555555).

- [ ] **Step 3: Sửa `TripsTable`**

Đầu `configure()`:

```php
$kmSnapshotBeforeEdit = null;
```

Trong EditAction, ngay sau `->schema(...)`:

```php
->before(function (Trip $record) use (&$kmSnapshotBeforeEdit): void {
    $kmSnapshotBeforeEdit = app(TripKmAdjustmentService::class)->kmSnapshot($record);
})
```

Thay `->after(function (Trip $record): void { self::recalculateKm($record); })` bằng:

```php
->after(function (Trip $record) use (&$kmSnapshotBeforeEdit): void {
    $service = app(TripKmAdjustmentService::class);

    if ($kmSnapshotBeforeEdit === $service->kmSnapshot($record)) {
        return;
    }

    $service->recalculateAfterEdit($record);
    $service->closePendingReportsAfterEdit($record, Auth::id());
}),
```

Xoá `private static function recalculateKm()` và các `use` không còn dùng (`ShiftKmCalculatorService`, `TripKmCalculatorService` — kiểm tra bằng grep trong file). Thêm `use App\Services\TripKmAdjustmentService;` và `use Illuminate\Support\Facades\Auth;` nếu chưa có.

- [ ] **Step 4: Sửa `TripForm`** — với `TextInput::make('start_km')` và `TextInput::make('end_km')` thêm:

```php
->disabled()
->helperText('Tự lấy từ km mốc sớm nhất')   // start_km
->helperText('Tự lấy từ km mốc muộn nhất')  // end_km
```

(Field disabled không được dehydrate nên không ghi đè DB. Giữ nguyên `->hidden(...)` cho xe thuê và `afterStateHydrated` hiện có.)

- [ ] **Step 5: Chạy test**

Run: `vendor/bin/pest tests/Feature/TripEditKmRecalculationTest.php tests/Feature/TripResourceTest.php tests/Feature/ExternalVehicleDriverAssignmentTest.php tests/Feature/TripWebCompletionKmTest.php`
Expected: all pass. Test cũ nào nhập `start_km`/`end_km` qua form xe công ty thì chuyển sang nhập km mốc, ghi vào report.

- [ ] **Step 6: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Filament/Resources/Trips tests/Feature
git commit -m "feat(trips): trip edit recalculates km only when km data changes"
```

---

### Task 3: Chặn km mốc đi lùi theo giờ

**Files:**
- Modify: `app/Filament/Resources/Trips/Schemas/TripForm.php` (repeater `checkpoints` của xe công ty, dòng có `->addActionLabel('Thêm mốc hành trình')` thứ hai)
- Test: `tests/Feature/TripEditKmRecalculationTest.php`

- [ ] **Step 1: Viết test đỏ**

```php
test('saving checkpoints whose km goes backwards in time is rejected', function () {
    editTripTable()->mountTableAction('edit', $this->oldTrip)
        ->fillForm(['checkpoints.record-'.$this->pickupCheckpoint->id.'.km_reading' => 10050])
        ->callMountedTableAction()
        ->assertHasTableActionErrors(['checkpoints']);

    expect((float) $this->pickupCheckpoint->fresh()->km_reading)->toBe(100050.0);
});

test('checkpoints at the same minute may carry different km', function () {
    $this->pickupCheckpoint->update(['occurred_at' => $this->deliveryCheckpoint->occurred_at]);

    editTripTable()->mountTableAction('edit', $this->oldTrip)
        ->fillForm(['trip_code' => 'SAME-MINUTE'])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();
});
```

(`$this->pickupCheckpoint` = mốc arrived_pickup 100.050.)

- [ ] **Step 2: Chạy, xác nhận đỏ** — test 1 FAIL (không có lỗi validate).

- [ ] **Step 3: Thêm rule** — vào repeater `checkpoints` (xe công ty) thêm `->rules([fn (): Closure => self::checkpointKmIncreasingRule()])`, và method trong `TripForm` (thêm `use Carbon\Carbon; use Closure;`):

```php
/**
 * Km các mốc phải tăng dần theo thời gian; các mốc cùng thời điểm được phép lệch nhau.
 */
public static function checkpointKmIncreasingRule(): Closure
{
    return function (string $attribute, mixed $value, Closure $fail): void {
        $rows = collect(is_array($value) ? $value : [])
            ->filter(fn ($row): bool => is_array($row) && filled($row['km_reading'] ?? null) && filled($row['occurred_at'] ?? null))
            ->map(fn (array $row): array => [
                'km' => (float) str_replace(',', '', (string) $row['km_reading']),
                'at' => Carbon::parse($row['occurred_at']),
            ])
            ->sortBy(fn (array $row): int => $row['at']->getTimestamp());

        $highest = null;

        foreach ($rows as $row) {
            if ($highest !== null && $row['at']->gt($highest['at']) && $row['km'] < $highest['km']) {
                $fail(sprintf(
                    'Km mốc lúc %s (%s km) nhỏ hơn mốc lúc %s (%s km). Km phải tăng dần theo thời gian.',
                    $row['at']->format('H:i d/m'),
                    number_format($row['km'], 1, ',', '.'),
                    $highest['at']->format('H:i d/m'),
                    number_format($highest['km'], 1, ',', '.'),
                ));

                return;
            }

            if ($highest === null || $row['km'] >= $highest['km']) {
                $highest = $row;
            }
        }
    };
}
```

- [ ] **Step 4: Chạy test** — `vendor/bin/pest tests/Feature/TripEditKmRecalculationTest.php tests/Feature/TripResourceTest.php` → all pass.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Filament/Resources/Trips/Schemas/TripForm.php tests/Feature/TripEditKmRecalculationTest.php
git commit -m "feat(trips): reject checkpoint km going backwards in time on trip edit"
```

---

### Task 4: Thông báo — trừ tài xế, tìm chính xác theo báo cáo

**Files:**
- Modify: `app/Observers/TripObserver.php`
- Test: `tests/Feature/TripKmReportTest.php`

- [ ] **Step 1: Viết test đỏ** — thêm vào `TripKmReportTest.php` (dùng setup sẵn có của file; đảm bảo có ít nhất 1 user điều hành **không** có role driver và 1 tài xế):

```php
test('trip notifications go to every user except drivers', function () {
    $dispatcher = User::factory()->create();

    app(TripObserver::class)->notifyUsersTripKmReported($this->trip);

    expect(DB::table('notifications')->where('notifiable_id', $dispatcher->id)->count())->toBe(1)
        ->and(DB::table('notifications')->whereIn('notifiable_id', User::role('driver')->pluck('id'))->count())->toBe(0);
});

test('resolving a report only clears notifications of that report', function () {
    // hai chuyến có id dễ trùng chuỗi: chuyến hiện tại và một chuyến có id chứa id hiện tại (vd 1 và 12, hoặc dựng tới khi id2 bắt đầu bằng id1)
    // tạo báo cáo chờ cho cả hai, gửi thông báo cho cả hai, xử lý báo cáo chuyến 1
    // expect: thông báo chuyến 2 vẫn còn actions và không có "[Đã"
});
```

Viết đầy đủ test thứ hai: tạo thêm chuyến cho tới khi `str_starts_with((string) $other->id, (string) $this->trip->id)` (vòng lặp tạo Trip tối đa 20 lần), tạo `TripKmReport` pending cho cả hai (observer tự gửi thông báo), gọi `app(TripKmAdjustmentService::class)->rejectReport($report1, 'x', null)`, rồi kiểm tra mọi thông báo có `tableActionRecord={$other->id}&` vẫn còn `actions` khác rỗng và `data` không chứa `[Đã`.

- [ ] **Step 2: Chạy, xác nhận đỏ** — test 1 FAIL (tài xế nhận thông báo), test 2 FAIL (thông báo chuyến khác bị gỡ).

- [ ] **Step 3: Sửa `TripObserver`**

Trong `sendTripNotification()` thay `$users = User::all();` bằng:

```php
$users = User::query()
    ->whereDoesntHave('roles', fn ($query) => $query->where('name', 'driver'))
    ->get();
```

và sau khi tạo `$notification`, nếu `$content` có `viewData`:

```php
if (! empty($content['viewData'])) {
    $notification->viewData($content['viewData']);
}
```

Cập nhật PHPDoc `@param callable(Trip): array{title: string, body: string, icon: string, status: string, actions?: array, viewData?: array}`.

Trong `notifyUsersTripKmReported()` thêm vào mảng trả về: `'viewData' => ['km_report_id' => $report?->id, 'trip_id' => $trip->id],`.

Tách truy vấn tìm thông báo ra method dùng chung và thay khối `where(...)` cũ trong `clearKmReportNotificationActions()`:

```php
/**
 * Thông báo báo sai km của đúng báo cáo (viewData.km_report_id), hoặc thông báo cũ chưa có viewData của đúng chuyến.
 */
private function kmReportNotifications(Trip $trip, ?TripKmReport $report): Collection
{
    $tripId = (int) $trip->getKey();

    return DB::table('notifications')
        ->where('data', 'like', '%resolve_km_report%')
        ->where(function ($query) use ($tripId, $report) {
            if ($report !== null) {
                $query->orWhere('data->viewData->km_report_id', $report->id);
            }

            $query->orWhere(fn ($legacy) => $legacy
                ->whereNull('data->viewData->km_report_id')
                ->where('data', 'like', "%tableActionRecord={$tripId}&%"));
        })
        ->get();
}
```

(`use Illuminate\Support\Collection;`). URL thông báo có dạng `?tableAction=resolve_km_report&tableActionRecord={id}&tripSearch=...` nên mẫu `tableActionRecord={id}&` khớp chính xác một chuyến.

- [ ] **Step 4: Chạy test** — `vendor/bin/pest tests/Feature/TripKmReportTest.php tests/Feature/TripDeliveredNotificationTest.php tests/Feature/DriverFcmNotificationTest.php` → all pass. Test cũ nào gửi thông báo cho user là tài xế rồi assert có thông báo → đổi người nhận sang user không có role driver, ghi vào report.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Observers/TripObserver.php tests/Feature
git commit -m "fix(trips): km report notifications skip drivers and clear only their own report"
```

---

### Task 5: Báo lại khi còn chờ — ghi đè, giữ lịch sử, cập nhật thông báo cũ

**Files:**
- Modify: `app/Http/Controllers/Api/TripKmReportController.php` (khối `if ($existingPending)`)
- Modify: `app/Observers/TripKmReportObserver.php` (`updated`)
- Modify: `app/Observers/TripObserver.php` (thêm `refreshKmReportNotifications`)
- Test: `tests/Feature/TripKmReportTest.php`

- [ ] **Step 1: Viết test đỏ** — mở rộng/viết mới cạnh test `submitting again while pending updates existing pending report` (dòng ~264):

```php
test('reporting again while pending keeps one report, records the previous value and does not send new notifications', function () {
    $dispatcher = User::factory()->create();
    Sanctum::actingAs($this->driver);

    $this->postJson("/api/driver/trips/{$this->trip->id}/km-report", ['reported_km' => 10500, 'note' => 'lần 1'])->assertCreated();
    $countAfterFirst = DB::table('notifications')->where('notifiable_id', $dispatcher->id)->count();

    $this->postJson("/api/driver/trips/{$this->trip->id}/km-report", ['reported_km' => 10550, 'note' => 'lần 2'])->assertCreated();

    $report = TripKmReport::where('trip_id', $this->trip->id)->sole();
    $notification = DB::table('notifications')->where('notifiable_id', $dispatcher->id)->latest('created_at')->first();

    expect((float) $report->reported_km)->toBe(10550.0)
        ->and($report->note)->toContain('lần 2')
        ->and($report->note)->toContain('10.500')
        ->and(DB::table('notifications')->where('notifiable_id', $dispatcher->id)->count())->toBe($countAfterFirst)
        ->and($notification->data)->toContain('10.550')
        ->and($notification->read_at)->toBeNull();
});
```

(Kiểm tra route thật của API báo sai km bằng `php artisan route:list --path=km` trước khi viết URL; dùng đúng route đó.)

- [ ] **Step 2: Chạy, xác nhận đỏ** — FAIL (số thông báo tăng, ghi chú không có lần trước).

- [ ] **Step 3: Controller** — trong khối `if ($existingPending)`, ghép ghi chú:

```php
$previous = sprintf(
    'Lần báo trước (%s): %s km%s',
    $existingPending->updated_at?->format('H:i d/m') ?? '',
    number_format((float) $existingPending->reported_km, 1, ',', '.'),
    filled($existingPending->note) ? ' — '.$existingPending->note : '',
);

$existingPending->update([
    // ... các field hiện có giữ nguyên ...
    'note' => trim(($request->validated('note') ?? '')."\n".$previous),
]);
```

(`paused_reason` vẫn dùng ghi chú mới của request như hiện tại.)

- [ ] **Step 4: Observer** — trong `TripKmReportObserver::updated()` thay lời gọi `$this->notify($report)` (khi `reported_km` đổi và còn Pending) bằng:

```php
$report->loadMissing(['trip', 'driver', 'vehicle']);

if ($report->trip) {
    app(TripObserver::class)->refreshKmReportNotifications($report->trip, $report);
}
```

- [ ] **Step 5: `TripObserver::refreshKmReportNotifications()`** — tách phần dựng `body` của `notifyUsersTripKmReported()` thành `private function kmReportBody(Trip $trip, ?TripKmReport $report): string` (dùng chung), rồi:

```php
/**
 * Tài xế báo lại khi báo cáo còn chờ: cập nhật nội dung thông báo cũ và đánh dấu chưa đọc thay vì gửi thông báo mới.
 */
public function refreshKmReportNotifications(Trip $trip, TripKmReport $report): void
{
    $body = $this->kmReportBody($trip, $report).' (báo lại lúc '.now()->format('H:i').')';

    foreach ($this->kmReportNotifications($trip, $report) as $notificationRecord) {
        $data = json_decode($notificationRecord->data, true);

        if (! is_array($data)) {
            continue;
        }

        $data['body'] = $body;

        DB::table('notifications')->where('id', $notificationRecord->id)->update([
            'data' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'read_at' => null,
            'updated_at' => now(),
        ]);
    }
}
```

- [ ] **Step 6: Chạy test** — `vendor/bin/pest tests/Feature/TripKmReportTest.php tests/Feature/TripResourceTest.php` → all pass.

- [ ] **Step 7: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/Api/TripKmReportController.php app/Observers tests/Feature/TripKmReportTest.php
git commit -m "feat(trips): re-reporting km keeps one report and refreshes its notification"
```

---

## Kiểm tra cuối

- [ ] `vendor/bin/pest tests/Feature/TripEditKmRecalculationTest.php tests/Feature/TripKmReportTest.php tests/Feature/TripResourceTest.php tests/Feature/TripWebCompletionKmTest.php tests/Feature/TripMultiDriverSwapKmTest.php tests/Feature/ReassignDriverHandoverTest.php tests/Feature/ExternalVehicleDriverAssignmentTest.php tests/Feature/TripDeliveredNotificationTest.php tests/Feature/DriverFcmNotificationTest.php`
- [ ] Toàn bộ: `php -d memory_limit=1G vendor/bin/pest` (2 test `CustomerLocationSeederTest`, `SyncEmployeeCodeTest` đã fail sẵn trên main).

## Ngoài phạm vi

- Dọn 61.587 thông báo cũ đã gửi cho tài xế (lệnh dọn riêng, cần duyệt vì xoá dữ liệu).
- Bỏ hẳn cột `is_paused` (suy ra từ báo cáo chờ).
- Trạng thái chuyến tự suy ra từ mốc (state machine) khi điều hành thêm mốc Hoàn thành.
- 7 chuyến thật có `start_km` ≠ mốc đầu, 2 chuyến `end_km` ≠ mốc cuối: sẽ tự về đúng mốc khi có người sửa km chuyến đó; muốn sửa đồng loạt cần lệnh riêng.
