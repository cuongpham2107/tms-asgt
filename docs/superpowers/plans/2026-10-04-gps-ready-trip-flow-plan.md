# ĐẶC TẢ THỰC THI: Bỏ km nhập tay · Đảo lái mới · Đồng bộ trạng thái · Km GPS

> **Dành cho AI coding agent.** Đọc hết file này trước khi viết code. Làm **đúng thứ tự task**, mỗi task một commit. Không tự mở rộng phạm vi. Gặp chỗ mơ hồ không có trong file này thì **dừng lại và hỏi**, không tự đoán.

- Repo: Laravel backend (`app/`) + Filament admin + app tài xế Expo React Native (`mobile/`).
- Nhánh làm việc: `feature/gps-trip-tracking`. Không commit lên `main`.
- Ngày chốt đặc tả: 2026-10-04.

---

## PHẦN A: QUY TẮC LÀM VIỆC (bắt buộc)

### A1. Stack (đọc `composer.json` / `mobile/package.json` để xác nhận)
- PHP 8.4, **Laravel 13**, **Filament 5**, **Pest 4**, Sanctum 4.
- Test chạy trên **SQLite** (`phpunit.xml`). Migration phải chạy được trên SQLite **và** DB production (MySQL/MariaDB). Chỉ dùng Schema builder, không viết SQL thô riêng cho một loại DB.
- Mobile: Expo SDK 57, expo-router, `expo-dev-client`, đã có `expo-location`. Build qua EAS.

### A2. Quy ước code
- Đọc `AGENTS.md` ở gốc repo và làm theo.
- Tạo file bằng `php artisan make:... --no-interaction` (migration, model, test, class).
- Khai báo đầy đủ kiểu tham số và kiểu trả về. Dùng constructor property promotion. Enum key dạng TitleCase. Ưu tiên PHPDoc hơn comment inline.
- Ghi trạng thái bằng `$model->save()` / `$model->update([...])` trên **từng model**, không dùng `Query\Builder::update()` hàng loạt cho cột `status`, để observer và activity log luôn chạy.
- **Không sửa migration cũ.** Mọi thay đổi schema là migration mới.
- Không thêm package PHP mới. Mobile chỉ thêm đúng các package ghi ở task M-GPS.
- Text hiển thị cho người dùng viết bằng tiếng Việt, giống code hiện có.

### A3. Quy trình cho mỗi task
1. Đọc các file liệt kê trong task và các file "hàng xóm" cùng thư mục để theo đúng convention.
2. Viết/sửa test Pest trước (tạo bằng `php artisan make:test --pest TenTest`).
3. Code cho test xanh.
4. Chạy test hẹp: `php artisan test --compact --filter=TenTest` hoặc đường dẫn file.
5. Chạy `vendor/bin/pint --dirty --format agent`.
6. Hết mỗi **phase**: chạy toàn bộ `php artisan test --compact`, phải xanh.
7. Commit: `<type>: <mô tả>` (feat/fix/refactor/test/chore), mỗi task một commit, nêu mã task (ví dụ `refactor(P1-T2): ...`).

### A4. Không được làm
- Không xoá test ngoài danh sách được phép ghi trong task.
- Không đưa nhánh lên production trước khi xong **Phase 6**: từ Phase 1 đến Phase 6, chuyến mới sẽ không có km.
- Không đổi tên các cột kết quả km (`total_km`, `total_km_loaded`, `total_km_empty`, `orders.loaded_km`).

---

## PHẦN B: QUYẾT ĐỊNH ĐÃ CHỐT (không bàn lại)

| # | Quyết định |
|---|---|
| Q1 | Lái mới khi đảo lái **do điều hành gán** (Filament). Lái xe chỉ **xin đảo lái** từ app. |
| Q2 | "Đảo lái" **vẫn là một trạng thái** (`driver_swap`) của Trip và Order. Khi gán lái mới thì khôi phục từ `trips.status_before_swap`. |
| Q3 | `vehicles.current_mileage` **giữ lại**, chỉ admin nhập tay (bảo dưỡng). App và luồng chuyến **không đọc, không ghi** cột này. |
| Q4 | **Migrate từ DB hiện tại.** Drop các cột *nhập* km, **giữ** các cột *kết quả* km. |
| Q5 | Tiền khách tính theo **km GPS thực tế** của từng đơn (`orders.loaded_km`). |
| Q6 | GPS lấy từ **điện thoại tài xế**, ghi **suốt ca**. Hộp đen EUP chỉ để lấp chỗ trống và đối chiếu. |
| Q7 | Mobile dùng `expo-location` + `expo-task-manager` (miễn phí) trước. Không dùng `react-native-background-geolocation` trong phạm vi này. |

---

## PHẦN C: HIỆN TRẠNG CẦN BIẾT (đã khảo sát, có file:line)

### C1. Km đang điều khiển luồng chuyến (phải sửa, không chỉ xoá)
- `app/Services/Trip/TripCheckpointService.php` ~249-283 `autoStartTrip()`: **ném lỗi nếu `vehicle.current_mileage` null**, và lấy km khởi đầu từ xe.
- `app/Services/Trip/Handlers/CheckpointEndHandler.php` ~21-24: **return sớm nếu thiếu `km_reading`**, nên chuyến không hoàn thành.
- `app/Models/Trip.php` ~207-231 `complete(?float $endKm)`: được gọi từ `TripController::complete`, `CheckpointEndHandler`, `EndHandler`, `CancelTripAction`, `EndShiftAction`.
- Sắp xếp theo `km_reading`: `TripKmCalculatorService`, `TripKmSplitService::loadedRanges`, `ShiftKmCalculatorService::recordOrderLoadedKm`, `CompletedHandler` ~64-78, `DriverShift` ~125 (timeline grouping key) và ~183-245.
- `app/Models/Vehicle.php:22` có default `current_mileage => 10000`, nên lỗi ở `autoStartTrip` đang bị che.
- `app/Filament/Pages/DriverDutyReport.php:289` nhận biết đảo lái bằng `whereNotNull('end_km')`.

### C2. Danh sách code km
- **Services:**
  - `TripKmCalculatorService` và `ShiftKmCalculatorService`: **viết lại ở P6**.
  - `TripKmSplitService`, `TripKmAdjustmentService`, `Trip/TripKmLimitService`, `HandoverKmResolver`: **xoá**.
- **Báo sai km:** model `TripKmReport`, enum `TripKmReportStatus`, `Api/TripKmReportController`, `ReportTripKmRequest`, route `POST /driver/trips/{trip}/report-km-issue`, bảng `trip_km_reports`, quan hệ `Trip::kmReports()` / `latestPendingKmReport()`, cột + action trong `TripsTable` (~64, 117-128, 289-420). **Xoá hết.**
- **Request có rule km:**
  - `TripCheckpointRequest` (46-51 required km cho arrived_pickup/completed; 94-145 validate km).
  - `EndVehicleRequest`, `EndShiftRequest` (end_km), `SwitchVehicleRequest`, `DriverSwapRequest`.
  - `CheckpointRequest`: **không được dùng ở đâu, xoá**.
- **Command:** `CheckKmCalculation` (xoá). `CheckTripCheckpoints` và `FullWorkflowTest` (command): bỏ phần km.
- **Tests chuyên km (được phép xoá):** `TripKmTest`, `TripKmReportTest`, `TripKmLimitValidationTest`, `ShiftKmCalculatorTest`, `HandoverKmResolverTest`, `TripKmSplitServiceTest`.
- **Tests cần sửa (không xoá):** `OrderFullFlowTest`, `EndVehicleCheckpointTest`, `FullWorkflowTest`, `TripStatsTest`, `TripOrderSequenceTest`, `TripCheckpointTest`, `OrderFlowHHHKTest`, `DriverFcmNotificationTest`, `TripStatisticsReportTest`, `TripHistoryTest`, `TripResourceTest`, `CancelTripActionTest`, `TripExcelExportTest`, `PendingTripVisibilityAndActionsTest`, `TripDeliveredNotificationTest`, `AuthControllerTest`, `StartShiftVehicleTest`.

### C3. Đảo lái hiện tại
- **Đường đang chạy:**
  1. Lái xe `POST /trips/{trip}/complete` khi còn đơn dở, hoặc `POST /shifts/{shift}/end-vehicle`, hoặc admin `EndShiftAction` → trip/order chuyển `driver_swap`.
  2. Admin dùng `ReassignDriverAction` (`TripsTable.php:430`) để gán lái mới.
- **Code chết:**
  - `Api/DriverSwapController`: không có route; query `driver_shifts.vehicle_id` là cột không tồn tại.
  - `Trips/Actions/DriverSwapAction`: không được đăng ký, chỉ dùng trong test `DriverFcmNotificationTest:528`.
  - `POST /shifts/switch-vehicle`: mobile không gọi.
- **Lỗi đã biết:**
  - `ReassignDriverAction` khôi phục trạng thái bằng cách đoán từ checkpoint cuối (sai), lấy `from_shift_id` từ ca mới nhất (sai ca).
  - `DriverShiftController::start` 72-75 ghi `to_shift_id` cho *mọi* lần đảo lái cũ.
  - Lái cũ không nhận push.
  - `DriverShift` 52-56 xoá ca thì xoá luôn bản ghi đảo lái.
  - `EndHandler` 23-26 biến chuyến `delivered` thành `driver_swap`.
  - `EndHandler` crash với chuyến không có đơn.
  - Logic "chuyển sang driver_swap" đang viết ở 5 nơi, mỗi nơi lọc trạng thái đơn khác nhau.

### C4. Lỗi đồng bộ trạng thái (phải sửa ở Phase 3)
1. Đơn `cancelled` bị coi là chưa xong, nên chuyến không bao giờ delivered/completed (`CompletedHandler` 90-92, `CheckpointEndHandler` 35-37, `TripController` 296-298).
2. `TripController::complete` cho phép hoàn thành chuyến đã `cancelled` (240).
3. `recordCheckpoint` không kiểm tra trạng thái hiện tại: chuyến đã xong hoặc đã huỷ vẫn quay lại được; `completed` trên đơn đã huỷ thì đơn thành completed.
4. `checkpoint_type=cancelled` qua validation nhưng `match` trong `dispatchHandler` không có nhánh, gây lỗi 500 (`TripCheckpointService` 232-240).
5. Chuyến nhiều đơn: giao xong 1 đơn thì chuyến vẫn kẹt ở `arrived_delivery`.
6. Xe bị coi là rảnh khi đơn chỉ còn `in_transit` (`CompletedHandler::resetVehicleIfIdle` ~106; `CreatesOrderTransportCards` 79, 207, 329-331, 399-401).
7. `CancelOrderAction`: chỉ hiện khi Draft nhưng lại kiểm tra `canCancel()`; khi huỷ chuyến dây chuyền thì chỉ `update(status)` trần, không làm gì khác.
8. `UnsendOrderAction`: chỉ hiện khi Draft nhưng yêu cầu Sent, nên không bao giờ chạy được.
9. `SendTripAction` chuyển thẳng Draft sang Sent, bỏ qua bước Assigned.
10. `OrdersTable.php:351` khi nhân bản đơn copy luôn trạng thái điểm giao.
11. Checkpoint `end` được gộp theo địa điểm như `completed` (`CheckpointFactory` 29-31, 124-131), nên đơn này tạo `end` hộ cho đơn khác.
12. Form sửa chuyến (`TripForm.php:39-43`) có ô Select trạng thái tự do.
13. Rent vehicle: tạo checkpoint giả nhưng không đổi trạng thái (`CreatesOrderTransportCards` 991-1098).
14. "Tạo chuyến + gán xe" đang viết 4 lần: `AssignTransportAction` 84-149, `BulkAssignTransportAction` 124-168, `CreatesOrderTransportCards` 940-983, `OrdersTable` 273-296.
15. Mobile và backend hiểu "đang chạy" khác nhau (`mobile/app/(tabs)/trips.tsx:85-91`, `index.tsx:84`, `OrderController` 36 và 169-171, `mobile/app/shift.tsx:16`).

---

## PHẦN D: THIẾT KẾ ĐÍCH

### D1. Trạng thái

**TripStatus**: giữ `pending, started, arrived_pickup, delivering, arrived_delivery, delivered, completed, driver_swap, cancelled`. Riêng `return_trip` được **thay bằng cờ `is_empty_run`** (cột đã có) cộng các trạng thái thường (task P3-T5).

Bảng chuyển trạng thái của Trip (mọi chuyển khác thì **ném `InvalidTransitionException` → HTTP 422**):

| Từ | Sự kiện | Đến | Ghi chú |
|---|---|---|---|
| pending | `started` (hoặc checkpoint bất kỳ khác → auto-start) | started | Đơn `assigned` → `sent` |
| started | `arrived_pickup` | arrived_pickup | |
| arrived_pickup | `left_pickup` | delivering | Đơn `sent` → `in_transit` |
| delivering, arrived_delivery | `arrived_delivery` | arrived_delivery | Điểm giao `pending` → `arrived` |
| arrived_delivery, delivering | `completed` (một đơn hoặc điểm giao) | delivering hoặc delivered | Điểm giao → `delivered`; đơn → `completed` khi hết điểm. **Mọi đơn đều đã đóng** (completed/cancelled, ít nhất 1 completed) thì → `delivered`, ngược lại → `delivering` |
| delivered | `end` hoặc API `complete` | completed | Đặt `completed_at`, vào hàng đợi tính km (P6) |
| started, arrived_pickup, delivering, arrived_delivery | `request_swap` | driver_swap | Lưu `status_before_swap`; đơn `sent`/`in_transit` → `driver_swap`; đóng lượt lái |
| driver_swap | `assign_driver` | `status_before_swap` | Đơn `driver_swap` → `in_transit` nếu `status_before_swap ∈ {delivering, arrived_delivery}`, ngược lại → `sent`; mở lượt lái |
| pending, started, arrived_pickup, delivering, driver_swap | `cancel` | cancelled | Mọi đơn chưa đóng → cancelled; checkpoint `cancelled`; `cancelled_at` |
| (mọi trạng thái đang chạy) | Huỷ đơn khiến **mọi đơn đều cancelled** | cancelled | Như trên |

**OrderStatus**: giữ `draft, assigned, sent, in_transit, driver_swap, completed, cancelled`.

| Từ | Sự kiện | Đến |
|---|---|---|
| draft | gán xe | assigned |
| assigned | gửi lệnh | sent |
| sent | thu hồi (chỉ khi trip chưa `left_pickup`) | assigned |
| sent | trip `left_pickup` | in_transit |
| in_transit | hết điểm giao chưa giao | completed |
| sent, in_transit | trip `request_swap` | driver_swap |
| driver_swap | trip `assign_driver` | sent hoặc in_transit (như trên) |
| draft, assigned, sent, in_transit, driver_swap | huỷ (chỉ khi **chưa có điểm giao nào `arrived`**, theo `docs/Dieu_hanh.md`) | cancelled |

**OrderDeliveryPointStatus**: `pending → arrived → delivered`. Cho phép `pending → delivered` (tự điền `arrived_at` = `delivered_at`).

**Hằng số dùng chung** (đặt trên enum):
- `TripStatus::busyStatuses()` = pending, started, arrived_pickup, delivering, arrived_delivery, delivered, driver_swap. Dùng cho "xe bận", "lái bận", và chặn bắt đầu chuyến mới.
- `TripStatus::driverActionableStatuses()` = pending, started, arrived_pickup, delivering, arrived_delivery, delivered. Đây là các trạng thái lái xe được thao tác.
- `OrderStatus::closedStatuses()` = completed, cancelled.

**API trả `available_actions`** trên `TripResource` và `OrderResource`: một mảng string, được tính bởi `TripStateMachine::availableActions(Trip, User)`. Giá trị có thể là `started, arrived_pickup, left_pickup, arrived_delivery, completed, end, request_swap`. Mobile **chỉ** hiển thị nút theo mảng này. Đồng thời trả `status_label` để mobile hiển thị đúng nhãn của backend.

### D2. Lượt lái và đảo lái

Bảng mới `trip_driver_assignments`:

| Cột | Kiểu | Ghi chú |
|---|---|---|
| id | bigint | |
| trip_id | FK trips, cascade delete | |
| driver_id | FK users | |
| shift_id | FK driver_shifts, **nullOnDelete** | Xoá ca không xoá lượt lái |
| started_at | datetime | |
| ended_at | datetime nullable | null = đang giữ chuyến |
| end_reason | string nullable | Enum `AssignmentEndReason`: `shift_handover`, `cargo_not_unloaded`, `reassigned`, `trip_finished`, `trip_cancelled`, `other` |
| note | text nullable | |
| created_by | FK users nullable | |
| timestamps | | |

- Index `(trip_id, ended_at)`.
- **Bất biến:** mỗi trip có tối đa 1 bản ghi `ended_at IS NULL`. `trips.driver_id` luôn bằng `driver_id` của lượt đang mở, hoặc **null** khi trip ở `driver_swap`.
- Thêm cột `trips.status_before_swap` (string nullable).

`App\Services\Trip\TripDriverService`, **nơi duy nhất** thay đổi tài xế của chuyến (mọi thao tác chạy trong `DB::transaction`):
- `openAssignment(Trip, User $driver, ?User $by)`: dùng khi tạo hoặc gán chuyến lần đầu.
- `requestSwap(Trip, User $driver, AssignmentEndReason, ?string $note)`:
  - Kiểm tra `$driver` đang giữ lượt mở, nếu không thì ném lỗi 403.
  - Gọi `TripStateMachine` chuyển sang `driver_swap`, đóng lượt, đặt `trips.driver_id = null`.
  - Push cho điều hành không bắt buộc. Đã có notification nào cho admin thì dùng lại, nếu không thì bỏ qua.
- `assignDriver(Trip, User $driver, User $by)`: trip phải ở `driver_swap` hoặc `pending`. Mở lượt mới, khôi phục trạng thái, push "Bạn được giao chuyến X" cho lái mới **và** "Chuyến X đã được giao cho Y" cho lái cũ.
- `replaceDriver(Trip, User $driver, User $by, ?string $note)`: điều hành đổi trực tiếp khi chuyến đang chạy. Đóng lượt cũ (`reassigned`), mở lượt mới; **trạng thái chuyến không đổi**. Push cho cả hai lái.
- `closeOnFinish(Trip, AssignmentEndReason)`: gọi khi trip `completed` (`trip_finished`) hoặc `cancelled` (`trip_cancelled`).
- `shift_id` của lượt mới = ca đang mở của tài xế, hoặc null. Khi tài xế vào ca sau đó, **chỉ** gán `shift_id` cho lượt **đang mở** của họ.

**Kết thúc ca** (API `/shifts/end` và Filament `EndShiftAction` dùng chung `DriverShiftService::endShift`): mọi chuyến tài xế đang giữ lượt mở mà ở trạng thái `driver_swap`-được (xem bảng D1) thì gọi `requestSwap(..., ShiftHandover)`. Chuyến `delivered` thì gọi complete. Chuyến `pending` thì đóng lượt, trip về `driver_id = null`, trạng thái giữ `pending`. Không chặn kết ca nữa.

### D3. Km có hàng / không hàng (Phase 6)

Hàm gốc: `GpsDistanceService::distance(int $vehicleId, CarbonInterface $from, CarbonInterface $to, ?int $driverId = null): DistanceResult{km, coverage, source}`, tính từ bảng `vehicle_gps_points`.

| Con số | Khoảng thời gian | Lưu ở |
|---|---|---|
| Km chuyến | `[started_at, completed_at]` | `trips.total_km` |
| Km có hàng của xe | **Hợp** các khoảng `[arrived_pickup.occurred_at, completed.occurred_at cuối cùng của đơn]` của mọi đơn (chồng nhau chỉ tính 1 lần) | `trips.total_km_loaded` |
| Km không hàng của xe | total − loaded | `trips.total_km_empty` |
| Km có hàng của đơn (**tính tiền khách**) | `[arrived_pickup, completed cuối]` của riêng đơn | `orders.loaded_km` |
| Km theo lái | Các khoảng trên **giao** `[assignment.started_at, ended_at]` | cột mới trên `trip_driver_assignments`: `km`, `km_loaded`, `km_empty` |
| Km ca | `[shift.start_time, shift.end_time]`, chỉ tính điểm của lái đó | `driver_shifts.total_km` |
| Km có hàng / không hàng của ca | Tổng `km_loaded` các lượt lái của ca; empty = total − loaded | `driver_shifts.total_km_loaded`, `total_km_empty` |

- Đơn có `arrived_pickup` nhưng thiếu `completed` (bị huỷ giữa đường): khoảng có hàng kết thúc ở `cancelled_at`.
- Đơn không có `arrived_pickup` (hàng đã có sẵn trên xe): khoảng bắt đầu từ `trip.started_at`.
- Cột mới trên `trips`: `km_source` (enum `phone_gps|eup|mixed|manual`), `gps_coverage` (decimal 5,2 %), `km_calculated_at`, `km_needs_review` (bool), `km_adjusted` (decimal nullable), `km_adjusted_loaded` (decimal nullable), `km_adjust_reason`, `km_adjusted_by`.
- Chốt số: tính khi trip `completed` (job `CalculateTripKmJob`), tính lại khi admin sửa giờ checkpoint. Báo cáo và export đọc số đã lưu, dùng `km_adjusted*` nếu có.
- `gps_coverage < 90` hoặc có điểm `mocked` thì `km_needs_review = true`.

### D4. GPS trên app (Phase 6)
- Ghi **từ lúc vào ca đến lúc kết ca**. Mỗi điểm gồm `seq` (tăng dần theo thiết bị), `recorded_at` (ISO8601 kèm offset), `lat`, `lng`, `speed`, `heading`, `accuracy`, `mocked`.
- App gửi kèm `device_id` (tạo một lần, lưu AsyncStorage), `shift_id`, `vehicle_id`.
- Server lưu vào `vehicle_gps_points`. Khoá duy nhất `(device_id, seq)`, trùng thì bỏ qua.
- Điểm tính km cho một chuyến = điểm của `vehicle_id` đó **từ thiết bị của tài xế đang giữ lượt lái** trong khoảng thời gian đó. Thiếu thì lấy điểm `source=eup`.

---

## PHẦN E: DANH SÁCH TASK

> Định dạng mỗi task: **Mục tiêu · File · Bước làm · Nghiệm thu**. "Nghiệm thu" là các test Pest phải có và phải xanh.

### PHASE 1: Gỡ nhập km ở backend

**P1-T1. Migration drop cột nhập km và bảng báo sai km**
- Tạo migration `drop_manual_km_input_columns`:
  - drop `trips.start_km`, `trips.end_km`
  - drop `driver_shifts.start_km`, `driver_shifts.end_km`
  - drop `trip_checkpoints.km_reading`
  - drop `driver_swaps.handover_km`
  - drop table `trip_km_reports`
- **Không** drop `total_km*`, `orders.loaded_km`, `vehicles.current_mileage`.
- `down()` tạo lại các cột (nullable) và bảng.
- Cập nhật `$fillable`/`casts()` của `Trip`, `DriverShift`, `TripCheckpoint`, `DriverSwap`. Xoá default `current_mileage => 10000` ở `Vehicle`.
- **Nghiệm thu:** `php artisan migrate:fresh` chạy được trên SQLite; toàn bộ test có thể đỏ, sẽ sửa ở các task sau.

**P1-T2. Xoá service, model, route km**
- Xoá các file: `TripKmSplitService`, `TripKmAdjustmentService`, `Trip/TripKmLimitService`, `HandoverKmResolver`, `TripKmReport`, `TripKmReportStatus`, `Api/TripKmReportController`, `ReportTripKmRequest`, `CheckpointRequest`, `Console/Commands/CheckKmCalculation`.
- Xoá route `report-km-issue`, các quan hệ `Trip::kmReports()` / `latestPendingKmReport()`.
- Xoá 6 file test chuyên km (C2).
- `TripKmCalculatorService` và `ShiftKmCalculatorService`: **giữ file**, thay thân các method public bằng no-op có PHPDoc `@todo P6`. Gỡ hết chỗ gọi (`TripCheckpointService` 98-102, `Trip::complete`, các controller/action).
- **Nghiệm thu:** `grep -rn "km_reading\|start_km\|end_km\|handover_km\|TripKmReport\|TripKmLimit\|HandoverKmResolver\|TripKmSplit" app routes` không còn kết quả (trừ migration cũ).

**P1-T3. Sửa luồng chuyến không phụ thuộc km**
- `TripCheckpointService::autoStartTrip`: bỏ kiểm tra mileage và phần lấy km.
- `CheckpointFactory` 52, 83, 161-177: bỏ logic km.
- `VehicleUpdater`: bỏ ghi `current_mileage`.
- `StartedHandler:28`: bỏ.
- `CompletedHandler`: bỏ tính `loaded_km`.
- `CheckpointEndHandler`: bỏ điều kiện km.
- `EndHandler`: bỏ km.
- `Trip::complete(?string $completedAt = null)`: bỏ tham số km, bỏ ghi mileage. Sửa mọi chỗ gọi.
- `createMissingEndCheckpoints`: bỏ km.
- Mọi chỗ sắp xếp hoặc nhóm checkpoint theo `km_reading` (C1) đổi sang `occurred_at`, sau đó `id`.
- **Nghiệm thu:**
  - Test: tạo xe với `current_mileage = null`, gửi checkpoint `arrived_pickup` cho trip pending → trip tự bắt đầu.
  - Test: gửi checkpoint `end` không có km khi mọi đơn đã xong → trip `completed`.

**P1-T4. Gỡ km khỏi Request, Resource, Controller**
- `TripCheckpointRequest`: bỏ rule km 46-51 và 94-145 (giữ các rule khác).
- `EndShiftRequest`: bỏ `end_km`.
- `EndVehicleRequest`, `SwitchVehicleRequest`: tạm bỏ rule km (sẽ xoá hẳn ở P4).
- `TripController::complete`: bỏ `end_km` và các kiểm tra km. `TripController::show:111`: bỏ `vehicle_mileage`. `TripController::stats`: chỉ trả các tổng `total_km*` đọc từ DB (không tính).
- `DriverShiftController`: `start`, `end`, `endVehicle`, `switchVehicle` bỏ km. `ShiftStatusController`: bỏ `last_km` và tính lại km.
- `VehicleSearchController`: bỏ `current_mileage` khỏi response.
- Resources: `TripResource` (bỏ start/end, giữ `total_km*`, bỏ adjusted km theo lái), `DriverShiftResource` (như trên), `TripCheckpointResource`, `DriverSwapResource`, `OrderResource:50`, `UserResource:42`.
- `DriverNotificationService::sendTripDriverSwapped`: bỏ tham số `$handoverKm`.
- `ActivityLogFormatter`: bỏ nhãn các cột đã drop.
- **Backward compatible:** request từ app cũ có gửi `km_reading` / `end_km` vẫn phải được chấp nhận (bỏ qua trường thừa, không lỗi 422).
- **Nghiệm thu:**
  - Test: POST checkpoint `arrived_pickup` kèm `km_reading: 12345` → 200, không lưu km.
  - Test: POST checkpoint không có km → 200.
  - Sửa các test liệt kê ở C2 cho xanh.

**P1-T5. Command, seeder, script**
- `CheckTripCheckpoints`, command `FullWorkflowTest`, `VehicleImportSeeder`, `RentedVehicleSeeder`, `database/scripts/*`: bỏ km nhập.
- **Nghiệm thu:** `php artisan migrate:fresh --seed` chạy được; toàn bộ `php artisan test --compact` xanh.

### PHASE 2: Gỡ nhập km ở Filament, báo cáo, export

**P2-T1. Trips**
- `TripsTable`:
  - Cột KM: chỉ hiện `total_km` (`—` nếu null). Bỏ phần diff với `current_mileage`.
  - Xoá cột "Báo sai Km", action "Tính lại Km", action "Xử lý báo sai Km", `recalculateKm()`, eager load `latestPendingKmReport`.
- `TripForm`: bỏ input start/end/total km và cột/input `km_reading` trong repeater.
- `TripInfolist`: bỏ start/end và diff; giữ `total_km*` (chỉ đọc).
- `ViewTripTimeline`, các blade timeline/popup/real-time-tracking: bỏ `km_reading`.
- `CancelTripAction`: bỏ input km. `CreateEmptyRunAction`: bỏ km. `ReassignDriverAction`: bỏ input `handover_km` (action này sẽ được thay ở P4).
- **Nghiệm thu:** sửa `CancelTripActionTest`, `TripResourceTest`, `PendingTripVisibilityAndActionsTest` cho xanh; trang danh sách, xem, sửa chuyến render không lỗi (Livewire test `assertSuccessful`).

**P2-T2. Ca làm, xe, báo cáo, export**
- `DriverShiftForm` / `Infolist` / `Table` / `CalendarWidget`: bỏ start/end km; giữ tổng km chỉ đọc.
- `DriverShift::getActivityTimelineAttribute` và `getOrdersWithKmDetailsAttribute`: bỏ km nhập, giữ `loaded_km` của đơn.
- `EndShiftAction`: bỏ km (logic ca sẽ sửa ở P4).
- `VehicleForm` / `VehiclesTable`: **giữ** `current_mileage` (Q3), đổi nhãn thành "Số km ODO (nhập tay)".
- `TripExcelExportService` cột V/W, `TripStatisticsReport`, `DriverDutyReport`: **giữ** km có hàng/không hàng, đọc từ cột kết quả; null thì hiện trống.
- `DriverDutyReport:289`: đổi cách nhận biết đảo lái sang `DriverSwap` tồn tại (P4 sẽ đổi sang lượt lái).
- **Nghiệm thu:** `TripExcelExportTest`, `TripStatisticsReportTest`, `DriverShiftFilterTest` xanh. Toàn bộ test xanh.

### PHASE 3: TripStateMachine và sửa đồng bộ trạng thái

**P3-T1. Enum helpers và exception**
- Thêm `TripStatus::busyStatuses()`, `driverActionableStatuses()`, `OrderStatus::closedStatuses()` (D1). Sửa `TripStatus::activeStatuses()` cho đúng nghĩa, hoặc thay mọi chỗ dùng bằng hàm mới.
- Tạo `App\Exceptions\InvalidTransitionException`, render JSON 422 `{message}` cho API và Notification danger cho Filament.

**P3-T2. `App\Services\Trip\TripStateMachine`**
- Các method: `applyCheckpoint(Trip, CheckpointType, Collection $checkpoints)`, `requestSwap`, `restoreAfterSwap`, `cancelTrip(Trip, User $by, ?string $reason)`, `cancelOrder(Order, User $by)`, `sendOrder(Order)`, `recallOrder(Order)`, `complete(Trip)`, `availableActions(Trip, ?User $driver): array`.
- Thực thi **đúng** bảng D1, gồm cả cascade điểm giao → đơn → chuyến → xe:
  - Xe về `On` khi không còn trip nào của xe ở `busyStatuses`.
  - Xe `Running` khi trip `started`.
- Ghi trạng thái bằng `save()` (A2).
- **Nghiệm thu:** `tests/Feature/TripStateMachineTest.php` có test cho **mỗi dòng** của 2 bảng chuyển trạng thái và ít nhất 6 chuyển không hợp lệ phải ném exception. Phải có các case:
  - Chuyến 2 đơn, 1 đơn huỷ + 1 đơn hoàn thành → `delivered`.
  - Chuyến 2 đơn, giao xong đơn 1 → trip `delivering`.
  - Checkpoint trên trip `cancelled` → 422.
  - Xe vẫn bận khi đơn còn `in_transit`.

**P3-T3. Nối các handler và API vào state machine**
- Các handler trong `app/Services/Trip/Handlers/` chỉ còn làm: tạo checkpoint/ảnh/điểm giao, rồi gọi `TripStateMachine::applyCheckpoint`. Không tự set status nữa.
- `TripCheckpointService::dispatchHandler`: thêm nhánh `Cancelled` → 422 "Không dùng checkpoint để huỷ chuyến" (sửa lỗi C4.4). Gộp `EndHandler` / `CheckpointEndHandler` (đặt lại tên cho rõ nghĩa).
- `TripController::complete`:
  - Trip `delivered` → `complete`.
  - Trip đang chạy mà mọi đơn đã đóng → `complete`.
  - Còn đơn dở → **422** "Còn đơn chưa giao, hãy dùng Đảo lái". Bỏ nhánh tự chuyển sang driver_swap.
- `CheckpointFactory`: checkpoint `end` **không** gộp theo địa điểm (sửa C4.11).
- **Nghiệm thu:** `TripCheckpointTest`, `TripOrderSequenceTest`, `OrderFlowHHHKTest`, `OrderFullFlowTest` xanh. Thêm test cho C4.2, C4.3, C4.4.

**P3-T4. Nối các action Filament vào state machine**
- `CancelOrderAction`: hiện khi `canCancel` theo D1; huỷ dây chuyền qua `TripStateMachine::cancelOrder`.
- `CancelTripAction`: dùng `cancelTrip`.
- `SendOrderAction` / `BulkSendOrderAction` / `SendTripAction`: dùng `sendOrder`; `SendTripAction` chỉ gửi đơn đang `assigned`.
- `UnsendOrderAction`: hiện khi `sent` và trip chưa `left_pickup`. Bỏ comment ở `OrdersTable.php:302`.
- `TripForm`: **bỏ** ô Select trạng thái (C4.12).
- `TripsTable` EditAction: bỏ logic set completed cho Rent vehicle. Rent vehicle hoàn thành bằng action riêng "Đánh dấu hoàn thành (xe thuê)", gọi `TripStateMachine::complete`, đồng thời set điểm giao `delivered` (C4.13).
- `OrdersTable.php:351`: nhân bản điểm giao với `status = pending`, xoá các timestamp.
- **Nghiệm thu:** `OrderCancelTest`, `CancelTripActionTest`, `SendTripActionTest` xanh; thêm test cho C4.7, C4.8, C4.9, C4.10.

**P3-T5. Gộp "tạo chuyến + gán xe"**
- Tạo `App\Services\Trip\TripAssignmentService::assign(Collection $orders, Vehicle, ?User $driver, bool $sendNow, User $by): Trip`.
- Thay 4 bản sao (C4.14) bằng lời gọi service. Picker xe/lái dùng `TripStatus::busyStatuses()` (C4.6).
- `return_trip`: chuyển dữ liệu (migration) các trip `status = return_trip` sang `is_empty_run = true` cộng trạng thái `completed` nếu có checkpoint `end`, ngược lại `started`. Xoá case `ReturnTrip` khỏi enum và sửa các chỗ dùng.
- **Nghiệm thu:** `AssignTransportActionTest`, `BulkAssignTransportActionTest`, `ReassignTransportActionTest`, `AssignTransportNotificationTest` xanh.

**P3-T6. `available_actions` và `status_label` trong API**
- `TripResource` và `OrderResource` trả `available_actions` (từ `TripStateMachine::availableActions`) và `status_label`.
- `OrderController::index` / `stats`, `TripController::active` / `stats`: dùng các hàm enum chung (C4.15). Đơn `driver_swap` vẫn hiện trong lịch sử của lái cũ ở dạng chỉ đọc.
- **Nghiệm thu:** test cho từng trạng thái trip trả đúng `available_actions`. Lái không giữ lượt mở thì nhận `[]`.

### PHASE 4: Lượt lái và đảo lái

**P4-T1. Schema và migrate dữ liệu**
- Migration tạo `trip_driver_assignments` và `trips.status_before_swap` (D2). Tạo model `TripDriverAssignment`, enum `AssignmentEndReason`, factory.
- Migration dữ liệu (chạy một lần, viết trong `up()` bằng Eloquent hoặc Query builder, xử lý theo lô `chunkById`). Với mỗi trip có `driver_id` hoặc có `driver_swaps`:
  1. Lấy các swap theo `created_at`. Dựng các lượt: `[trip.started_at ?? trip.created_at → swap1.created_at]` cho `swap1.from_driver_id`, …, lượt cuối cho `trip.driver_id`.
  2. Lượt cuối có `ended_at = completed_at ?? cancelled_at`, hoặc null nếu trip còn chạy.
  3. Trip đang `driver_swap`: đóng lượt cuối ở `updated_at`, đặt `driver_id = null`, `status_before_swap` suy ra từ checkpoint cuối không phải swap (`left_pickup` → delivering, `arrived_delivery` → arrived_delivery, `arrived_pickup` → arrived_pickup, khác → started).
- Drop bảng `driver_swaps` ở **migration riêng** chạy sau.
- **Nghiệm thu:** test migration với dữ liệu mẫu 3 kịch bản (không đảo lái, đảo lái 1 lần đã xong, đang ở driver_swap) cho ra đúng các lượt.

**P4-T2. `TripDriverService`** theo D2.
- **Nghiệm thu:** `tests/Feature/TripDriverServiceTest.php`:
  - `requestSwap` của lái không giữ lượt → 403.
  - `requestSwap` → trip `driver_swap`, đơn `driver_swap`, lượt đóng, `driver_id` null.
  - `assignDriver` → khôi phục đúng `status_before_swap`, đơn `in_transit`/`sent` đúng quy tắc, push cho 2 lái (dùng `Notification::fake()` hoặc fake hiện có trong `DriverFcmNotificationTest`).
  - `replaceDriver` → trạng thái không đổi, 2 lượt.
  - Bất biến "tối đa 1 lượt mở".

**P4-T3. API**
- Thêm `POST /driver/trips/{trip}/swap` `{reason: shift_handover|cargo_not_unloaded|other, note?}` gọi `requestSwap`.
- Xoá route, method và request: `/shifts/switch-vehicle`, `/shifts/{shift}/end-vehicle`, `EndVehicleRequest`, `SwitchVehicleRequest`, `DriverSwapRequest`, `Api/DriverSwapController`.
- `DriverShiftController::start`: chỉ gán `shift_id` cho lượt **đang mở** của lái (sửa C3).
- `/shifts/end`: dùng `DriverShiftService::endShift` (D2), không chặn nữa.
- Các truy vấn "chuyến của tôi" (`TripController` 37-40, 127-128, 182-185): dựa trên `trip_driver_assignments` (lượt mở = có thể thao tác; lượt đã đóng = lịch sử chỉ đọc).
- **Nghiệm thu:** sửa `EndVehicleCheckpointTest` thành test cho endpoint `swap` và `shifts/end`. `StartShiftVehicleTest`, `TripHistoryTest`, `TripStatsTest` xanh.

**P4-T4. Filament**
- Xoá `DriverSwapAction` và `ReassignDriverAction`.
- Thêm action "Gán / đổi tài xế" trên `TripsTable` và trang xem chuyến:
  - Trip `driver_swap` hoặc `pending` → `assignDriver`.
  - Trip đang chạy → `replaceDriver` (form có ghi chú).
  - Giữ phần chọn tài xế có sắp xếp theo "đang trong ca" như `ReassignDriverAction` hiện tại.
- Thêm bộ lọc nhanh "Đảo lái" (status = driver_swap) trên danh sách chuyến.
- Thêm RelationManager hoặc tab "Lượt lái" trong trang xem chuyến: tài xế, ca, bắt đầu, kết thúc, lý do, người thao tác.
- `EndShiftAction`: dùng `DriverShiftService::endShift`.
- `DriverDutyReport:289`: đổi sang dùng lượt lái.
- Xoá model, resource, factory `DriverSwap` và các quan hệ `driverSwaps()` / `driverSwapCheckpoints()`. Checkpoint type `driver_swap` vẫn giữ để ghi mốc trên timeline (tạo bởi `requestSwap`).
- **Nghiệm thu:** `DriverFcmNotificationTest` (sửa phần swap), `OrderFullFlowTest` (kịch bản swap viết lại dùng service), Livewire test cho action mới. Toàn bộ test xanh.

### PHASE 5: Mobile, luồng nghiệp vụ (không GPS)

**P5-T1. Gỡ km**
- `mobile/src/lib/api.ts`: xoá `endVehicle`, `switchVehicle`, `reportKmIssue`; `trips.complete(tripId)` không còn `endKm`; xoá các trường km nhập trong types.
- `app/trip-detail.tsx`: xoá state, input, validate, modal báo sai km, nút "Báo sai lệch Km đồng hồ", "Km bắt đầu", "Km đồng hồ" (các vùng dòng ~103-130, 160-247, 324-481, 696-956, 1247-1362, 1621-1727 và style liên quan).
- `app/order-detail.tsx`: xoá yêu cầu km (~400-406), input "Km xe" (~996-1025), prefill từ `current_mileage`.
- `app/shift.tsx`: bỏ input km khi kết ca, gọi thẳng `/shifts/end`. `app/(tabs)/profile.tsx` ~88-96: bỏ gọi `endVehicle`.
- `index.tsx`, `trips.tsx`, `stats.tsx`, `completed-trips.tsx`: giữ hiển thị `total_km*` / `loaded_km` (đọc từ API), hiện "—" khi null; bỏ "start → end km".
- **Nghiệm thu:** `npx tsc --noEmit` trong `mobile/` không lỗi; `grep -rn "km_reading\|end_km\|start_km\|handover_km\|reportKm" mobile/app mobile/src` không còn kết quả.

**P5-T2. Nút theo `available_actions` và nhãn từ server**
- Thay mọi logic `canStart`/`canComplete`/điều kiện nút theo status (`trip-detail.tsx` ~301-320, `order-detail.tsx` ~349-367) bằng kiểm tra `available_actions.includes(...)`.
- Hiển thị `status_label` từ API, xoá các map label cục bộ.
- Nút **"Đảo lái"** (khi có `request_swap`): mở modal chọn lý do (Bàn giao ca / Hàng chưa hạ được / Khác + ghi chú), gọi `POST /trips/{trip}/swap`, xong thì quay về danh sách.
- Nút "Kết thúc chuyến" chỉ hiện khi có `end`.
- Push "Bạn được giao chuyến X": bấm vào thì mở `trip-detail` của chuyến đó (theo cách điều hướng push đã có).
- **Nghiệm thu:** `tsc` sạch; kiểm tra tay trên dev build theo kịch bản ở Phần F.

**P5-T3. Bắt buộc cập nhật**
- Backend: `config/app.php` thêm `'min_mobile_version' => env('MIN_MOBILE_VERSION', '1.0.0')`. Trả `min_app_version` trong response `/driver/login` và `GET /driver/shifts/active`.
- Mobile: so sánh với `Constants.expoConfig.version`; thấp hơn thì hiện màn chặn "Vui lòng cập nhật ứng dụng" kèm link store.
- **Nghiệm thu:** test Pest kiểm tra response có `min_app_version`.

### PHASE 6: GPS và tính km (bắt buộc trước production)

**P6-T1. Bảng điểm GPS và API nhận điểm**
- Migration `vehicle_gps_points`:
  - Cột: `id`, `vehicle_id` (index), `driver_id` (nullable), `shift_id` (nullable), `device_id` (string, nullable cho EUP), `seq` (unsigned bigint, nullable), `recorded_at` (datetime, index cùng `vehicle_id`), `lat` / `lng` (decimal 10,7), `speed` (float nullable, km/h), `heading`, `accuracy` (float nullable, m), `mocked` (bool), `source` (enum `phone|eup`), `created_at`.
  - Unique `(device_id, seq)`.
- `POST /driver/gps-points`: body `{device_id, shift_id, vehicle_id, points: [{seq, recorded_at, lat, lng, speed, heading, accuracy, mocked}]}`, tối đa 500 điểm mỗi lần.
  - Validate từng điểm.
  - `insertOrIgnore`.
  - Trả `{last_seq}` = seq lớn nhất **đã lưu hoặc đã có** của thiết bị.
  - Rate limit 30 request/phút/user.
- Lưu vị trí mới nhất vào `vehicles.gps_lat`, `gps_lng`, `gps_speed`, `last_gps_update` (cột đã có), chỉ khi điểm mới hơn.
- Cảnh báo quá 3 phút không nhận được điểm: nằm trong P6-T5.
- **Nghiệm thu:** test gửi trùng lô hai lần → không nhân đôi, `last_seq` đúng; test validate.

**P6-T2. Lấy điểm EUP định kỳ**
- Tạo job `SyncEupGpsJob` dùng `EupGpsService`: ngoài cập nhật xe như hiện tại, insert điểm `source=eup` vào `vehicle_gps_points`.
- Đăng ký trong `routes/console.php`: `Schedule::job(new SyncEupGpsJob)->everyMinute()->withoutOverlapping()`.
- **Route `GET /gps-sync` trong `routes/web.php` đang không có auth**: thêm middleware `auth`.
- **Nghiệm thu:** test với `Http::fake()` trả 2 xe → 2 điểm `eup`; test route `/gps-sync` khi chưa đăng nhập → redirect/401.

**P6-T3. `GpsDistanceService`**
- `distance(vehicleId, from, to, ?driverId)`:
  1. Lấy điểm trong khoảng: ưu tiên `source=phone` (của các thiết bị thuộc `driverId` nếu có); khoảng trống quá 120 giây trong dữ liệu phone thì lấp bằng điểm `eup` cùng khoảng.
  2. Lọc:
     - bỏ điểm `accuracy > 50`;
     - bỏ điểm `mocked`;
     - bỏ điểm mà vận tốc suy ra từ điểm trước lớn hơn 150 km/h;
     - bỏ đoạn dịch chuyển dưới 20 m khi `speed < 3` (chống trôi lúc đứng yên).
  3. Cộng haversine giữa các điểm liên tiếp.
  4. Khoảng trống quá 300 giây không có cả phone lẫn eup: gọi `OsrmService` lấy quãng đường đường bộ giữa hai đầu khoảng trống, cộng vào và đánh dấu.
  5. Trả `DistanceResult{km, coverage (% thời gian có điểm, gap ≤ 120s được coi là phủ), source (phone_gps|eup|mixed), hasMocked, osrmFilledSeconds}`.
- Các ngưỡng đặt trong `config/gps.php`.
- **Nghiệm thu:** `tests/Unit/GpsDistanceServiceTest.php` với dữ liệu tổng hợp:
  - Đường thẳng 10 km chia điểm đều 5 giây → 10 km ± 1%.
  - Đứng yên 30 phút có nhiễu ±15 m → dưới 0.1 km.
  - Một điểm nhảy 5 km → bị loại.
  - Mất phone 10 phút có EUP → dùng EUP, `source = mixed`.
  - Mất cả hai → OSRM được gọi (dùng `Http::fake`).

**P6-T4. Viết lại tính km chuyến / đơn / lái / ca**
- Viết lại `TripKmCalculatorService::calculate(Trip)` và `ShiftKmCalculatorService::calculate(DriverShift)` theo bảng D3, dùng `GpsDistanceService`. Thuật toán hợp khoảng lấy cảm hứng từ bản cũ (xem git history của file) nhưng trục là `occurred_at`.
- Migration thêm các cột ở D3 vào `trips` và `km`, `km_loaded`, `km_empty` vào `trip_driver_assignments`.
- `CalculateTripKmJob` dispatch khi trip chuyển `completed` (từ `TripStateMachine::complete`) và khi admin sửa `occurred_at` của checkpoint.
- Kết ca thì dispatch tính km ca.
- **Nghiệm thu:** `tests/Feature/TripKmGpsTest.php` với điểm GPS tổng hợp:
  - 1 đơn → loaded/empty đúng.
  - 2 đơn chở ghép chồng nhau → loaded của xe là hợp khoảng; `loaded_km` mỗi đơn tính đủ đoạn của nó.
  - Đảo lái giữa đường → km 2 lượt cộng lại bằng km chuyến.
  - Đơn huỷ giữa đường.
  - Coverage dưới 90% → `km_needs_review`.

**P6-T5. Filament: kiểm tra và điều chỉnh km**
- Cột và bộ lọc "Cần kiểm tra km" trên `TripsTable`; hiển thị `km_source` và `gps_coverage`.
- Action "Điều chỉnh km": bắt buộc nhập lý do; ghi `km_adjusted*`, **không** ghi đè số GPS.
- Export, báo cáo và hoá đơn dùng `km_adjusted ?? total_km`, tương tự cho loaded.
- Trang xem chuyến: bản đồ hành trình từ `vehicle_gps_points`, đoạn có hàng và đoạn không hàng tô hai màu (dùng component bản đồ hiện có, xem `buildGpsMapConfig` trong `TripsTable`).
- Widget/notification cho điều hành: chuyến đang chạy mà quá 3 phút không có điểm phone. Dùng scheduled command mỗi phút, gửi Filament database notification cho admin, mỗi chuyến tối đa 1 lần mỗi 15 phút.
- **Nghiệm thu:** Livewire test cho action điều chỉnh (thiếu lý do → lỗi validate); test export dùng số đã điều chỉnh.

**P6-T6. Mobile ghi GPS (`mobile/src/tracking/`)**
- Cài `expo-task-manager` và `expo-sqlite` (đúng phiên bản SDK 57: `npx expo install expo-task-manager expo-sqlite`).
- `app.json`:
  - Plugin `expo-location`: `isAndroidBackgroundLocationEnabled: true`, `isAndroidForegroundServiceEnabled: true`, `locationAlwaysAndWhenInUsePermission` (tiếng Việt, nêu rõ dùng để tính km chuyến).
  - iOS `infoPlist`: `UIBackgroundModes: ["location"]`, `NSLocationAlwaysAndWhenInUseUsageDescription`.
  - Android permissions thêm: `ACCESS_BACKGROUND_LOCATION`, `FOREGROUND_SERVICE`, `FOREGROUND_SERVICE_LOCATION`.
- Các module:
  - `tracker.ts`: `startTracking({shiftId, vehicleId})` / `stopTracking()` bằng `Location.startLocationUpdatesAsync(TASK, {accuracy: High, timeInterval: 5000, distanceInterval: 25, foregroundService: {notificationTitle: 'Đang ghi hành trình', notificationBody: 'ASGT Vận Tải'}, activityType: AutomotiveNavigation, pausesUpdatesAutomatically: false, showsBackgroundLocationIndicator: true})`.
  - `task.ts`: `TaskManager.defineTask` ở **top-level** (import trong entry của app), nhận `locations` và ghi vào SQLite.
  - `queue.ts`: bảng `gps_queue(seq INTEGER PRIMARY KEY AUTOINCREMENT, payload TEXT)`; các hàm `enqueue`, `peekBatch(500)`, `ackUpTo(seq)`.
  - `uploader.ts`: 30 giây/lần hoặc đủ 50 điểm thì gửi; khi app vào foreground thì flush; khi lỗi thì backoff 30s → 60s → 120s (tối đa).
  - `deviceId.ts`: UUID lưu trong AsyncStorage.
- Tích hợp:
  - Vào ca: kiểm tra quyền; chưa "Always" thì mở màn `app/gps-permission.tsx` (hướng dẫn cấp quyền + tắt tối ưu pin, có ảnh hoặc text cho Xiaomi/Oppo/Vivo/Samsung). **Chặn vào ca** cho tới khi có quyền.
  - Vào ca thành công → `startTracking`. Kết ca → flush hết rồi `stopTracking`. Đăng xuất → flush rồi dừng.
  - Mở app trong khi ca đang mở mà task chưa chạy → tự `startTracking` lại.
  - Thanh trạng thái GPS trên `app/(tabs)/index.tsx`: 🟢 / 🟡 (không có điểm mới trong 60s) / 🔴 (thiếu quyền hoặc tracking tắt), kèm số điểm đang chờ gửi.
- **Nghiệm thu (thủ công, ghi kết quả vào PR):**
  - Dev build Android: vào ca, khoá màn hình 15 phút khi đang di chuyển → server nhận điểm liên tục.
  - Bật chế độ máy bay 5 phút rồi tắt → điểm được gửi bù, không trùng.
  - Kill app → mở lại → tracking chạy lại.
  - iOS tương tự.

---

## PHẦN F: KỊCH BẢN KIỂM THỬ CUỐI (sau Phase 6)

1. Chuyến 1 đơn bình thường: vào ca → bắt đầu → đến nhận → rời nhận → đến giao → giao xong → kết thúc → kết ca. Km có hàng, không hàng, km đơn, km ca đều có giá trị, `gps_coverage ≥ 90`.
2. Chuyến 2 đơn chở ghép, giao 2 điểm khác nhau. Sau khi giao đơn 1, trip ở `delivering`; `loaded_km` mỗi đơn đúng.
3. Đảo lái: lái A bấm Đảo lái giữa đường → điều hành gán lái B → B làm tiếp từ đúng bước. Km lượt A + km lượt B = km chuyến.
4. Kết ca khi còn chuyến dở → chuyến tự sang Đảo lái.
5. Huỷ 1 trong 2 đơn trước khi đến điểm giao → chuyến vẫn hoàn thành được.
6. Mất mạng giữa chuyến 10 phút → không mất km.
7. App phiên bản cũ đăng nhập → bị chặn, yêu cầu cập nhật.

## PHẦN G: TRIỂN KHAI
1. **Backup DB production** trước khi migrate.
2. Deploy backend và đặt `MIN_MOBILE_VERSION` bằng phiên bản app mới.
3. Phát hành app mới (build EAS production) cùng lúc. Chuẩn bị video và giải trình quyền vị trí nền cho App Store và Google Play.
4. Chạy thử 3–5 xe trong 2 tuần, theo dõi `gps_coverage` và tỉ lệ `km_needs_review` trước khi dùng số km để xuất hoá đơn.
