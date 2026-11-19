# Báo cáo rà soát tình trạng Km chuyến sau thấp hơn chuyến trước

- **Thời điểm cập nhật:** 19/11/2026 11:28 (GMT+7)
- **Tổng số xe kiểm tra:** 68 xe
- **Trạng thái xử lý:** Đã sửa xong **7/10** trường hợp bị trùng Km (3 chuyến không hàng `#350`, `#615`, `#616` và 4 chuyến có hàng `#139`, `#619`, `#323`, `#419`). Hiện toàn hệ thống chỉ còn **2 xe (3 cặp)** cần Điều hành quyết định nghiệp vụ.

---

## 1. Các trường hợp còn lại (2 xe)

| STT | Biển số | Chuyến trước | Km chuyến trước | Chuyến sau | Km chuyến sau | Chênh lệch | Ghi chú / Phương án |
| :-: | :--- | :--- | :---: | :--- | :---: | :-: | :--- |
| **1** | **20C04820** | `#129` (`ASG-173` – Hoàng Trường Anh)<br>`16/11 22:10 → 23:32` | `429.505 → 429.522` (`17 km`) | `#192` (`ASG-248` – Nguyễn Hồng Ngọc)<br>`17/11 09:20 → 10:00` | `429.413 → 429.424` (`11 km`) | **`-109 km`** | Quản lý điều phối chủ động hạ lại Km gốc xe từ `429.522` về đúng đồng hồ `429.413`. **Không bị trùng Km** (mỗi chuyến tính đúng quãng đường), giữ nguyên. |
| **2** | **29H19892** | `#54` (`ASG-45` – Bùi Tiến Trung)<br>`16/11 14:14 → 17/11 05:28` | `439.550 → 439.620` (`70 km`) | `#166` (**Không hàng** – Bùi Tiến Trung)<br>`17/11 07:14 → 18/11 08:17` | `439.564 → 439.636` (`72 km`) | **`-56 km`** | `#54` đã tính 62 km không hàng (`439.558 → 439.620`), còn `#166` treo sang hôm sau mới chốt `439.636`. Cần Điều hành xác nhận có **hủy `#166`** (nếu trùng chặng về của `#54`) hay rút `end_km` của `#54` về `439.558` & `#166` về `439.620`. |
| **3** | **29H19892** | `#166` (**Không hàng** – Bùi Tiến Trung)<br>`17/11 07:14 → 18/11 08:17` | `439.564 → 439.636` (`72 km`) | `#201` (`ASG-257` – Nguyễn Việt Cường)<br>`17/11 11:30 → 14:02` | `439.620 → 439.624` (`4 km`) | **`-16 km`** | Hệ quả trực tiếp từ chuyến `#166` ở mục 2 (sẽ tự hết khi xử lý `#166`). |
