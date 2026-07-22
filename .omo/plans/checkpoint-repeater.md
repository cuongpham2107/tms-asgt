# checkpoint-repeater - Work Plan

## TL;DR (For humans)

**What you'll get:** Trong form chi tiết chuyến đi (Trip), section "Các mốc hành trình" sẽ chuyển từ bảng tĩnh chỉ xem được sang bảng tương tác cho phép thêm checkpoint mới, sửa từng trường, và xoá checkpoint — tất cả lưu tự động khi bấm Save của form Filament.

**Why this approach:** Dùng `Repeater` với `->relationship('checkpoints')` để Filament tự động CRUD qua Eloquent, không cần code custom save. Hiển thị dạng bảng (`->table()`) cho gọn dù có nhiều trường.

**What it will NOT do:** Không thay đổi logic nghiệp vụ (CheckpointFactory, TripCheckpointService), không ảnh hưởng đến mobile API, không thêm migration mới.

**Effort:** Quick (3 files touched)
**Risk:** Low — chỉ thay đổi UI form, không đụng đến business logic
**Decisions to sanity-check:**
- `trip_id` bị LOẠI KHỎI Repeater (do `->relationship()` tự set)
- `driver_id`/`shift_id`/`vehicle_id` được auto-fill từ Trip cha, ẩn khỏi form
- Route bulk-update cũ bị xoá (không còn dùng)

Your next move: Start work (`/start-work`) hoặc review plan. Full execution detail follows below.

---

> TL;DR (machine): Quick, Low risk — replace Blade view with Filament Repeater using relationship('checkpoints'), table layout, addable+deletable, 2 cleanup items, no tests.

## Scope
### Must have
- Repeater trong TripForm section "Các mốc hành trình" dùng `->relationship('checkpoints')`
- Table layout với columns: Loại, Đơn hàng, Km, Giờ, Ghi chú
- Schema đầy đủ các field (trừ trip_id): checkpoint_type, order_id, delivery_point_id, occurred_at, km_reading, gps_lat, gps_lng, voice_note
- driver_id/shift_id/vehicle_id auto-fill từ Trip cha, ẩn (`->hidden()`)
- `->addable()` + `->deletable()` — thêm và xoá checkpoint
- Xoá route `POST /trips/{trip}/checkpoints/bulk-update` khỏi routes/web.php
- Xoá file Blade `grouped-checkpoints.blade.php`

### Must NOT have (guardrails, anti-slop, scope boundaries)
- KHÔNG expose `trip_id` trong Repeater (relationship tự set)
- KHÔNG expose `created_at` (auto timestamp)
- KHÔNG sửa CheckpointFactory, TripCheckpointService, TripCheckpoint model, CheckpointType enum, Trip model
- KHÔNG sửa mobile API hoặc bất kỳ API route nào
- KHÔNG thêm migration mới
- KHÔNG viết test (user tự test)

## Verification strategy
> Zero human intervention - all verification is agent-executed.
- Test decision: none (user self-tests) + Pest framework available
- Evidence: .omo/evidence/

## Execution strategy
### Parallel execution waves
Single wave — tất cả 3 todos độc lập, có thể làm song song.

### Dependency matrix
| Todo | Depends on | Blocks | Can parallelize with |
| --- | --- | --- | --- |
| T1 | none | none | T2, T3 |
| T2 | none | none | T1, T3 |
| T3 | none | none | T1, T2 |

## Todos

- [x] 1. app/Filament/Resources/Trips/Schemas/TripForm.php: Replace Section custom view with Repeater using relationship('checkpoints')
  What to do: Thay dòng 131-137 (Section::make('Các mốc hành trình')...) bằng Repeater.
  Must NOT do: KHÔNG expose trip_id, KHÔNG xoá các section khác (Thông tin chuyến, Km & Thời gian), KHÔNG sửa các field không liên quan.

  Field-to-component mapping:
  - checkpoint_type: `Select::make('checkpoint_type')->options(CheckpointType::class)->required()->native(false)`
  - order_id: `Select::make('order_id')->relationship('order', 'order_code', modifyQueryUsing: fn($q, $get) => $q->where('trip_id', $get('../../id')))->searchable()->native(false)`
  - delivery_point_id: `Select::make('delivery_point_id')->relationship('deliveryPoint', 'address', modifyQueryUsing: fn($q, $get) => $q->where('order_id', $get('order_id')))->searchable()->native(false)->nullable()`
  - occurred_at: `DateTimePicker::make('occurred_at')->required()->displayFormat('H:i d/m/Y')->seconds(false)->native(true)`
  - km_reading: `TextInput::make('km_reading')->numeric()->step(0.1)->nullable()`
  - gps_lat: `TextInput::make('gps_lat')->numeric()->step(0.0000001)->nullable()`
  - gps_lng: `TextInput::make('gps_lng')->numeric()->step(0.0000001)->nullable()`
  - voice_note: `TextInput::make('voice_note')->nullable()`
  - driver_id: `Select::make('driver_id')->relationship('driver', 'name')->hidden()->default(fn($get) => $get('../../driver_id'))`
  - shift_id: `Select::make('shift_id')->relationship('shift', 'id')->hidden()->default(fn($get) => $get('../../shift_id'))`
  - vehicle_id: `Select::make('vehicle_id')->relationship('vehicle', 'plate_number')->hidden()->default(fn($get) => $get('../../vehicle_id'))`

  Auto-fill context fields on create:
  ```php
  ->mutateRelationshipDataBeforeCreateUsing(function (array $data, Trip $record): array {
      $data['driver_id'] = $record->driver_id;
      $data['shift_id'] = $record->shift_id;
      $data['vehicle_id'] = $record->vehicle_id;
      return $data;
  })
  ```

  Table columns:
  ```php
  ->table([
      TableColumn::make('Loại')->width('150px'),
      TableColumn::make('Đơn hàng')->width('120px'),
      TableColumn::make('Km')->width('80px'),
      TableColumn::make('Giờ')->width('150px'),
      TableColumn::make('Ghi chú')->width('150px'),
  ])
  ```

  Remove import of `View` class (line 15) if no longer used. Keep `Repeater` and `TableColumn` imports (already present at lines 10-11).

  Parallelization: Wave 1 | Blocked by: none | Blocks: none
  References: app/Filament/Resources/Trips/Schemas/TripForm.php:1-140, app/Models/TripCheckpoint.php:1-70, app/Enums/CheckpointType.php:1-43
  Acceptance criteria:
  - Form renders Repeater instead of Blade view when editing a Trip
  - Add button creates a new empty checkpoint row
  - Delete button removes a checkpoint (soft? no — TripCheckpoint has no SoftDeletes, so hard delete)
  - Save lưu tất cả thay đổi (thêm/sửa/xoá) vào DB
  - driver_id/shift_id/vehicle_id tự động được set từ Trip cha khi tạo mới
  QA scenarios:
  - Happy: Mở form edit Trip có sẵn checkpoints → thấy danh sách trong Repeater → sửa km_reading → bấm Save → reload → giá trị mới được lưu.
  - Happy: Mở form → bấm "Add" → chọn checkpoint_type + order_id + occurred_at → Save → checkpoint mới xuất hiện trong DB.
  - Happy: Mở form → bấm icon xoá ở 1 dòng → Save → checkpoint bị xoá khỏi DB.
  - Failure: Bỏ trống checkpoint_type hoặc occurred_at → bấm Save → form báo lỗi validation.
  Commit: Y | feat(trips): replace checkpoint custom view with Repeater supporting add/edit/delete

- [x] 2. routes/web.php: Remove bulk-update route (lines 22-47)
  What to do: Xoá toàn bộ block `Route::post('/trips/{trip}/checkpoints/bulk-update', ...)` (dòng 22-47 trong routes/web.php).
  Must NOT do: KHÔNG xoá route khác, KHÔNG xoá `Route::get('/mobile/{any}', ...)` ở trên.

  Parallelization: Wave 1 | Blocked by: none | Blocks: none
  References: routes/web.php:22-47
  Acceptance criteria:
  - `POST /trips/{id}/checkpoints/bulk-update` trả về 404
  - Các route khác trong web.php không bị ảnh hưởng
  QA scenarios:
  - Happy: Sau khi xoá, `php artisan route:list --path=trips` không còn route `checkpoints.bulk-update`.
  - Failure: Gọi `POST /trips/1/checkpoints/bulk-update` → 404.
  Commit: Y | chore: remove unused bulk-update checkpoint route

- [x] 3. Delete resources/views/filament/resources/trips/components/grouped-checkpoints.blade.php
  What to do: Xoá file.
  Must NOT do: KHÔNG xoá thư mục components/ nếu còn file khác trong đó.

  Parallelization: Wave 1 | Blocked by: none | Blocks: none
  References: resources/views/filament/resources/trips/components/grouped-checkpoints.blade.php (141 dòng)
  Acceptance criteria:
  - File không còn tồn tại
  - Form Trip vẫn render bình thường (không crash do missing view)
  QA scenarios:
  - Happy: Mở form Trip → không có lỗi "View [checkpoints_grouped] not found".
  - Happy: Section "Các mốc hành trình" hiển thị Repeater thay vì Blade view.
  Commit: Y | chore: remove replaced grouped-checkpoints Blade view

## Final verification wave
> Runs in parallel after ALL todos. ALL must APPROVE. Surface results and wait for the user's explicit okay before declaring complete.
- [x] F1. Plan compliance audit — verify all 3 todos completed, Scope OUT respected
- [x] F2. Code quality review — `vendor/bin/pint --format agent` passes, no unused imports
- [x] F3. Real manual QA — user opens Trip form, tests add/edit/delete/save ✅ confirmed working
- [x] F4. Scope fidelity — no changes to models, services, enums, API, migrations

## Commit strategy
Single commit: `feat(trips): replace checkpoint custom view with Repeater supporting add/edit/delete`
Hoặc 3 commits riêng (T1, T2, T3).

## Success criteria
- Trip form section "Các mốc hành trình" hiển thị Repeater table với đầy đủ cột
- Có nút "Add" để thêm checkpoint mới
- Có nút xoá (🗑) trên mỗi dòng để xoá checkpoint
- Sửa trường trong Repeater → bấm Save form → thay đổi được lưu vào DB
- driver_id/shift_id/vehicle_id tự động điền khi tạo checkpoint mới
- Route bulk-update không còn tồn tại
- Không có lỗi "View not found"
