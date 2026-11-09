# TMS-ASGT: Tài Liệu Nghiệp Vụ & Kiến Trúc Hệ Thống Chuẩn (Single Source of Truth)

> **Tài liệu nghiệp vụ & kỹ thuật hợp nhất**  
> Cập nhật: 11/2026 (Phản ánh 100% mã nguồn thực tế trên nhánh `feat/docker-frankenphp-octane-postgres`)  
> Thay thế toàn bộ các ghi chép, checklist và biên bản họp cũ trước đây.

---

## 1. Bối Cảnh Nghiệp Vụ & Thuật Ngữ (Domain & Terminology)

Hệ thống quản trị vận tải hàng hóa đường bộ (Transport Management System) phục vụ hoạt động vận tải của **ASGL (ASG Logistics)** tại thị trường miền Bắc:

| Thuật ngữ | Ý nghĩa | Đặc thù nghiệp vụ |
| :--- | :--- | :--- |
| **NBA** | Cảng hàng không quốc tế Nội Bài | Trạm trung chuyển chính, lấy/trả hàng không |
| **TN** | Thái Nguyên | Tuyến Samsung Thái Nguyên (SEVT) |
| **BN** | Bắc Ninh | Tuyến Samsung Bắc Ninh (SEV) và các KCN |
| **NBO** | Nội Bài Off-airport Cargo Terminal | Kho kéo dài / Kho hàng không ngoại cảng |
| **HHHK** | Hàng Hóa Hàng Không | Hàng hóa đi/đến sân bay, yêu cầu kiểm soát niêm chì, ảnh chụp mốc nghiêm ngặt |
| **Hàng Ngoài** | Hàng nội địa / Hàng thương mại | Hàng chở cho các đối tác công nghiệp, nhà máy |
| **GCR / DGR** | General Cargo / Dangerous Goods | Phân loại hàng thường hoặc hàng nguy hiểm (hóa chất, pin) |
| **Geofence** | Hàng rào địa lý ảo (GPS) | Bán kính toạ độ xác thực vị trí xe khi lái xe bấm xác nhận mốc |

---

## 2. Luồng Nghiệp Vụ Điều Hành (Dispatch Workflow - Web Admin)

Giao diện Điều hành được xây dựng trên **Filament 5**, tích hợp bản đồ React và Reverb realtime.

### 2.1. Tiếp nhận & Tạo Đơn Hàng (Orders)
1. **Tạo đơn hàng:**
   - **Phân loại tuyến:** NBA ➔ TN, NBA ➔ BN, NBO, hoặc Đi tỉnh.
   - **Thông tin hàng hóa:** Khách hàng, loại hàng (HHHK / Hàng ngoài), số kiện, trọng lượng thực tế (`weight`), trọng lượng tính cước (`chargeable_weight`), loại xe yêu cầu (thường, lạnh, fooc, cont, chống rung, cánh dơi).
   - **Lộ trình:** Điểm nhận hàng (`pickup_location_id`) và danh sách các điểm giao hàng (`delivery_points`).
2. **Kế hoạch & Tạo chuyến hàng loạt:** Cho phép nhân viên điều hành tạo nhanh các đơn hàng định kỳ lặp lại theo ca hàng ngày.

### 2.2. Ghép Xe & Gán Lái Xe (Vehicle Dispatch Board)
- **Bảng Điều Vận Trực Quan (React Widget):** Hiển thị danh sách xe, tài xế khả dụng, lọc theo tải trọng và khu vực hoạt động.
- **Quy tắc gán:**
  - Hệ thống tự động gợi ý tài xế đang trong ca làm việc (`DriverShift` active) đang lái chiếc xe đó.
  - Hỗ trợ đổi lái xe khác cho ca kế tiếp hoặc gán xe vệ tinh ngoài (chủ xe đối tác: Tam Bảo, HMA, Hải Như...).
  - Tự động tính toán trước cước phí vận chuyển dựa trên bảng cước (`FreightRate`).
- **Gửi lệnh vận chuyển:** Sau khi xác nhận phân công, đơn hàng chuyển trạng thái sang **`Đã gửi`**, kích hoạt Firebase Push Notification đến điện thoại tài xế và đẩy realtime qua Reverb.

### 2.3. Quản lý Chuyến & Các Thao Tác Đặc Biệt
- **Tạo chuyến quay đầu (Round-trip):** Nhân viên điều hành có thể bấm tạo chuyến quay đầu từ một chuyến đã có, hệ thống tự đảo ngược điểm đi/đến và đánh dấu cước khứ hồi.
- **Thu hồi lệnh / Hủy chuyến:**
  - *Thu hồi:* Chỉ được thực hiện khi lái xe **chưa bấm bắt đầu chuyến** trên ứng dụng di động.
  - *Hủy chuyến:* Ghi nhận trạng thái hủy để làm căn cứ đối soát chi phí phạt hủy với khách hàng.
- **Điều hành xử lý thay lái xe:** Trong trường hợp điện thoại tài xế hết pin, hỏng GPS hoặc quên thao tác, tài khoản Điều hành có quyền kết thúc chuyến, nhập km và đóng ca thay cho tài xế từ màn hình quản trị.

---

## 3. Luồng Ứng Dụng Di Động Lái Xe (Driver Mobile App - Expo React Native)

### 3.1. Đăng Nhập & Bắt Đầu Ca (Start Shift)
1. Tài xế đăng nhập bằng Số điện thoại / Mã nhân viên.
2. **Chọn xe & Vào ca:**
   - Lái xe chọn biển số xe nhận ca (gợi ý 4 số cuối).
   - Hệ thống tự động lấy số Km hiện tại của xe (`vehicle.current_mileage`) làm `shift.start_km`.
   - Ghi nhận thời gian và toạ độ GPS lúc vào ca.

### 3.2. Thực Hiện Chuyến & Gửi Mốc Hành Trình (Trip Checkpoints)
Lái xe chỉ được xử lý một chuyến tại một thời điểm (FIFO). Trong suốt hành trình, lái xe bấm các nút tương ứng với **7 loại mốc hành trình**:

```
[Bắt đầu chuyến] ➔ [Đến điểm lấy hàng] ➔ [Rời điểm lấy hàng] ➔ [Đến điểm giao hàng] ➔ [Giao hàng xong] ➔ [Hoàn thành chuyến]
```

| Mốc Checkpoint | Dữ liệu bắt buộc | Kiểm tra GPS Geofence | Mô tả |
| :--- | :---: | :---: | :--- |
| **`started`** | Toạ độ GPS | Không | Bắt đầu di chuyển đi nhận hàng |
| **`arrived_pickup`** | **Số Km đồng hồ**, Ảnh chụp, GPS | **Bắt buộc** | Đến kho lấy hàng, xác thực bán kính kho |
| **`left_pickup`** | Ảnh niêm chì / hàng, GPS | Không | Hoàn tất đóng hàng, bắt đầu đi giao |
| **`arrived_delivery`** | Toạ độ GPS, Ảnh chụp | **Bắt buộc** | Đến điểm trả hàng của khách |
| **`completed`** | Ảnh ký nhận biên bản, GPS | Không | Đã hạ hàng xong tại điểm giao |
| **`driver_swap`** | Số Km bàn giao, GPS | Không | Bàn giao xe giữa chừng cho tài xế khác |
| **`end`** | **Số Km đồng hồ cuối**, GPS | Không | Chốt số km khi hoàn thành chuyến hoặc trả xe |

> **Ràng buộc số Km:** Số Km nhập tại mốc sau **tuyệt đối không được nhỏ hơn** số Km của mốc trước đó hoặc Km bắt đầu của xe.

### 3.3. Bàn Giao Xe / Đảo Lái Giữa Chừng (Driver Swap)
- Khi lái xe cần đổi xe khác hoặc bàn giao hàng dở cho tài xế ca sau:
  1. Lái xe cũ bấm **"Bàn giao / Đảo lái"** và nhập số Km đồng hồ hiện tại ➔ Lưu checkpoint `driver_swap`.
  2. Lái xe mới vào ca hoặc nhận chuyến: Số Km bắt đầu của lái mới được tự động gán bằng số Km kết thúc của lái cũ.
  3. Hệ thống tách bạch rõ ràng phần Km có hàng của lái cũ và lái mới để tính lương/thưởng chính xác.

### 3.4. Kết Thúc Ca (End Shift) & Tự Động Thoát Ca
- **Thoát ca thủ công:** Lái xe bấm "Kết thúc ca", nhập số Km đồng hồ cuối cùng trên xe.
- **Tự động thoát ca (`shifts:auto-end`):** Scheduler tự động quét lúc **`08:05`** (ca đêm) và **`20:05`** (ca ngày). Nếu lái xe không còn đơn dở và quên chưa thoát ca, hệ thống sẽ tự động đóng ca với thời gian ân hạn 5 phút.

---

## 4. Công Thức & Động Cơ Tính Km (Km Calculation & Split Engine)

Hệ thống quản lý Km ở 2 cấp độ độc lập thông qua [`TripKmSplitService.php`](file:///Users/cuongpham/Deverlop/ASGL/tms-asgt/app/Services/TripKmSplitService.php):

### 4.1. Cấp Độ Chuyến Đi (Trip-Level)
* **Tổng Km chuyến (`total_km`):**
  $$\text{total\_km} = \text{end\_km} - \text{start\_km}$$
* **Km Có Hàng (`total_km_loaded`):**
  Được tính từ thời điểm xe đến điểm đóng hàng đầu tiên (`arrived_pickup`) cho đến khi hoàn thành điểm trả hàng cuối cùng (`completed` / `end`):
  $$\text{total\_km\_loaded} = \text{km}_{\text{giao xong}} - \text{km}_{\text{đến lấy hàng}}$$
* **Km Rỗng / Không Hàng (`total_km_empty`):**
  Là đoạn đường xe chạy không tải từ bãi xe đến điểm lấy hàng hoặc từ điểm trả hàng về bãi:
  $$\text{total\_km\_empty} = \text{total\_km} - \text{total\_km\_loaded}$$

### 4.2. Cấp Độ Ca Lái Xe (DriverShift-Level)
Một ca làm việc có thể lái nhiều xe hoặc chạy nhiều chuyến:
* Hệ thống gom các checkpoints theo từng `vehicle_id`.
* Tính tổng Km chạy của lái xe trong cả ca:
  $$\text{Shift Total Km} = \sum (\text{Km kết thúc xe} - \text{Km bắt đầu xe})$$
* Phân tách chính xác phần Km có hàng và Km chạy rỗng để phục vụ tính định mức tiêu hao nhiên liệu và tính lương tài xế.

### 4.3. Dự Phòng Khi Mất Tín Hiệu GPS (OSRM Fallback)
Nếu xe đi vào vùng mất sóng GPS hoặc tài xế quên bật định vị:
- Hệ thống gọi dịch vụ [`OsrmService.php`](file:///Users/cuongpham/Deverlop/ASGL/tms-asgt/app/Services/OsrmService.php) tính toán khoảng cách thực tế chuẩn theo mạng lưới đường bộ giữa các điểm mốc.
- Kết quả OSRM được lưu vào Cache để tái sử dụng ngay lập tức.

---

## 5. Những Điểm Cũ Đã Được Thay Đổi / Chuẩn Hóa So Với Thiết Kế Ban Đầu

| Vấn đề trước đây | Thay đổi chuẩn hóa hiện tại |
| :--- | :--- |
| **Cơ sở dữ liệu** | Đã loại bỏ hoàn toàn **SQLite**. Hệ thống chạy 100% trên **PostgreSQL 16** với 35 migration chuẩn hóa và index tối ưu. |
| **Web Server** | Thay thế Nginx + PHP-FPM truyền thống bằng **FrankenPHP + Laravel Octane (Worker Mode)**, tăng tốc độ xử lý API lên **gấp 18.6 lần**. |
| **Tự động thoát ca** | Bổ sung scheduler `shifts:auto-end` giải quyết triệt để tình trạng tài xế quên kết thúc ca làm sai lệch số Km. |
| **Xác thực mốc GPS** | Tích hợp thuật toán Geofence bán kính toạ độ (chống gian lận tài xế bấm xác nhận mốc khi chưa đến nơi). |
| **Tự động sao lưu** | Bổ sung cơ chế `php artisan db:backup` nén gzip, tự động dọn dẹp giữ 3 bản sao lưu gần nhất. |
