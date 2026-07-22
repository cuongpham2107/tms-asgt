# remove-empty-kilometers - Work Plan

## TL;DR (For humans)

**What:** Xóa bảng `empty_kilometers` (0 rows) và toàn bộ dead code liên quan. Thêm `is_empty_run` + `note` vào `trips`. Tạo Filament action mới "Tạo chuyến không hàng" để điều hành tạo trip rỗng với 2 checkpoint Started/End có sẵn. Mobile hiển thị badge "chuyến không hàng" và driver chỉ cần update checkpoint đã tạo.

**Why this approach:** KM calculation đã xử lý đúng trip không có order (total_km_empty = total_km). Không cần bảng riêng — trip + checkpoint có sẵn đáp ứng đủ. CheckpointFactory bị bypass (tạo checkpoint trực tiếp trong action, không qua factory) vì factory lặp qua orders — trip rỗng không có order.

**What it will NOT do:** Không tạo OrderDeliveryPoint (dùng location trực tiếp). Không sửa TripCheckpointService (ReturnTrip guard đã có sẵn). Không viết test mới. Không thay đổi KM calculation services.

**Effort:** 3 waves, ~14 files touched. Wave 1: cleanup dead code. Wave 2: schema + actions + mobile. Wave 3: verify.

**Risk:** Thấp — bảng rỗng, mobile không gọi API này, KM services đã verified.

**Decisions:** is_empty_run boolean + ReturnTrip status (cả hai cùng tồn tại để filter UI). Location trực tiếp, không qua delivery_point. Không test mới.

## Scope

### IN

| # | Component | Files | Mô tả |
|---|-----------|-------|-------|
| 1 | Xóa dead code EmptyKilometer | 7 files | Model, Controller, Resource, Policy, Route, demo script, migration (mới để drop) |
| 2 | Schema trips | 2 files | Migration thêm `is_empty_run` + `note`, cập nhật Trip model casts |
| 3 | CreateEmptyRunAction | 1 file | HeaderAction mới trên ListTrips — form: xe, tài xế, điểm đi, điểm đến, ghi chú |
| 4 | ListTrips headerAction | 1 file | Gắn CreateEmptyRunAction vào header |
| 5 | ReassignDriverAction | 1 file | create_return_trip checkbox → thêm is_empty_run=true, đồng bộ logic |
| 6 | Mobile trip-detail | 1 file | Badge "Chuyến không hàng" + driver update checkpoint có sẵn |
| 7 | Shield cleanup | CLI | `php artisan shield:generate --all --ignore-config-changes` |
| 8 | Verify | CLI | Chạy toàn bộ test suite |

### OUT (Must-NOT-Have)

- ❌ Không tạo OrderDeliveryPoint cho trip rỗng
- ❌ Không tạo order giả
- ❌ Không sửa CheckpointFactory (bypass hoàn toàn — checkpoint tạo trực tiếp)
- ❌ Không sửa TripCheckpointService.recordCheckpoint() (ReturnTrip guard đã xử lý)
- ❌ Không sửa CheckpointEndHandler (early-return cho trip không order là đúng)
- ❌ Không sửa TripKmCalculatorService / ShiftKmCalculatorService
- ❌ Không xóa migration gốc `2026_05_21_152105` (giữ lại, tạo migration mới để drop)
- ❌ Không viết test mới
- ❌ Không đổi prefix trip code

## Verification strategy

**Tests-after** (user choice). Agent-executed QA cho từng todo. Chạy toàn bộ test suite ở wave cuối.

## Execution strategy

3 waves, sequential (wave sau phụ thuộc wave trước):

- **Wave 1:** Remove dead code — độc lập, không phụ thuộc gì
- **Wave 2:** Schema + Actions + Mobile — phụ thuộc Wave 1 (clean state)
- **Wave 3:** Verify — phụ thuộc Wave 2

## Todos

- [x] 1. Create migration to drop empty_kilometers table
- [x] 2. Delete 4 EmptyKilometer files (model, controller, resource, policy)
- [x] 3. Remove route from api.php
- [x] 4. Update demo script
- [x] 5. Migration: add is_empty_run + note to trips
- [x] 6. Update Trip model ($fillable + casts)
- [x] 7. Create CreateEmptyRunAction
- [x] 8. Add headerAction to ListTrips
- [x] 9. Update ReassignDriverAction
- [x] 10. Update mobile trip-detail (badge)
- [x] 11. Filament Shield cleanup
- [x] 12. Run full test suite + fix TripCheckpointTest
- [x] 13. Final verification wave (all F1-F4 pass)

### Details

#### T1.1: Create migration to drop empty_kilometers table
- **References:** `database/migrations/2026_05_21_152105_create_empty_kilometers_table.php` (original — KEEP, do not delete)
- **Acceptance:** `php artisan make:migration drop_empty_kilometers_table` → `Schema::dropIfExists('empty_kilometers')` in `up()`, recreate in `down()` by copy-pasting original migration's `up()` logic
- **QA happy:** `php artisan migrate` → table gone. `php artisan migrate:rollback` → table restored.
- **QA failure:** `php artisan migrate` fails → verify migration order. Original migration still exists and runs first, then drop migration runs.
- **Commit:** `feat: add migration to drop empty_kilometers table`

#### T1.2: Delete EmptyKilometer model
- **References:** `app/Models/EmptyKilometer.php`
- **Acceptance:** File deleted. `grep -r "EmptyKilometer" app/` returns no references from other app files (except those being deleted in other todos).
- **QA happy:** `php artisan tinker --execute 'class_exists("App\\Models\\EmptyKilometer")'` → false
- **QA failure:** ClassNotFoundException if any remaining code references it → fix before proceeding
- **Commit:** `feat: remove EmptyKilometer model`

#### T1.3: Delete EmptyKilometerController
- **References:** `app/Http/Controllers/Api/EmptyKilometerController.php`
- **Acceptance:** File deleted.
- **QA happy:** File not found at path.
- **QA failure:** N/A (simple delete)
- **Commit:** combined with T1.4, T1.5 below

#### T1.4: Delete EmptyKilometerResource
- **References:** `app/Http/Resources/EmptyKilometerResource.php`
- **Acceptance:** File deleted.
- **QA happy:** File not found.
- **QA failure:** N/A
- **Commit:** `feat: remove EmptyKilometer controller, resource, policy`

#### T1.5: Delete EmptyKilometerPolicy
- **References:** `app/Policies/EmptyKilometerPolicy.php`
- **Acceptance:** File deleted.
- **QA happy:** File not found.
- **QA failure:** N/A
- **Commit:** combined with T1.3, T1.4

#### T1.6: Remove route + import from api.php
- **References:** `routes/api.php` lines 5 (import) and 58 (route)
- **Acceptance:** Remove `use App\Http\Controllers\Api\EmptyKilometerController;` (line 5). Remove `Route::post('/empty-kilometers', [EmptyKilometerController::class, 'store']);` (line 58).
- **QA happy:** `php artisan route:list --path=api` → no `/empty-kilometers` route
- **QA failure:** PHP error on route:list → check for stray references
- **Commit:** `feat: remove empty-kilometers API route`

#### T1.7: Update demo script
- **References:** `database/scripts/demo-delivery-point-selection.php` line 224: `DB::table("empty_kilometers")->whereIn("shift_id",$shiftIds)->delete();`
- **Acceptance:** Remove line 224 (or wrap in `if (Schema::hasTable('empty_kilometers'))` for safety). Since table is dropped, just delete the line.
- **QA happy:** Script runs without error referencing empty_kilometers.
- **QA failure:** Script references empty_kilometers elsewhere → grep for any missed references
- **Commit:** `feat: remove empty_kilometers reference from demo script`

### Wave 2: Schema + Actions + Mobile

#### T2.1: Migration: add is_empty_run + note to trips
- **References:** `database/migrations/` (existing trips migration), `app/Models/Trip.php` (existing columns)
- **Acceptance:** 
  - `php artisan make:migration add_is_empty_run_and_note_to_trips_table`
  - `$table->boolean('is_empty_run')->default(false)->after('status')->comment('Chuyến không hàng');`
  - `$table->text('note')->nullable()->after('is_empty_run')->comment('Ghi chú chuyến đi');`
  - `down()`: `$table->dropColumn(['is_empty_run', 'note']);`
- **QA happy:** `php artisan migrate` → columns exist. `php artisan migrate:rollback` → columns gone.
- **QA failure:** Migration order conflict → ensure migration timestamp is after all existing migrations.
- **Commit:** `feat: add is_empty_run and note columns to trips table`

#### T2.2: Update Trip model
- **References:** `app/Models/Trip.php` — add `is_empty_run` and `note` to `$fillable`, add cast for `is_empty_run` (boolean)
- **Acceptance:** 
  - `$fillable` includes `'is_empty_run', 'note'`
  - `casts()` returns `'is_empty_run' => 'boolean'`
- **QA happy:** `php artisan tinker --execute 'App\Models\Trip::first()?->is_empty_run'` → returns boolean (false for existing trips)
- **QA failure:** SQL error → verify column exists (run migration first)
- **Commit:** `feat: add is_empty_run and note to Trip model`

#### T2.3: Create CreateEmptyRunAction
- **References:** `app/Filament/Resources/Trips/Actions/ReassignDriverAction.php` (follow same pattern for trip+checkpoint creation), `app/Models/Location.php` (for location select options)
- **Acceptance:** File: `app/Filament/Resources/Trips/Actions/CreateEmptyRunAction.php`
  - Action label: "Tạo chuyến không hàng"
  - Icon: `heroicon-o-truck`
  - Color: `success`
  - Form fields (4 required + 1 optional):
    - `Select::make('vehicle_id')` → label "Xe" → options from `Vehicle::where('is_active', true)`
    - `Select::make('driver_id')` → label "Tài xế" → options from `User::where('is_active', true)` (filter/hint active drivers like ReassignDriverAction)
    - `Select::make('start_location_id')` → label "Điểm đi" → options from `Location::where('is_active', true)`
    - `Select::make('end_location_id')` → label "Điểm đến" → options from `Location::where('is_active', true)`
    - `Textarea::make('note')` → label "Ghi chú" → optional
  - Action handler:
    1. Validate vehicle_id, driver_id, start_location_id, end_location_id
    2. Resolve driver's active shift (pattern: `DriverShift::where('driver_id', ...)->whereNull('end_time')->first()`)
    3. Create Trip: `status = TripStatus::ReturnTrip`, `is_empty_run = true`, `start_location_id`, `end_location_id`, `note`, `start_km = vehicle->current_mileage`, `started_at = now()`
    4. Create Started checkpoint: `order_id = null`, `km_reading = vehicle->current_mileage`, `occurred_at = now()`
    5. Create End checkpoint: `order_id = null`, `km_reading = null`, `occurred_at = now()->addSecond()`
    6. Success notification with trip_code
  - **CRITICAL:** Checkpoints created with `order_id = null` — this is the key difference from normal trips. The existing `TripCheckpointService.recordCheckpoint()` ReturnTrip guard (lines 49-61) finds these pre-created checkpoints by `trip_id + checkpoint_type` and updates them.
- **QA happy:** Click action → form opens with 4 selects + textarea → fill all → submit → trip created with ReturnTrip status + is_empty_run=true → 2 checkpoints exist with null order_id → success notification
- **QA failure:** Cannot find driver shift → gracefully handle null shift (still create trip, but shift_id=null). Cannot get vehicle mileage → still create, start_km=0.
- **Commit:** `feat: add CreateEmptyRunAction for empty-run trips`

#### T2.4: Add headerAction to ListTrips
- **References:** `app/Filament/Resources/Trips/Pages/ListTrips.php` — add `CreateEmptyRunAction::make()` to `getHeaderActions()`
- **Acceptance:** 
  - `use App\Filament\Resources\Trips\Actions\CreateEmptyRunAction;`
  - Add `CreateEmptyRunAction::make()` to header actions array
- **QA happy:** ListTrips page loads → "Tạo chuyến không hàng" button visible in header. Click → form opens.
- **QA failure:** Action not visible → check import and header action registration
- **Commit:** `feat: add CreateEmptyRunAction to ListTrips header`

#### T2.5: Update ReassignDriverAction
- **References:** `app/Filament/Resources/Trips/Actions/ReassignDriverAction.php` lines 229-263
- **Acceptance:** 
  - Line 235: add `'is_empty_run' => true` to Trip::create data
  - Line 241 (Started checkpoint): set `order_id => null` (already null implicitly, make explicit for clarity)
  - Line 253 (End checkpoint): set `order_id => null`
  - Verify `start_location_id` and `end_location_id` already set (lines 235-236)
- **QA happy:** Reassign driver with create_return_trip checked → new ReturnTrip created with is_empty_run=true → 2 checkpoints with null order_id
- **QA failure:** Existing behavior for create_return_trip breaks → verify the action still completes successfully
- **Commit:** `feat: set is_empty_run=true on ReassignDriverAction return trips`

#### T2.6: Update mobile trip-detail — empty-run indicator
- **References:** `mobile/app/trip-detail.tsx` — add badge/indicator for empty-run trips
- **Acceptance:**
  - Check `trip.is_empty_run === true` OR `trip.status === 'return_trip'`
  - Display badge "Chuyến không hàng" near trip header (violet/muted color, consistent with ListTrips pill filter)
  - Mobile already handles `return_trip` status with separate UI flow (lines 236-284 of trip-detail.tsx) — verify this flow still works and displays the badge
- **QA happy:** Open empty-run trip on mobile → badge visible. Driver can update Started and End checkpoints without issues.
- **QA failure:** Badge doesn't show → verify API response includes `is_empty_run` field (TripResource may need update)
- **Commit:** `feat: add empty-run badge to mobile trip detail`

#### T2.7: Run Filament Shield cleanup
- **References:** `app/Policies/` (EmptyKilometerPolicy deleted in T1.5)
- **Acceptance:**
  - Run `php artisan shield:generate --all --ignore-config-changes`
  - No errors related to missing EmptyKilometer model/policy
  - Verify `permissions` table no longer has EmptyKilometer entries: `php artisan tinker --execute 'Spatie\Permission\Models\Permission::where("name", "like", "%EmptyKilometer%")->count()'` → 0
- **QA happy:** Shield regenerates without errors. No orphan permissions.
- **QA failure:** Permission still exists → manually delete via tinker or run `shield:generate` again.
- **Commit:** combined with T2.6 or separate shield commit

### Wave 3: Final verification

#### T3.1: Run full test suite + verify
- **References:** `tests/Feature/` — all existing test files
- **Acceptance:**
  - `php artisan test --compact` → all tests pass
  - Key tests to verify specifically: `TripKmTest`, `TripResourceTest`, `FullWorkflowTest`, `TripCheckpointTest`, `OrderFullFlowTest`
  - `php artisan route:list --path=api` → no `/empty-kilometers` route
- **QA happy:** Zero test failures. No PHP errors on route list.
- **QA failure:** Any test fails → investigate and fix. Most likely culprit: TripResourceTest if it references deleted files → update test if needed.
- **Commit:** No commit needed (verification only)

## Final verification wave

Run in parallel after ALL todos complete. ALL must APPROVE.

| ID | Check | Method | Evidence |
|----|-------|--------|----------|
| F1 | Plan compliance | Diff todos against changed files | `git diff --stat` matches plan scope |
| F2 | Code quality | Pint + no PHP errors | `vendor/bin/pint --format agent` + `php artisan route:list` |
| F3 | Real manual QA | Create empty-run trip via UI | Screenshot: ListTrips headerAction → form → trip created with checkpoints |
| F4 | Scope fidelity | Grep for EmptyKilometer | `grep -r "EmptyKilometer\|empty.kilometer\|empty_kilometer" app/ routes/` → only migration file (original, kept) |

## Commit strategy

| Wave | Commits |
|------|---------|
| W1 | 3 commits: migration drop + model delete + controller/resource/policy/route/demo cleanup |
| W2 | 4 commits: trips migration + Trip model + CreateEmptyRunAction + ReassignAction/mobile/shield |
| W3 | No commit (verification only) |

## Success criteria

1. `grep -r "EmptyKilometer" app/Http/ app/Models/ app/Policies/ app/Http/ routes/` returns **zero results** (except original migration file)
2. `POST /api/driver/empty-kilometers` returns **404** (route removed)
3. ListTrips page có nút "Tạo chuyến không hàng" → tạo được trip với `is_empty_run=true`, `status=return_trip`, 2 checkpoints Started+End
4. ReassignDriverAction create_return_trip → trip mới có `is_empty_run=true`
5. Mobile hiển thị badge "Chuyến không hàng" trên trip detail
6. `php artisan test --compact` → **zero failures**
7. `php artisan shield:generate --all --ignore-config-changes` → **zero errors**
