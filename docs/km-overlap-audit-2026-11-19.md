# Báo cáo rà soát tình trạng Km chuyến sau thấp hơn chuyến trước

- **Thời điểm kiểm tra:** 19/11/2026 10:34 (GMT+7)
- **Phạm vi dữ liệu:** Toàn bộ chuyến đã bắt đầu/hoàn thành trên hệ thống (loại trừ chuyến `pending` và `cancelled`)
- **Tổng số xe kiểm tra:** 68 xe
- **Trạng thái thao tác:** Chỉ đọc dữ liệu (không chỉnh sửa, không xóa bản ghi)

---

## 1. Tóm tắt kết quả

| Hạng mục kiểm tra | Kết quả | Ghi chú |
| :--- | :---: | :--- |
| Chuyến có `Km kết thúc < Km bắt đầu` trong cùng 1 chuyến | **0 chuyến** | Không có chuyến nào bị âm Km |
| Xe có `Km hiện tại (current_mileage) < Km kết thúc lớn nhất` | **0 xe** | 100% khớp với Km lớn nhất |
| Cặp chuyến liên tiếp có `Km bắt đầu chuyến sau < Km kết thúc chuyến trước` | **10 trường hợp (trên 8 xe)** | Gây chồng lấn / tính trùng Km giữa 2 chuyến |

---

## 2. Bảng tổng hợp 10 trường hợp phát hiện (8 xe)

| STT | Biển số | Chuyến trước (Mã chuyến / Đơn / Lái xe) | Km chuyến trước (`Bắt đầu → Kết thúc`) | Chuyến sau (Mã chuyến / Đơn / Lái xe) | Km chuyến sau (`Bắt đầu → Kết thúc`) | Chênh lệch | Nhóm nguyên nhân |
| :-: | :--- | :--- | :---: | :--- | :---: | :-: | :--- |
| **1** | **99H00973** | `#361` (`CD-2026-11-18-361`) · `ASG-445`<br>TX: Dương Văn Hùng · `18/11 04:19 → 05:26` | `206.899 → 206.900` | `#419` (`CD-2026-11-18-419`) · `ASG-508`<br>TX: Bùi Đức Tuấn · `18/11 10:21 → 11:33` | `206.899 → 206.906` | **`-1 km`** | Trùng mốc `start_km` |
| **2** | **20C04820** | `#129` (`CD-2026-11-16-129`) · `ASG-173`<br>TX: Hoàng Trường Anh · `16/11 22:10 → 23:32` | `429.505 → 429.522` | `#192` (`CD-2026-11-17-192`) · `ASG-248`<br>TX: Nguyễn Hồng Ngọc · `17/11 09:20 → 10:00` | `429.413 → 429.424` | **`-109 km`** | Chỉnh hạ Km gốc xe |
| **3** | **20C04820** | `#319` (`CD-2026-11-17-319`) · `ASG-419`<br>TX: Nguyễn Hồng Ngọc · `17/11 15:00 → 17:30` | `429.485 → 429.550` | `#323` (`CD-2026-11-17-323`) · `ASG-387`<br>TX: Nguyễn Hồng Ngọc · `17/11 21:35 → 23:26` | `429.485 → 429.559` | **`-65 km`** | Trùng mốc `start_km` |
| **4** | **20C13806** | `#337` (`CD-2026-11-17-337`) · `ASG-429`<br>TX: Nguyễn Ngọc Linh · `17/11 23:40 → 18/11 01:50` | `339.550 → 339.599` | `#350` (`CD-2026-11-18-350`) · **Không hàng**<br>TX: Nguyễn Ngọc Linh · `18/11 01:49 → 02:37` | `339.552 → 339.638` | **`-47 km`** | Chuyến không hàng tạo sớm |
| **5** | **20C04429** | `#139` (`CD-2026-11-16-139`) · `ASG-111`<br>TX: Nguyễn Mạnh Linh · `17/11 04:21 → 18/11 08:42` | `230.600 → 230.694` | `#167` (`CD-2026-11-17-167`) · **Không hàng**<br>TX: Vũ Duy Huy · `17/11 07:52 → 09:26` | `230.603 → 230.654` | **`-91 km`** | Chuyến cũ treo qua ngày |
| **6** | **20C06704** | `#619` (`CD-2026-11-19-619`) · `ASG-711`<br>TX: Nguyễn Đình Hùng · `19/11 02:34 → 08:28` | `465.544 → 465.672` | `#635` (`CD-2026-11-19-635`) · **Không hàng**<br>TX: Nguyễn Đình Hùng · `19/11 05:19 → 08:29` | `465.630 → 465.672` | **`-42 km`** | Chuyến không hàng tạo sớm + trùng `end_km` |
| **7** | **29H19892** | `#54` (`CD-2026-11-16-54`) · `ASG-45`<br>TX: Bùi Tiến Trung · `16/11 14:14 → 17/11 05:28` | `439.550 → 439.620` | `#166` (`CD-2026-11-17-166`) · **Không hàng**<br>TX: Bùi Tiến Trung · `17/11 07:14 → 18/11 08:17` | `439.564 → 439.636` | **`-56 km`** | Chuyến không hàng tạo sớm + treo qua ngày |
| **8** | **29H19892** | `#166` (`CD-2026-11-17-166`) · **Không hàng**<br>TX: Bùi Tiến Trung · `17/11 07:14 → 18/11 08:17` | `439.564 → 439.636` | `#201` (`CD-2026-11-17-201`) · `ASG-257`<br>TX: Nguyễn Việt Cường · `17/11 11:30 → 14:02` | `439.620 → 439.624` | **`-16 km`** | Hệ quả từ chuyến `#166` treo qua ngày |
| **9** | **20C13005** | `#585` (`CD-2026-11-18-585`) · `ASG-686`<br>TX: Chu Trung Khang · `18/11 21:55 → 19/11 01:39` | `415.473 → 415.519` | `#615` (`CD-2026-11-19-615`) · **Không hàng**<br>TX: Chu Trung Khang · `19/11 01:26 → 02:32` | `415.473 → 415.554` | **`-46 km`** | Chuyến không hàng tạo sớm |
| **10** | **20C18331** | `#589` (`CD-2026-11-18-589`) · `ASG-690`<br>TX: Đường Văn Mạnh · `18/11 22:51 → 19/11 02:27` | `292.017 → 292.069` | `#616` (`CD-2026-11-19-616`) · **Không hàng**<br>TX: Đường Văn Mạnh · `19/11 01:27 → 06:07` | `292.020 → 292.107` | **`-49 km`** | Chuyến không hàng tạo sớm |

---

## 3. Phân tích chi tiết từng xe bị ảnh hưởng

### 3.1. Nhóm Chuyến không hàng tạo sớm / Chuyến cũ treo qua ngày (7 trường hợp trên 6 xe)

1. **Xe `20C13806`** (`current_mileage: 339.660`)
   - `#337` (`ASG-429` – Nguyễn Ngọc Linh): `17/11 23:40 → 18/11 01:50` · `339.550 → 339.599` (49 km)
   - `#350` (**Không hàng** – Nguyễn Ngọc Linh): `18/11 01:49 → 02:37` · `339.552 → 339.638` (86 km)
   - **Chi tiết:** Chuyến không hàng `#350` được tạo lúc `01:49:56` (trước khi `#337` chốt `339.599` lúc `01:50`), nên `#350` giữ `start_km = 339.552`, bị trùng **47 km** (`339.552 → 339.599`).

2. **Xe `20C04429`** (`current_mileage: 230.811`)
   - `#139` (`ASG-111` – Nguyễn Mạnh Linh): `17/11 04:21 → 18/11 08:42` · `230.600 → 230.694` (94 km)
   - `#167` (**Không hàng** – Vũ Duy Huy): `17/11 07:52 → 09:26` · `230.603 → 230.654` (51 km)
   - `#283` (**Không hàng** – Nguyễn Đình Hùng): `17/11 17:27 → 18/11 08:34` · `230.654 → 230.694` (40 km)
   - **Chi tiết:** Chuyến `#139` không được chốt kết thúc trong ngày `17/11`, đến `08:42 18/11` mới bấm kết thúc với số Km cuối của xe (`230.694`), dẫn đến bao trùm luôn quãng đường của 2 chuyến không hàng `#167` và `#283` (**91 km** bị trùng).

3. **Xe `20C06704`** (`current_mileage: 465.672`)
   - `#619` (`ASG-711` – Nguyễn Đình Hùng): `19/11 02:34 → 08:28` · `465.544 → 465.672` (128 km)
   - `#635` (**Không hàng** – Nguyễn Đình Hùng): `19/11 05:19 → 08:29` · `465.630 → 465.672` (42 km)
   - **Chi tiết:** Lúc `05:11` xe đến giao hàng tại SEV (`465.630`), lúc `05:19` Điều hành tạo chuyến không hàng `#635` (`start_km = 465.630`). Đến `08:28` khi về bãi (`465.672`), tài xế nhập `end_km = 465.672` cho cả chuyến `#619` lẫn chuyến `#635`, dẫn đến **42 km** (`465.630 → 465.672`) bị tính 2 lần.

4. **Xe `29H19892`** (`current_mileage: 439.740`)
   - `#54` (`ASG-45` – Bùi Tiến Trung): `16/11 14:14 → 17/11 05:28` · `439.550 → 439.620` (70 km)
   - `#166` (**Không hàng** – Bùi Tiến Trung): `17/11 07:14 → 18/11 08:17` · `439.564 → 439.636` (72 km)
   - `#201` → `#365` (6 chuyến có hàng – Nguyễn Việt Cường): `17/11 11:30 → 18/11 06:09` · nối liên tục từ `439.620 → 439.636` (16 km)
   - **Chi tiết:** Chuyến không hàng `#166` lấy `start_km = 439.564` (thấp hơn `439.620` của `#54` là **56 km**) và bị treo sang sáng `18/11` mới chốt `439.636`, đè lên toàn bộ 6 chuyến (`#201` → `#365`) chạy trong ngày `17/11` (**16 km**).

5. **Xe `20C13005`** (`current_mileage: 415.554`)
   - `#585` (`ASG-686` – Chu Trung Khang): `18/11 21:55 → 19/11 01:39` · `415.473 → 415.519` (46 km)
   - `#615` (**Không hàng** – Chu Trung Khang): `19/11 01:26 → 02:32` · `415.473 → 415.554` (81 km)
   - **Chi tiết:** Chuyến không hàng `#615` tạo lúc `01:26` khi `#585` chưa cập nhật Km giao hàng (`415.519`), nên `#615` lấy lại `start_km = 415.473`, bị trùng **46 km** (`415.473 → 415.519`).

6. **Xe `20C18331`** (`current_mileage: 292.107`)
   - `#589` (`ASG-690` – Đường Văn Mạnh): `18/11 22:51 → 19/11 02:27` · `292.017 → 292.069` (52 km)
   - `#616` (**Không hàng** – Đường Văn Mạnh): `19/11 01:27 → 06:07` · `292.020 → 292.107` (87 km)
   - **Chi tiết:** Chuyến không hàng `#616` tạo lúc `01:27` khi `#589` mới ở mốc lấy hàng (`292.020`), chưa cập nhật Km giao hàng (`292.069`), nên bị trùng **49 km** (`292.020 → 292.069`).

---

### 3.2. Nhóm Chỉnh sửa Km / Trùng mốc `start_km` giữa các chuyến có hàng (3 trường hợp trên 2 xe)

1. **Xe `20C04820`** (`current_mileage: 429.710`)
   - **Cặp 1 (`-109 km`):**
     - `#129` (`ASG-173` – Hoàng Trường Anh): `16/11 22:10 → 23:32` · `429.505 → 429.522` (17 km)
     - `#192` (`ASG-248` – Nguyễn Hồng Ngọc): `17/11 09:20 → 10:00` · `429.413 → 429.424` (11 km)
     - Sau đó nối tiếp `#317`: `429.424 → 429.485` (61 km). Nguyên nhân do điều chỉnh hạ lại số Km thực tế của xe từ `429.522` xuống `429.413`.
   - **Cặp 2 (`-65 km`):**
     - `#319` (`ASG-419` – Nguyễn Hồng Ngọc): `17/11 15:00 → 17:30` · `429.485 → 429.550` (65 km)
     - `#323` (`ASG-387` – Nguyễn Hồng Ngọc): `17/11 21:35 → 23:26` · `429.485 → 429.559` (74 km)
     - Cả hai chuyến cùng bắt đầu từ `429.485`, dẫn đến trùng **65 km** (`429.485 → 429.550`).

2. **Xe `99H00973`** (`current_mileage: 207.047`)
   - `#361` (`ASG-445` – Dương Văn Hùng): `18/11 04:19 → 05:26` · `206.899 → 206.900` (1 km)
   - `#419` (`ASG-508` – Bùi Đức Tuấn): `18/11 10:21 → 11:33` · `206.899 → 206.906` (7 km)
   - Chênh lệch nhỏ (**`-1 km`**) do chuyến `#419` lấy `start_km = 206.899` thay vì `206.900`.

---

## 4. Nguyên nhân gốc rễ & Đề xuất xử lý

### Nguyên nhân gốc rễ
1. **Quy trình tạo Chuyến không hàng (`CreateEmptyRunAction`) gán cứng `start_km` ngay lúc tạo:**
   - Hiện tại khi Điều hành bấm *"Tạo chuyến không hàng"* trong lúc chuyến có hàng trước đó vẫn chưa hoàn tất, hệ thống lấy ngay `vehicle.current_mileage` tại thời điểm tạo làm `start_km` cho chuyến không hàng (và tạo luôn checkpoint `started`).
   - Khi chuyến có hàng trước đó hoàn tất sau với `end_km` cao hơn, `start_km` của chuyến không hàng không tự cập nhật theo nên bị nhỏ hơn `end_km` chuyến trước, gây **tính trùng Km** (chiếm **7/10** trường hợp).
2. **Chuyến cũ bị treo chưa bấm "Kết thúc đơn hàng" nhưng xe đã chạy chuyến tiếp theo:**
   - Khi tài xế quên chốt mốc `end` (hoặc bị hệ thống tự đóng ca trước khi kịp chốt), chuyến cũ bị treo qua ngày; sau đó tài xế quay lại bấm kết thúc với số Km mới của xe, làm `end_km` của chuyến cũ trùm qua cả các chuyến đã chạy ở giữa.

### Đề xuất khắc phục
1. **Xử lý dữ liệu hiện có (sau khi Sếp/Điều hành duyệt):**
   - Rà soát và điều chỉnh lại `start_km` / `end_km` của 8 xe nêu trên trên trang Quản trị Chuyến xe (ưu tiên sửa các chuyến không hàng `#350`, `#635`, `#615`, `#616`, `#166` và các cặp chuyến trên xe `20C04820`, `20C04429`).
2. **Ngăn chặn tái diễn về mặt hệ thống:**
   - Khi chuyến có hàng hoàn thành (`Trip::complete`), nếu xe đó đang có chuyến không hàng (`is_empty_run = true`, trạng thái `return_trip`) chưa kết thúc với `start_km < end_km` của chuyến vừa hoàn thành, tự động đẩy `start_km` (và checkpoint `started`) của chuyến không hàng lên bằng `end_km` vừa chốt (hoặc chỉ chốt `start_km` của chuyến không hàng khi tài xế thực sự bắt đầu chạy chuyến không hàng).
   - Không cho phép đóng chuyến có hàng hoặc tạo chuyến mới đè lên mốc Km của chuyến đang chạy chưa kết thúc.
