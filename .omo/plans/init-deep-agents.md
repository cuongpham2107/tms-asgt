# /init-deep — AGENTS.md Generation Plan

**Generated:** 2026-07-15
**Mode:** Update (modify existing + create new where warranted)

## Summary

Root `AGENTS.md` already exists (364 lines, comprehensive). This plan:
1. **UPDATES** root `AGENTS.md` with a WHERE TO LOOK section and minor architecture clarifications
2. **CREATES** 4 new subdirectory `AGENTS.md` files

## AGENTS.md Locations

| # | Path | Action | Score | Reason |
|---|------|--------|-------|--------|
| 1 | `./AGENTS.md` | UPDATE | root | Add WHERE TO LOOK + Services mention |
| 2 | `app/Filament/AGENTS.md` | CREATE | 22 | Custom architecture, 12 resources, 6 components |
| 3 | `app/Services/Trip/AGENTS.md` | CREATE | 16 | Handler pattern, marker interface, 14 files |
| 4 | `tests/AGENTS.md` | CREATE | 14 | 23 test files, unique conventions |
| 5 | `app/Enums/AGENTS.md` | CREATE | 12 | 19 domain enums, type system |

---

## File 1: UPDATE `./AGENTS.md`

**Action:** Insert a WHERE TO LOOK section after STRUCTURE block (around line 94, before CODE MAP or CONVENTIONS). Also add `app/Services/Trip/` mention in Architecture section.

### Content to INSERT (after the STRUCTURE block):

```markdown
## WHERE TO LOOK

| Task | Location | Notes |
|------|----------|-------|
| Filament resources | `app/Filament/Resources/{Name}/` | Each has `Tables/`, `Schemas/`, `Pages/` |
| Custom form components | `app/Filament/Forms/Components/` | VehiclePicker, PillFilter, MapboxLocationPicker, CardPicker |
| Dashboard widgets | `app/Filament/Widgets/` | Charts, stats, map |
| Trip checkpoint flow | `app/Services/Trip/TripCheckpointService.php` | Handler pattern with `CheckpointFactory` |
| API controllers | `app/Http/Controllers/Api/` | 11 controllers, Sanctum-auth driver endpoints |
| Domain enums | `app/Enums/` | 19 backed enums |
| Database schema docs | `database/SCHEMA_REFERENCE.md` | 19 tables with column details |
| Tests | `tests/Feature/` | Pest v4, per-file RefreshDatabase |
| Mobile app | `mobile/` | Expo React Native driver app |
```

### Content to ADD in Architecture section (after "Trip checkpoint system" bullet):

```markdown
  - Handler classes implement marker interface `CheckpointHandlerInterface` (no enforced signature — each handler receives only params it needs).
  - Supporting services: `DeliveryPointResolver`, `TripPhotoAttacher`, `VehicleUpdater`, `TripShiftResolver`, `DeliveryPointStatusUpdater`.
```

---

## File 2: CREATE `app/Filament/AGENTS.md`

```markdown
# Filament Admin Panel

**Panel ID:** `app` (path `/app`) — single panel, no multi-tenancy.

## STRUCTURE

```
app/Filament/
├── BaseResource.php          # Soft-delete route binding (all resources extend this)
├── BaseTable.php             # applyDefaults() — bulk/record actions + empty state
├── Forms/Components/         # 6 custom components (VehiclePicker, PillFilter, MapboxLocationPicker, etc.)
├── Pages/                    # 3 non-resource pages
├── Resources/                # 12 resources (each: Resource + Tables/ + Schemas/ + Pages/)
├── Tables/Columns/           # UniqueMapColumn
├── Traits/                   # InteractsWithPageTable
└── Widgets/                  # 9 dashboard widgets
```

## WHERE TO LOOK

| Task | Location | Notes |
|------|----------|-------|
| Resource CRUD | `Resources/{Plural}/` | Extends `BaseResource`, delegates to `Tables/{X}Table`, `Schemas/{X}Form` |
| Custom actions | `Resources/{Plural}/Actions/` | Orders has 11, Trips has 6+ |
| Custom form fields | `Forms/Components/` | Check here before building new ones |
| Dashboard widgets | `Widgets/` | Charts, stats, maps |
| Live tracking map | `Pages/GoogleMapTracking.php` | 750+ lines, Leaflet, vehicle playback |
| Driver duty report | `Pages/DriverDutyReport.php` | Shift summary with pill filters |

## CONVENTIONS

- **Every resource** → `BaseResource` (except UserResource → `Resource` for Shield compat)
- **Every table** → `BaseTable`, calls `parent::applyDefaults()` BEFORE columns
- **Forms separate** → `Schemas/{Name}Form::configure(Schema $schema)` — static method
- **Schema namespace** → `Filament\Schemas\Schema` (NOT `Filament\Forms` from v3)
- **Soft-delete binding** → handled by `BaseResource::getRecordRouteBindingEloquentQuery()`
- **Navigation groups** (Vietnamese): Tổng quan, Vận hành, Quản lý, Hoạt động, Cấu hình, Bảo dưỡng
- **`#[Url]` filters** → call `$this->resetPage()` on change (see ListTrips, ListOrders)
- **Mapbox CDN** → injected via render hooks in `AppPanelProvider`

## ANTI-PATTERNS

- Don't use `Filament\Forms` namespace for schema — use `Filament\Schemas\Schema` (v5 breaking change)
- Don't call columns/actions before `applyDefaults()` in table classes
- Don't create new custom form components without checking `Forms/Components/` first

## PLUGINS

| Plugin | Config | Purpose |
|--------|--------|---------|
| `FilamentShieldPlugin` | `config/filament-shield.php` | RBAC, auto-generated policies |
| `FilamentFullCalendarPlugin` | selectable + editable | Calendar view for trips/maintenance |
```

---

## File 3: CREATE `app/Services/Trip/AGENTS.md`

```markdown
# Trip Checkpoint Sub-System

Handler pattern: `TripCheckpointService` orchestrates → `CheckpointFactory` creates → dispatch to individual handlers.

## STRUCTURE

```
app/Services/Trip/
├── TripCheckpointService.php     # Entry point — recordCheckpoint()
├── CheckpointFactory.php         # Creates TripCheckpoint records (handles dedup)
├── DeliveryPointResolver.php     # Resolves delivery points from payload
├── DeliveryPointStatusUpdater.php
├── TripPhotoAttacher.php         # Attaches uploaded photos to checkpoints
├── TripShiftResolver.php         # Resolves active shift for trip
├── VehicleUpdater.php            # Updates vehicle mileage from checkpoints
└── Handlers/
    ├── CheckpointHandlerInterface.php   # MARKER INTERFACE — no enforced signature
    ├── StartedHandler.php               # Trip start, shift linking, vehicle km
    ├── ArrivedPickupHandler.php         # Updates sent orders → in_transit
    ├── LeftPickupHandler.php            # Confirms pickup departure
    ├── ArrivedDeliveryHandler.php       # Delivery point status update
    ├── CompletedHandler.php             # Order completion, trip completion check
    ├── EndHandler.php                   # Trip end, order finalization
    └── CheckpointEndHandler.php         # Shift-level checkpoint end
```

## WHERE TO LOOK

| Task | Location | Notes |
|------|----------|-------|
| Add new checkpoint type | `Handlers/` + `CheckpointType` enum + `dispatchHandler()` match | 3 places to touch |
| Change checkpoint flow | `dispatchHandler()` in TripCheckpointService | Match expression dispatches by type |
| Dedup logic | `CheckpointFactory::create()` | Checks existing checkpoint at same location |
| Auto-start trip | `autoStartTrip()` in TripCheckpointService | Fires when first checkpoint != Started on pending trip |
| KM calculation | `app/Services/TripKmCalculatorService.php` | Separate from checkpoint flow |

## CONVENTIONS

- **Marker interface** `CheckpointHandlerInterface` — no enforced signature. Each handler receives only the params it needs via `match` dispatch.
- **Return trip handling** → `recordCheckpoint()` short-circuits for ReturnTrip + Started/End (updates existing km_reading, does not create new)
- **Validation** → `validateOrderBelongsToTrip()` + `validateNoActiveTrip()` called before transaction
- **All checkpoint mutations in DB transaction** → `DB::transaction(fn () => ...)`
- **Shift KM recalculated** after every checkpoint via `app(ShiftKmCalculatorService::class)->calculate()`

## GOTCHAS

- Handler interface is a marker — no method contract. Read each handler to understand its params.
- Enum `->value` required on relation updates (e.g., `$trip->orders()->where('status', OrderStatus::Sent->value)`)
- Auto-start uses vehicle's `current_mileage` — rejects with error if null
```

---

## File 4: CREATE `tests/AGENTS.md`

```markdown
# Test Suite

Pest v4, ~23 files, 140+ test functions. SQLite `:memory:`, per-file RefreshDatabase.

## STRUCTURE

```
tests/
├── Feature/          # 22 feature tests (flat — no subdirectories)
├── Unit/             # 1 placeholder (ExampleTest)
├── Pest.php          # Binds to TestCase, RefreshDatabase commented out globally
└── TestCase.php      # Force :memory: DB, no global RefreshDatabase
```

## CONVENTIONS

- **`uses(RefreshDatabase::class)`** — per file, NOT global. Every feature test opts in explicitly.
- **`beforeEach()` setup** → `Role::create()` + `Model::create()` for reference data (not factories)
- **Factories** → only 3 exist: `UserFactory`, `VehicleFactory`, `TripFactory`. All other models use `Model::create()` directly.
- **API auth** → `Sanctum::actingAs($driver)` (requires `driver` role assigned via `->assignRole()`)
- **Filament auth** → `$this->actingAs($admin)` (session-based)
- **Enum queries** → `->value` on writes, auto-cast on reads
- **Declare test** → `test('description', fn () => ...)` — also some `it()` usage (no enforced convention)
- **Helpers** → file-level PHP functions (NOT shared, NOT in Pest.php). Naming: `fw*(...)`, `end*(...)`

## WHERE TO LOOK

| Domain | File | Tests |
|--------|------|-------|
| Checkpoint lifecycle | `TripCheckpointTest.php` | 23 — all types, validation, dedup, photos |
| KM calculation | `TripKmTest.php` + `FullWorkflowTest.php` | 7+7 — unit + E2E scenarios |
| Order flow | `OrderFullFlowTest.php` | 3 — swap/no-swap KM split |
| Order cancel | `OrderCancelTest.php` | 4 — trip cleanup on cancel |
| Trip API | `TripHistoryTest.php` | 7 — pagination, filters, auth |
| Filament resources | `TripResourceTest.php`, `UserResourceTest.php` | Livewire `::test()` + `::mountTableAction()` |
| Dashboard | `DashboardWidgetsTest.php` | 4 — widget render + filter toggle |

## ANTI-PATTERNS

- Don't put `RefreshDatabase` in `Pest.php` — keep it per-file
- Don't create factories for every model — `Model::create()` is the convention
- Don't use PHPUnit `TestCase` directly — extend `Tests\TestCase` via Pest bind
- Don't share helper functions across test files — each file defines its own

## COMMANDS

```bash
php artisan test --compact                    # All tests
php artisan test --compact --filter=TestName  # Specific test
php artisan make:test --pest NewFeatureTest   # Create new test
```
```

---

## File 5: CREATE `app/Enums/AGENTS.md`

```markdown
# Domain Enums

19 backed enums — the type system for the domain model. All live flat in `app/Enums/`.

## ENUM INDEX

| Enum | Backed By | Purpose |
|------|-----------|---------|
| `CargoType` | string | Hàng hóa / Vật liệu / Khác |
| `CheckpointType` | string | Trip checkpoint states (Started → ArrivedPickup → ... → Completed/End) |
| `DriverSwapReason` | string | Lý do đổi tài xế (Sick, Personal, etc.) |
| `LocationType` | string | Warehouse / Customer / Supplier |
| `MaintenanceJobStatus` | string | Scheduled → InProgress → Completed |
| `MaintenanceJobType` | string | Bảo dưỡng định kỳ / Sửa chữa / etc. |
| `MaintenanceTriggerType` | string | Time / Km / TimeOrKm |
| `OnDutyLocation` | string | InOffice / Mobile / OnLeave |
| `OrderDeliveryPointStatus` | string | Pending → InTransit → Delivered |
| `OrderStatus` | string | Draft → Sent → InTransit → Delivered / Completed / Cancelled |
| `OrderType` | string | Hhhk / Chuyến / Nội bộ |
| `Priority` | string | Low / Medium / High / Urgent |
| `ShiftType` | string | Ca sáng / Ca chiều / Ca tối |
| `TripStatus` | string | Pending → Started → InTransit → Completed / DriverSwap / ReturnTrip |
| `VehicleDocumentStatus` | string | Active / Expired / Pending |
| `VehicleDocumentType` | string | Đăng kiểm / Bảo hiểm / etc. |
| `VehicleOwnerType` | string | Company / Rent / Partner |
| `VehicleStatus` | string | Available / InUse / Maintenance / Retired |
| `VehicleType` | string | Truck / Van / Container / etc. |

## CONVENTIONS

- All enums use **TitleCase keys** (e.g., `ArrivedPickup`, not `arrived_pickup`)
- **`->value` required on relation queries** → `$trip->orders()->where('status', OrderStatus::Sent->value)`
- **Auto-cast on model reads** → `$order->status` returns enum instance
- **`->label()` method** → returns Vietnamese display text

## GOTCHAS

- Adding new enum values → SQLite CHECK constraint needs `->change()` migration
- Relation updates bypass Eloquent casting → always use `->value` for writes via relations
- `CheckpointType` drives `dispatchHandler()` match — adding a type without a handler breaks at runtime
```

---

## Execution Order

1. Update root `./AGENTS.md` (insert WHERE TO LOOK + Architecture addition)
2. Create `app/Filament/AGENTS.md`
3. Create `app/Services/Trip/AGENTS.md`  
4. Create `tests/AGENTS.md`
5. Create `app/Enums/AGENTS.md`

## Post-Execution

- Verify all files are 30-80 lines (except root which stays at ~380 with additions)
- Ensure no parent content is duplicated in child files
- Verify telegraphic style — no generic advice
