# Quy trình ghi nhận Km — Business Requirements

## Các mốc trạng thái đơn hàng (1 → 7)

| # | Trạng thái | Checkpoint hiện tại | Cách ghi nhận Km |
|---|---|---|---|
| 1 | Bắt đầu | `started` | Lấy Km từ lần nhập gần nhất |
| 2 | Đến điểm nhận | `arrived_pickup` | Nhập Km điểm nhận hàng |
| 3 | Đến điểm giao | `arrived_delivery` | Ghi nhận Km khi bấm bàn giao |
| 4 | Giao hàng xong | `completed` | Nhập Km điểm trả hàng → order.status = Completed |
| 5 | Về điểm đỗ | `end` (checkpoint type) | Nhập Km về điểm đỗ xe. **Không gắn với trip** (trip_id = null). Tạo trước khi kết thúc ca hoặc đổi xe. |
| 6 | Kết thúc đơn hàng | _(implicit)_ | Clear đơn trên app. Nếu tất cả orders Completed → gọi `POST /trips/{trip}/complete`. Nếu chưa xong → gọi `POST /trips/{trip}/complete` (trip → DriverSwap) hoặc `POST /shifts/{shift}/end-vehicle` (EndHandler). |
| 7 | Kết thúc ca | `end` (shift flow) | Chọn xe → Nhập Km kết thúc (qua `end-vehicle`) → Gọi `end` shift → Ghi nhận thời gian |

## TH1 — Giao xong hết, cùng xe về kết thúc ca

**Flow:**
1. Bắt đầu (started)
2. Đến điểm nhận (arrived_pickup, nhập km)
3. Đến điểm giao (arrived_delivery)
4. Giao hàng xong (completed, nhập km) → order.status = Completed
5. Lặp lại 2-4 cho các đơn còn lại
6. Kết thúc chuyến: `POST /trips/{trip}/complete` (trip → Completed)
7. Về điểm đỗ: `POST /shifts/{shift}/end-vehicle` (tạo checkpoint `end`)
8. Kết thúc ca: `POST /shifts/end`

**Hiện trạng:** ✅ Đã hỗ trợ đầy đủ.

## TH2 — Giao xong hết, đổi xe khác về kết thúc ca

**Flow:**
1. Như TH1 đến bước 6
2. Về điểm đỗ (xe cũ): `POST /shifts/{shift}/end-vehicle` (km xe cũ)
3. Đổi xe: `POST /shifts/switch-vehicle` (handover_km xe mới)
4. Về điểm đỗ (xe mới): `POST /shifts/{shift}/end-vehicle` (km xe mới)
5. Kết thúc ca: `POST /shifts/end`

**Hiện trạng:** ✅ Đã hỗ trợ (có gate bắt buộc `end` checkpoint trước khi switch).

## TH3 — Đổi xe theo yêu cầu điều hành

**Ví dụ 1**: Nhận xe mới, có đơn tiếp → nhập km mới, vòng lặp mới.
**Ví dụ 2**: Nhận xe mới, không có đơn → về điểm đỗ → kết thúc ca.
**Ví dụ 3**: Xe về điểm đỗ do quá km → **bắt buộc tạo đơn không hàng** → nhập km → đổi xe → đơn mới.

**Hiện trạng:**
- Ví dụ 1, 2: ✅ Hỗ trợ
- Ví dụ 3 (đơn không hàng): ✅ Có `POST /api/driver/empty-kilometers` để ghi nhận km không hàng. Cần Form riêng trên admin để điều hành tạo đơn không hàng.

## TH4 — Chưa giao xong, bàn giao xe, hết ca

**Flow:**
1. Đến điểm nhận (arrived_pickup, km=1300)
2. Chưa giao xong → bấm "Kết thúc đơn hàng" để bàn giao
   - **Cách 1**: `POST /trips/{trip}/complete` → trip.status = DriverSwap, orders chưa xong → DriverSwap
   - **Cách 2**: `POST /shifts/{shift}/end-vehicle` → EndHandler phát hiện trip active → trip.status = DriverSwap
3. Kết thúc ca: `POST /shifts/end`

**Sau đó (lái mới nhận đơn bàn giao):**
4. Lái mới nhận trip → tiếp tục từ trạng thái đang dở (vd: arrived_pickup → km=1310)
5. Hoàn thành giao hàng → completed → complete trip

**Hiện trạng:** ✅ Cả 2 cách đều hỗ trợ. Trên web trả về trạng thái "Đảo lái".

## Mapping API endpoints

| Bước | Endpoint | Ghi chú |
|------|----------|---------|
| Bắt đầu chuyến | `POST /api/driver/trips/{trip}/checkpoints` (started) | |
| Đến điểm nhận | `POST /api/driver/trips/{trip}/checkpoints` (arrived_pickup) | km_reading required |
| Đến điểm giao | `POST /api/driver/trips/{trip}/checkpoints` (arrived_delivery) | order_id + delivery_point_id |
| Giao hàng xong | `POST /api/driver/trips/{trip}/checkpoints` (completed) | order_id + delivery_point_id + km_reading |
| Kết thúc chuyến | `POST /api/driver/trips/{trip}/complete` | end_km required. Nếu all orders done → Completed. Nếu chưa → DriverSwap. |
| Về điểm đỗ / Rời xe | `POST /api/driver/shifts/{shift}/end-vehicle` | Tạo checkpoint `end`, km_reading required |
| Đổi xe | `POST /api/driver/shifts/switch-vehicle` | Cần `end` checkpoint trước. handover_km cho xe mới |
| Km không hàng | `POST /api/driver/empty-kilometers` | start_km, end_km, started_at, ended_at |
| Kết thúc ca | `POST /api/driver/shifts/end` | Cần `end` checkpoint trước. end_km lấy từ checkpoint. |

## Gaps & TODO

1. **Step 5 "Về điểm đỗ"**: `end` checkpoint type đảm nhiệm vai trò này nhưng tên gọi có thể gây nhầm lẫn. Cân nhắc đổi label "Kết thúc xe" → "Về điểm đỗ" trong enum CheckpointType nếu cần.
2. **Step 6 "Kết thúc đơn hàng"**: Hiện là implicit — clear đơn khỏi app khi trip kết thúc (Completed hoặc DriverSwap). Không có API riêng để "clear order on app" mà không kết thúc trip.
3. **TH3 Ví dụ 3**: Cần Form riêng trong Filament admin để điều hành gán đơn không hàng cho lái. Endpoint `empty-kilometers` đã có.
4. **Demo script**: Cần cập nhật `database/scripts/demo-delivery-point-selection.php` để dùng đúng endpoint mới (`/trips/{trip}/checkpoints` thay vì `/checkpoints`, `end-vehicle` trước `end`).
