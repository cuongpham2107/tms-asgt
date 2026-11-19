# Báo cáo rà soát tình trạng Km chuyến sau thấp hơn chuyến trước

- **Thời điểm cập nhật:** 19/11/2026 11:10 (GMT+7)
- **Phạm vi dữ liệu:** Toàn bộ chuyến đã bắt đầu/hoàn thành trên hệ thống (loại trừ chuyến `pending` và `cancelled`)
- **Tổng số xe kiểm tra:** 68 xe
- **Ghi chú:** Đã xử lý xong 3 chuyến không hàng bị lỗi `start_km` tạo sớm (`20C13806` `#350`, `20C13005` `#615`, `20C18331` `#616`) và cập nhật logic tự đồng bộ trong `Trip::complete()`. Danh sách dưới đây chỉ còn lại **7 trường hợp (trên 5 xe)** cần Điều hành đối soát.

---

## 1. Tóm tắt kết quả còn tồn đọng

| Hạng mục kiểm tra | Kết quả | Ghi chú |
| :--- | :---: | :--- |
| Chuyến có `Km kết thúc < Km bắt đầu` trong cùng 1 chuyến | **0 chuyến** | Không có chuyến nào bị âm Km |
| Xe có `Km hiện tại (current_mileage) < Km kết thúc lớn nhất` | **0 xe** | 100% khớp với Km lớn nhất |
| Cặp chuyến liên tiếp có `Km bắt đầu chuyến sau < Km kết thúc chuyến trước` | **7 trường hợp (trên 5 xe)** | Cần Điều hành rà soát và xác nhận |

---

## 2. Bảng tổng hợp 7 trường hợp còn lại (5 xe)

| STT | Biển số | Chuyến trước (Mã chuyến / Đơn / Lái xe) | Km chuyến trước (`Bắt đầu → Kết thúc`) | Chuyến sau (Mã chuyến / Đơn / Lái xe) | Km chuyến sau (`Bắt đầu → Kết thúc`) | Chênh lệch | Nguyên nhân |
| :-: | :--- | :--- | :---: | :--- | :---: | :-: | :--- |
| **1** | **99H00973** | `#361` (`CD-2026-11-18-361`) · `ASG-445`<br>TX: Dương Văn Hùng · `18/11 04:19 → 05:26` | `206.899 → 206.900` | `#419` (`CD-2026-11-18-419`) · `ASG-508`<br>TX: Bùi Đức Tuấn · `18/11 10:21 → 11:33` | `206.899 → 206.906` | **`-1 km`** | Trùng mốc `start_km = 206.899` |
| **2** | **20C04820** | `#129` (`CD-2026-11-16-129`) · `ASG-173`<br>TX: Hoàng Trường Anh · `16/11 22:10 → 23:32` | `429.505 → 429.522` | `#192` (`CD-2026-11-17-192`) · `ASG-248`<br>TX: Nguyễn Hồng Ngọc · `17/11 09:20 → 10:00` | `429.413 → 429.424` | **`-109 km`** | Chỉnh hạ Km gốc xe từ `429.522` về `429.413` |
| **3** | **20C04820** | `#319` (`CD-2026-11-17-319`) · `ASG-419`<br>TX: Nguyễn Hồng Ngọc · `17/11 15:00 → 17:30` | `429.485 → 429.550` | `#323` (`CD-2026-11-17-323`) · `ASG-387`<br>TX: Nguyễn Hồng Ngọc · `17/11 21:35 → 23:26` | `429.485 → 429.559` | **`-65 km`** | Cả 2 chuyến cùng lấy `start_km = 429.485` |
| **4** | **20C04429** | `#139` (`CD-2026-11-16-139`) · `ASG-111`<br>TX: Nguyễn Mạnh Linh · `17/11 04:21 → 18/11 08:42` | `230.600 → 230.694` | `#167` (`CD-2026-11-17-167`) · **Không hàng**<br>TX: Vũ Duy Huy · `17/11 07:52 → 09:26` | `230.603 → 230.654` | **`-91 km`** | Chuyến `#139` treo qua ngày mới chốt `230.694` |
| **5** | **20C06704** | `#619` (`CD-2026-11-19-619`) · `ASG-711`<br>TX: Nguyễn Đình Hùng · `19/11 02:34 → 08:28` | `465.544 → 465.672` | `#635` (`CD-2026-11-19-635`) · **Không hàng**<br>TX: Nguyễn Đình Hùng · `19/11 05:19 → 08:29` | `465.630 → 465.672` | **`-42 km`** | Chuyến `#619` nhập nhầm `end_km = 465.672` thay vì `465.630` |
| **6** | **29H19892** | `#54` (`CD-2026-11-16-54`) · `ASG-45`<br>TX: Bùi Tiến Trung · `16/11 14:14 → 17/11 05:28` | `439.550 → 439.620` | `#166` (`CD-2026-11-17-166`) · **Không hàng**<br>TX: Bùi Tiến Trung · `17/11 07:14 → 18/11 08:17` | `439.564 → 439.636` | **`-56 km`** | Chuyến `#166` treo qua ngày & trùng chặng về của `#54` |
| **7** | **29H19892** | `#166` (`CD-2026-11-17-166`) · **Không hàng**<br>TX: Bùi Tiến Trung · `17/11 07:14 → 18/11 08:17` | `439.564 → 439.636` | `#201` (`CD-2026-11-17-201`) · `ASG-257`<br>TX: Nguyễn Việt Cường · `17/11 11:30 → 14:02` | `439.620 → 439.624` | **`-16 km`** | Hệ quả từ chuyến `#166` treo qua ngày |

---

## 3. Phân tích chi tiết từng xe cần đối soát (5 xe)

### 1. Xe `20C04820` (`current_mileage: 429.710`)
- **Cặp 1 (`-109 km`):**
  - `#129` (`ASG-173` – Hoàng Trường Anh): `16/11 22:10 → 23:32` · `429.505 → 429.522` (17 km)
  - `#192` (`ASG-248` – Nguyễn Hồng Ngọc): `17/11 09:20 → 10:00` · `429.413 → 429.424` (11 km)
  - Sau đó nối tiếp `#317`: `429.424 → 429.485` (61 km). Do điều chỉnh hạ lại số Km thực tế của xe từ `429.522` xuống `429.413`.
- **Cặp 2 (`-65 km`):**
  - `#319` (`ASG-419` – Nguyễn Hồng Ngọc): `17/11 15:00 → 17:30` · `429.485 → 429.550` (65 km)
  - `#323` (`ASG-387` – Nguyễn Hồng Ngọc): `17/11 21:35 → 23:26` · `429.485 → 429.559` (74 km)
  - Cả 2 chuyến cùng bắt đầu từ `429.485`, dẫn đến trùng **65 km** (`429.485 → 429.550`).

### 2. Xe `20C04429` (`current_mileage: 230.811`)
- `#139` (`ASG-111` – Nguyễn Mạnh Linh, `ASGL → SEVT 1-2`): `17/11 04:21 → 18/11 08:42` · `230.600 → 230.694` (94 km; giao xong tại `230.603`)
- `#167` (**Không hàng** – Vũ Duy Huy, `ASGL → NBA`): `17/11 07:52 → 09:26` · `230.603 → 230.654` (51 km) — **đúng**
- `#283` (**Không hàng** – Nguyễn Đình Hùng): `17/11 17:27 → 18/11 08:34` · `230.654 → 230.694` (40 km) — **đúng**
- **Chi tiết:** Chuyến có hàng `#139` đã giao xong tại `230.603` nhưng bị treo sang sáng `18/11` mới bấm kết thúc ở `230.694`, bao trùm lên cả 2 chuyến không hàng `#167` và `#283` (**91 km** bị trùng). Đề xuất sửa `end_km` của `#139` về `230.603`.

### 3. Xe `20C06704` (`current_mileage: 465.672`)
- `#619` (`ASG-711` – Nguyễn Đình Hùng, `SEVT 3-4 → SEV`): `19/11 02:34 → 08:28` · `465.544 → 465.672` (128 km; giao xong tại SEV ở `465.630`)
- `#635` (**Không hàng** – Nguyễn Đình Hùng, `SEV → ASGL`): `19/11 05:19 → 08:29` · `465.630 → 465.672` (42 km) — **đúng**
- **Chi tiết:** Chuyến không hàng `#635` đúng là `465.630 → 465.672` (`42 km`). Chuyến có hàng `#619` bị nhập nhầm `end_km = 465.672` thay vì `465.630` tại SEV, làm trùng **42 km**. Đề xuất sửa `end_km` của `#619` về `465.630`.

### 4. Xe `29H19892` (`current_mileage: 439.740`)
- `#54` (`ASG-45` – Bùi Tiến Trung): `16/11 14:14 → 17/11 05:28` · `439.550 → 439.620` (70 km; giao xong tại `439.558`)
- `#166` (**Không hàng** – Bùi Tiến Trung, `ASG → ASGL`): `17/11 07:14 → 18/11 08:17` · `439.564 → 439.636` (72 km)
- `#201 → #365` (6 chuyến có hàng – Nguyễn Việt Cường): `17/11 11:30 → 18/11 06:09` · nối liên tục từ `439.620 → 439.636` (16 km)
- **Chi tiết:** Chuyến không hàng `#166` lấy `start_km = 439.564` (thấp hơn `439.620` của `#54` là **56 km**) và treo qua ngày hôm sau mới chốt `439.636`, đè lên toàn bộ 6 chuyến (`#201 → #365`) chạy trong ngày `17/11` (**16 km**). Cần Điều hành đối soát xem `#166` có bị tạo trùng với chặng về của `#54` hay không.

### 5. Xe `99H00973` (`current_mileage: 207.047`)
- `#361` (`ASG-445` – Dương Văn Hùng): `18/11 04:19 → 05:26` · `206.899 → 206.900` (1 km)
- `#419` (`ASG-508` – Bùi Đức Tuấn): `18/11 10:21 → 11:33` · `206.899 → 206.906` (7 km)
- **Chi tiết:** Chênh lệch nhỏ (**`-1 km`**) do chuyến `#419` lấy `start_km = 206.899` thay vì `206.900`.
