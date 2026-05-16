# ASG Transport Management System (ASG TMS)

> **Stack:** Laravel 12 · PHP 8.4 · Filament v3 · Alpine.js v3 · Tailwind CSS v4  
> **Mục đích file:** Tài liệu nghiệp vụ + checklist code – dùng cho vide-coding

---

## 📋 Mục lục

1. [Tổng quan hệ thống](#1-tổng-quan-hệ-thống)
2. [Vai trò & Phân quyền](#2-vai-trò--phân-quyền)
3. [Luồng nghiệp vụ tổng quát](#3-luồng-nghiệp-vụ-tổng-quát)
4. [Trạng thái đơn hàng (State Machine)](#4-trạng-thái-đơn-hàng-state-machine)
5. [Module: Điều hành (Web)](#5-module-điều-hành-web)
6. [Module: Lái xe (App)](#6-module-lái-xe-app)
7. [Module: Đội số liệu (Web)](#7-module-đội-số-liệu-web)
8. [Module: BDSC & Quản lý xe](#8-module-bdsc--quản-lý-xe)
9. [Module: Dashboard & Báo cáo](#9-module-dashboard--báo-cáo)
10. [Database Schema](#10-database-schema)
11. [Checklist Implementation](#11-checklist-implementation)
12. [Ghi chú tồn đọng (từ Check_TMS)](#12-ghi-chú-tồn-đọng-từ-check_tms)

---

## 1. Tổng quan hệ thống

| Thuộc tính      | Giá trị                                                |
| --------------- | ------------------------------------------------------ |
| Tên             | ASG Transport Management System                        |
| Mục tiêu        | Thay thế mô hình vận hành phụ thuộc Google Sheets      |
| Nền tảng Web    | Filament Admin – Điều hành, Số liệu, Quản lý, BDSC     |
| Nền tảng Mobile | App lái xe (có thể dùng Filament + PWA hoặc API riêng) |
| Giai đoạn 1     | Điều phối, phân xe, lái xe cập nhật hành trình         |
| Giai đoạn 2     | Đội số liệu, tính cước, bảng kê, báo cáo               |

### Loại hình vận chuyển

```
HHHK (Hàng hóa hàng không)          Hàng ngoài
├── Điểm: NBA, TN, BN, NBO           ├── Điểm: NBA, TN, BN, Đi tỉnh
├── Đơn gọn: chỉ cần điểm đi/đến    └── Đơn đầy đủ: sender/receiver + hàng hóa
└── Tần suất cao → cần tạo nhanh
```

---

## 2. Vai trò & Phân quyền

| Role          | Nền tảng   | Quyền chính                                       |
| ------------- | ---------- | ------------------------------------------------- |
| `dispatcher`  | Web        | Tạo đơn, gán xe/lái, gửi lệnh, thu hồi, hủy       |
| `driver`      | Mobile App | Nhận lệnh, cập nhật trạng thái, nhập km, chụp ảnh |
| `data_team`   | Web        | Bổ sung kg/kiện, tính cước, xuất bảng kê          |
| `maintenance` | Web        | Quản lý BDSC, đăng kiểm, cảnh báo                 |
| `manager`     | Web/Mobile | Xem dashboard, báo cáo, không chỉnh sửa           |
| `admin`       | Web        | Full access, cấu hình hệ thống                    |

### Filament Panel Setup

```php
// Giai đoạn 1: dùng 1 panel duy nhất với role-based navigation
// Giai đoạn 2: có thể tách thêm panel riêng cho driver (API)

FilamentServiceProvider::panel('admin')
    ->authGuard('web')
    ->navigationGroups([
        'Điều hành',
        'Quản lý xe & Lái xe',
        'Số liệu & Cước',
        'BDSC',
        'Dashboard & Báo cáo',
    ]);
```

---

## 3. Luồng nghiệp vụ tổng quát

```
[KH phát sinh nhu cầu]
        ↓
[Điều phối tạo lệnh vận chuyển]  ← Filament CreateRecord / quick-create form
        ↓
[Hệ thống gợi ý xe: tải trọng, tình trạng, ca trực, chứng chỉ, vị trí GPS]
        ↓
[Điều phối gán xe + lái → Gửi lệnh]  ← status: "Đã gửi"
        ↓
[Lái xe nhận lệnh trên App]  ← status: "Đi nhận hàng"
        ↓
[Lái xe cập nhật các mốc hành trình]  ← real-time status update
        ↓
[Hệ thống ghi nhận km, trạng thái có hàng/không hàng]
        ↓
[Dữ liệu → Đội số liệu bổ sung thông tin]  ← status: "Hoàn thành"
        ↓
[Hệ thống sinh bảng kê, báo cáo, đối soát]
```

---

## 4. Trạng thái đơn hàng (State Machine)

```
draft ──────────────────────────────────────────────────── cancelled
  │                                                              ↑
  ↓ [gán xe + lái]                                    [điều hành hủy]
assigned (Chưa gửi)                                              │
  │                                                              │
  ↓ [gửi lệnh]                               [trước khi → delivering]
sent (Đã gửi)
  │
  ↓ [lái xe bấm Bắt đầu]
picking (Đi nhận hàng)
  │
  ↓ [lái xe xác nhận đến điểm nhận]
at_pickup (Đến điểm nhận hàng)  ← ⚠️ cảnh báo nếu > 60 phút
  │
  ↓ [lái xe bấm Bắt đầu đi giao]
delivering (Đi giao hàng)
  │
  ↓ [lái xe xác nhận đến điểm giao]
at_delivery (Đến điểm giao hàng)  ← ⚠️ cảnh báo nếu > 60 phút
  │
  ↓ [lái xe xác nhận giao xong]
delivered (Giao hàng xong)
  │
  ↓ [đủ thông tin đầy đủ]
completed (Hoàn thành)  ← Đội số liệu có thể truy cập

Trạng thái đặc biệt:
  driver_swap (Đảo lái)  ← 1 xe có thể đổi lái giữa chừng
```

### Enum PHP

```php
enum OrderStatus: string
{
    case Draft      = 'draft';
    case Assigned   = 'assigned';
    case Sent       = 'sent';
    case Picking    = 'picking';
    case AtPickup   = 'at_pickup';
    case Delivering = 'delivering';
    case AtDelivery = 'at_delivery';
    case Delivered  = 'delivered';
    case Completed  = 'completed';
    case Cancelled  = 'cancelled';
    case DriverSwap = 'driver_swap';

    public function label(): string { /* ... */ }
    public function color(): string { /* badge color */ }
    public function canCancel(): bool
    {
        return in_array($this, [self::Draft, self::Assigned, self::Sent,
                                 self::Picking, self::AtPickup, self::Delivering]);
    }
    public function canRecall(): bool  // Thu hồi lệnh
    {
        return in_array($this, [self::Sent, self::Picking, self::AtPickup]);
    }
}
```

---

## 5. Module: Điều hành (Web)

### 5.1 Tạo đơn hàng

#### HHHK – Tạo nhanh (Kế hoạch)

**Input form:**

| Field                | Type            | Ghi chú                                                                    |
| -------------------- | --------------- | -------------------------------------------------------------------------- |
| `transport_type`     | select          | HHHK / Hàng ngoài                                                          |
| `destination_zone`   | select          | NBA / TN / BN / NBO (HHHK) hoặc NBA / TN / BN / Đi tỉnh                    |
| `customer_id`        | select + manual | Searchable, có gợi ý                                                       |
| `pickup_point`       | select + manual | Điểm đi                                                                    |
| `delivery_points`    | repeater        | Nhiều điểm trả, có thể thêm/xóa                                            |
| `expected_load_time` | datetime        | Thời gian dự kiến đóng hàng                                                |
| `vehicle_owner`      | select          | ASGT (mặc định) / Tam bảo / HMA / VT123 / Hải như / ACE / CBT / manual     |
| `vehicle_id`         | select + manual | Gợi ý khi nhập 4 số cuối biển                                              |
| `tonnage`            | number          | Auto từ xe hoặc nhập tay                                                   |
| `vehicle_type`       | select          | Xe thường (mặc định) / lạnh / chống rung / cont / fooc / cánh dơi / manual |
| `driver_id`          | select          | Auto từ app lái xe, hoặc chọn tay                                          |
| `notes`              | textarea        | Yêu cầu đặc biệt                                                           |
| `bulk_count`         | number          | Tạo hàng loạt N đơn cùng loại                                              |

**Tính năng đặc biệt:**

- Tạo hàng loạt: `bulk_count` → clone N đơn
- Auto-schedule: cài đặt tự động tạo đơn định kỳ hàng ngày
- Hiển thị theo User (chỉ xem đơn mình tạo, có toggle "xem tất cả")
- Gán biển xe → tự động chuyển sang tab Quản lý đơn hàng

#### Hàng ngoài – Đơn đầy đủ

Thêm các field:

| Field              | Type                        |
| ------------------ | --------------------------- |
| `sender_name`      | text                        |
| `sender_contact`   | text                        |
| `sender_phone`     | text                        |
| `receiver_name`    | text                        |
| `receiver_contact` | text                        |
| `receiver_phone`   | text                        |
| `cargo_name`       | text                        |
| `cargo_units`      | integer                     |
| `cargo_weight`     | decimal                     |
| `cargo_type`       | select (GCR mặc định / DGR) |

---

### 5.2 Quản lý đơn hàng

**Danh sách đơn hàng (Filament Table):**

| Column               | Mô tả                           |
| -------------------- | ------------------------------- |
| `id`                 | Mã đơn                          |
| `customer`           | Khách hàng                      |
| `route`              | Hành trình (điểm đi → điểm đến) |
| `expected_load_time` | Thời gian đóng hàng             |
| `vehicle.plate`      | Biển số xe                      |
| `driver.name`        | Lái xe                          |
| `status`             | Badge màu theo trạng thái       |
| `sent_at`            | Thời gian gửi lệnh              |

**Actions trên từng đơn:**

```
[...] Menu
 ├── Gán xe        → chỉ khi assigned trở xuống
 ├── Gán lái       → chỉ khi lái còn hạn bằng
 ├── Gửi lệnh      → sent
 ├── Thu hồi       → chỉ trước khi delivering
 ├── Sửa chuyến    → chỉ trước khi accepted (sent)
 ├── Hủy chuyến    → chỉ trước khi at_delivery; ghi nhận "hủy chuyến"
 ├── Tạo quay đầu  → tạo đơn mới, đảo điểm đi/đến, flag "chuyến quay đầu"
 ├── Xóa đơn       → vào thùng rác, xem lại được
 └── Truy xuất thông tin xe+lái → copy to clipboard
```

**Thông tin copy cho khách:**

```
Biển số xe: ___
Số đăng ký xe: ___
Tải trọng / Số cửa: ___
Họ tên lái xe: ___
CCCD: ___ (ngày cấp, ngày sinh, địa chỉ thường trú, SĐT)
Số GPLX: ___
Chứng chỉ đi kèm: ___
```

**Bản đồ tổng quan:**

- Map hiển thị tất cả xe đang hoạt động (icon + trạng thái)
- Kéo từ GPS realtime
- Lọc theo điểm: NBA / TN / BN / NBO

---

### 5.3 Gợi ý xe tự động

```php
// Service: VehicleSuggestionService
// Tiêu chí sắp xếp (theo thứ tự ưu tiên):
// 1. Xe gần điểm lấy hàng nhất (GPS distance)
// 2. Tải trọng phù hợp với đơn
// 3. Trạng thái sẵn sàng (ON, không BDSC, giấy tờ hợp lệ)
// 4. Lái xe đang trong ca trực + còn hạn bằng + chứng chỉ phù hợp
// 5. Xe đăng ký vào nhà máy (nếu có yêu cầu)
```

---

### 5.4 Cảnh báo realtime (Filament Notifications / Alpine polling)

| Cảnh báo                    | Điều kiện                         | Kênh                       |
| --------------------------- | --------------------------------- | -------------------------- |
| Quá tốc độ                  | GPS > ngưỡng                      | Toast + âm thanh + ghi log |
| Đi sai tuyến                | Lệch tuyến cố định đã cài         | Toast + âm thanh + ghi log |
| Chờ tại điểm nhận > 60 phút | Thời gian tại `at_pickup` > 60p   | Toast + badge đơn          |
| Chờ tại điểm giao > 60 phút | Thời gian tại `at_delivery` > 60p | Toast + badge đơn          |
| Dừng đỗ > 5 phút            | GPS không di chuyển > 5p          | Toast + âm thanh           |
| Km xe thuê > 3000 km/tháng  | Trigger khi chọn xe               | Cảnh báo modal             |

---

### 5.5 Điều hành thay lái xe (fallback)

Khi lái xe bị lỗi máy hoặc quên, điều hành có thể:

- Thoát ca thay
- Nhập km thay
- Kết thúc chuyến thay
- Chỉnh sửa thông tin đơn → ghi log lịch sử chỉnh sửa (ai, lúc nào, thay đổi gì)

---

## 6. Module: Lái xe (App)

> Xây dựng dưới dạng **API + Mobile UI** hoặc **PWA Livewire**

### 6.1 Đầu ca

```
[Đăng nhập ID/Pass]
        ↓
[Chọn vào ca: ghi nhận giờ + GPS]
        ↓
[Chọn loại ca: Cả ca / Nửa ca ngày / Nửa ca đêm]
        ↓
[Chọn xe: nhập 4 số cuối → gợi ý biển đầy đủ]
        ↓
[Xác nhận → hiển thị bảng tóm tắt + Km gần nhất trước đó]
        ↓
[Màn hình Danh sách chuyến]
```

### 6.2 Thực hiện chuyến

```
[Danh sách chuyến] (sắp xếp theo thời gian đóng hàng sớm nhất)
        ↓ [chọn chuyến]
[Chi tiết đơn hàng]
        ↓ [bấm Bắt đầu]

BƯỚC 1 – Đến điểm nhận hàng:
  - GPS xác nhận vị trí
  - Nhập Km đến (tự hiển thị km gần nhất, lái chỉ sửa số cuối)
  - Validation: Km mới >= Km trước đó
  - Chụp ảnh xe tại điểm
  - → status: at_pickup

BƯỚC 2 – Bắt đầu đi giao hàng:
  - GPS log
  - Chụp ảnh tình trạng hàng hóa, seal, khóa
  - Ghi chú bất thường (freetext hoặc ghi âm → chuyển text)
  - → status: delivering

BƯỚC 3 – Đến điểm giao hàng:
  - GPS xác nhận vị trí
  - Chụp ảnh xe tại điểm
  - → status: at_delivery

BƯỚC 4 – Giao hàng xong:
  - Nhập Km kết thúc (validation: >= Km trước)
  - Chụp ảnh POD + tình trạng hàng hóa
  - Ghi chú bất thường
  - → status: delivered

BƯỚC 5 – Kết thúc chuyến:
  - Bấm "Kết thúc" → quay về Danh sách chuyến
```

**Nguyên tắc quan trọng:**

- 1 xe / 1 đơn → chỉ gán 1 lái. Nếu gán lái mới → tự out lái cũ
- 1 lái có thể gán nhiều xe / nhiều đơn
- **Lái chỉ làm 1 đơn 1 lần:** chưa kết thúc / đảo lái đơn 1 → không bắt đầu đơn 2

### 6.3 Km không hàng (tuyến riêng)

```
Khi lái xe đi không hàng (điều xe, nội bộ, BDSC...):
  → Tạo "Km không hàng" thủ công
  - Điểm đi + Km đi
  - Điểm đến + Km đến
  - Ghi chú lý do

Hệ thống tổng hợp:
  Km có hàng  = Km tại at_pickup → Km tại delivered
  Km không hàng = tổng Km lái - tổng Km có hàng
  (Tính theo từng lái xe, xe, chuyến, khách hàng, kỳ lương)
```

### 6.4 Đảo lái

| Tình huống           | Xử lý                                                                 |
| -------------------- | --------------------------------------------------------------------- |
| Bàn giao ca (về nhà) | Nhập Km → Kết thúc ca → Điều hành gán lái mới                         |
| Hàng chưa hạ được    | Nhập Km xe hiện tại → Đảo lái → Lái mới nhận từ Km đó                 |
| Đơn sau đảo lái      | Không hiển thị trong app lái cũ. Điều hành gán trên web → app lái mới |
| Lái mới nhận đơn     | Km bắt đầu = Km kết thúc của lái cũ                                   |

### 6.5 Kết thúc ca

```
[Nút "Kết thúc ca" ở góc phải màn hình Danh sách chuyến]
  → Nhập Km kết thúc (>= Km gần nhất)
  → GPS ghi nhận
  → Bảng tóm tắt ca: tổng Km có hàng / không hàng
  → Lái tiếp theo vào ca: hiển thị Km bắt đầu = Km kết thúc ca trước
```

---

## 7. Module: Đội số liệu (Web)

### 7.1 Danh sách chuyến hoàn thành

Filament Table, filter theo:

- Khách hàng / Kỳ đối chiếu / Loại hình / Ngày

### 7.2 Nhập liệu bổ sung

**HHHK:**

| Field             | Ghi chú                  |
| ----------------- | ------------------------ |
| `cargo_units`     | Kiện                     |
| `cargo_weight_kg` | Cân (kg)                 |
| `customer_id`     | Khách hàng (nếu chưa có) |

**Hàng ngoài:**

| Field              | Ghi chú                              |
| ------------------ | ------------------------------------ |
| `surcharges`       | Phụ phí, phát sinh ngoài chi phí gốc |
| `waiting_hours`    | Giờ chờ phát sinh                    |
| `storage_ticket`   | Vé kho                               |
| `extra_points`     | Điểm trả thêm                        |
| `additional_costs` | Bảng ghi nhận chi phí phát sinh      |

### 7.3 Tính cước tự động

```php
// Hệ thống tự động áp cước sau khi đội số liệu hoàn thiện
// Dựa trên: tuyến đường + khách hàng + tải trọng xe + loại hàng
// Có thể điều chỉnh thủ công nếu cần
```

### 7.4 Bảng kê & Xuất

```
Bảng kê theo khách hàng / theo kỳ đối chiếu
  → Chốt bảng kê
  → Gửi kế toán xuất hóa đơn

Kiểm tra GPS: Điều chỉnh lại thời gian ghi nhận thực tế nếu cần
```

---

## 8. Module: BDSC & Quản lý xe

### 8.1 Quản lý xe

| Field                 | Ghi chú                       |
| --------------------- | ----------------------------- |
| `plate_number`        | Biển số                       |
| `registration_number` | Số đăng ký                    |
| `tonnage`             | Tải trọng                     |
| `door_count`          | Số cửa                        |
| `vehicle_type`        | Loại xe                       |
| `owner`               | Chủ xe                        |
| `status`              | ON / OFF                      |
| `off_reason`          | BDSC / Đăng kiểm / Bất thường |

**Trạng thái xe theo ca:**

```
ON  → Sẵn sàng nhận lệnh
OFF → BDSC / Không lái / Đăng kiểm
```

**Trạng thái vận hành (realtime):**

- Chờ hàng (CH)
- Không hàng (KH)
- Đi lấy hàng
- Lấy hàng xong
- Đi trả hàng
- Trả hàng xong
- Sẵn sàng
- Không lái
- BDSC
- Đăng kiểm

### 8.2 Kế hoạch BDSC

```
Nhập kế hoạch BDSC:
  - Xe
  - Điểm thực hiện (đi/đến)
  - Thời gian bắt đầu / kết thúc
  - Yêu cầu thực hiện
  - Chi phí

Cảnh báo:
  - Khi chọn xe có kế hoạch BDSC bắt buộc → hiện modal cảnh báo
  - Hạn đăng kiểm sắp hết
  - Hạn bằng lái sắp hết
  - Hạn giấy tờ xe sắp hết
```

### 8.3 Quản lý lái xe

| Field            | Ghi chú                                  |
| ---------------- | ---------------------------------------- |
| `full_name`      | Họ tên                                   |
| `cccd`           | CCCD (ngày cấp, ngày sinh, địa chỉ, SĐT) |
| `license_number` | Số GPLX                                  |
| `license_expiry` | Hạn bằng lái                             |
| `certificates`   | Chứng chỉ đi kèm (JSON array)            |
| `status`         | Active / Inactive                        |

---

## 9. Module: Dashboard & Báo cáo

### 9.1 Dashboard Realtime

| Widget                                 | Dữ liệu                       |
| -------------------------------------- | ----------------------------- |
| Map tổng quan                          | Vị trí + trạng thái tất cả xe |
| Bảng kiểm soát tình trạng xe           | ON/OFF, theo điểm             |
| Tổng hợp xe theo điểm + trạng thái     | NBA/TN/BN/NBO × trạng thái    |
| Tổng nhân lực trong ca trực            | Số lái đang trực              |
| Lịch lái đang trực + Km đã đi trong ca | Bảng theo lái xe              |
| Kiểm soát Km xe thuê/tháng             | ⚠️ cảnh báo > 3000 km         |

### 9.2 Báo cáo vận tải

| Báo cáo              | Chiều phân tích                     |
| -------------------- | ----------------------------------- |
| Sản lượng vận chuyển | Theo loại hình / ngày / tháng / năm |
| Kiểm soát Km xe thuê | Theo xe / tháng                     |
| Km theo xe           | CH/KH riêng                         |
| Km theo lái xe       | CH/KH riêng                         |
| Công lái xe          | Bảng chấm công                      |
| Doanh thu hàng ngoài | Theo khách hàng / tải trọng         |

### 9.3 Filament Widgets

```php
// Dùng Filament Stats Overview Widget + Chart Widget
// Filament Map Widget (dùng package leaflet hoặc google maps)
// Filter: date range, zone, customer, driver, vehicle
```

---

## 10. Database Schema

### Core Tables

```sql
-- Khách hàng
customers (id, name, code, contact, phone, address, ...)

-- Xe
vehicles (
  id, plate_number, registration_number, owner, tonnage,
  door_count, vehicle_type, status [on/off], off_reason,
  registration_expiry, inspection_expiry
)

-- Lái xe
drivers (
  id, user_id, full_name, cccd, cccd_issue_date,
  date_of_birth, address, phone, license_number,
  license_expiry, certificates (json), status
)

-- Điểm vận chuyển
locations (id, code, name, zone [NBA/TN/BN/NBO/Tỉnh], address, lat, lng)

-- Bảng giá cước
freight_rates (id, customer_id, origin_id, destination_id, tonnage, rate, ...)

-- Đơn hàng
orders (
  id, order_number, transport_type [hhhk/hang_ngoai],
  destination_zone, customer_id, origin_id,
  expected_load_time, vehicle_owner, vehicle_id, driver_id,
  tonnage, vehicle_type, notes, status,
  -- Hàng ngoài
  sender_name, sender_contact, sender_phone,
  receiver_name, receiver_contact, receiver_phone,
  cargo_name, cargo_units, cargo_weight, cargo_type,
  -- Flags
  is_return_trip, is_cancelled,
  created_by (user_id), sent_at, cancelled_at,
  -- Tính cước
  freight_rate, surcharges, total_cost,
  -- Số liệu team bổ sung
  data_cargo_units, data_cargo_weight,
  -- Timestamps
  created_at, updated_at
)

-- Điểm trả hàng (nhiều điểm)
order_delivery_points (id, order_id, location_id, address, contact, phone, sequence)

-- Mốc hành trình (lái xe cập nhật)
order_milestones (
  id, order_id, driver_id, vehicle_id,
  milestone [start/at_pickup/departing/at_delivery/delivered/end],
  recorded_at, km, gps_lat, gps_lng,
  photo_path, notes
)

-- Km không hàng
empty_trips (
  id, driver_id, vehicle_id,
  origin, destination, km_start, km_end,
  reason, recorded_at
)

-- Ca trực lái xe
driver_shifts (
  id, driver_id, vehicle_id,
  shift_type [full/half_day/half_night],
  start_time, end_time, start_gps, end_gps,
  km_start, km_end
)

-- Lịch sử chỉnh sửa đơn hàng
order_edit_logs (id, order_id, edited_by, field, old_value, new_value, edited_at)

-- Đổi lái (đảo lái)
driver_handovers (id, order_id, from_driver_id, to_driver_id, km_handover, reason, created_at)

-- BDSC
maintenance_plans (
  id, vehicle_id, type, origin, destination,
  scheduled_start, scheduled_end, actual_start, actual_end,
  requirements, cost, status [pending/done]
)

-- Cảnh báo GPS
gps_alerts (id, order_id, vehicle_id, alert_type [speed/route/idle],
             value, threshold, created_at)
```

---

## 11. Checklist Implementation

### Phase 1A – Nền tảng

- [ ] Laravel 12 setup + Filament v3 install
- [ ] Auth + Role system (Spatie Permission)
- [ ] Models + Migrations (tất cả bảng core)
- [ ] Seeders: Customers, Locations, Vehicles, Drivers

### Phase 1B – Điều hành

- [ ] **OrderResource** (Filament): CreateForm, ListTable, EditRecord
    - [ ] HHHK form
    - [ ] Hàng ngoài form
    - [ ] Bulk create (tạo hàng loạt)
    - [ ] Filter theo zone (NBA/TN/BN/NBO)
    - [ ] View per user (toggle "xem tất cả")
- [ ] **Order Actions**: Gán xe, Gán lái, Gửi lệnh, Thu hồi, Hủy, Tạo quay đầu, Xóa
    - [ ] Validation điều kiện theo trạng thái
- [ ] **VehicleSuggestionService**: gợi ý xe theo GPS + tải trọng + ca trực
- [ ] **Truy xuất thông tin xe+lái**: copy to clipboard
- [ ] **OrderEditLog**: ghi log lịch sử chỉnh sửa

### Phase 1C – Lái xe (API/PWA)

- [ ] API endpoints cho driver app
    - [ ] `POST /api/driver/login`
    - [ ] `POST /api/driver/shift/start`
    - [ ] `POST /api/driver/shift/end`
    - [ ] `GET /api/driver/orders`
    - [ ] `POST /api/orders/{id}/milestone`
    - [ ] `POST /api/empty-trips`
    - [ ] `POST /api/driver/handover`
- [ ] Upload ảnh tại mỗi milestone
- [ ] GPS logging (batch update)
- [ ] Validation Km (không nhỏ hơn Km trước)
- [ ] Logic "1 đơn 1 lúc" (block bước 2 nếu chưa xong bước 1)

### Phase 1D – Realtime & Cảnh báo

- [ ] Pusher / Laravel Echo setup
- [ ] GPS alert service (speed, route, idle)
- [ ] Wait time alert (60 phút tại điểm)
- [ ] Filament Notifications (toast + âm thanh)
- [ ] Map widget (Leaflet.js embedded)

### Phase 2A – Số liệu & Cước

- [ ] **DataTeamOrderResource**: filter completed orders
- [ ] Nhập bổ sung: kg, kiện, phụ phí
- [ ] **FreightRateService**: tính cước tự động
- [ ] Bảng kê theo khách hàng / kỳ
- [ ] Export Excel/PDF

### Phase 2B – BDSC & Báo cáo

- [ ] **MaintenanceResource**: kế hoạch BDSC
- [ ] Cảnh báo hạn giấy tờ (queue job hàng ngày)
- [ ] Dashboard widgets
- [ ] Report filters: ngày/tuần/tháng/năm
- [ ] Báo cáo Km (xe, lái, CH/KH)

---

## 12. Ghi chú tồn đọng (từ Check_TMS)

Các tính năng **chưa làm** hoặc **làm chưa đúng** theo file `Check_TMS.xlsx`:

### ❌ Chưa làm (NO)

**Điều hành:**

- Tạo đơn kế hoạch: phân tách theo zone NBA/TN/BN/NBO
- Bulk create (tạo hàng loạt)
- Hiển thị đơn theo User (chỉ xem của mình)
- Auto-schedule đơn định kỳ hàng ngày
- Tự động chuyển sang Quản lý đơn khi gán biển xe
- Chỉ cần gán biển → "xác nhận phân công" (lái auto từ app)
- Tạo chuyến quay đầu
- Danh mục đơn: phân tách theo zone
- Có thể tạo đơn + gán xe luôn ở Quản lý đơn (không chỉ ở Kế hoạch)
- Xem chi tiết đơn + chỉnh sửa ngay khi click vào dòng
- Trạng thái tự động thành "Hoàn thành" khi đủ thông tin
- Các trạng thái đầy đủ (10 trạng thái)
- Đảo lái
- Truy xuất thông tin xe+lái để copy gửi khách
- Điều hành hoàn thiện thay lái xe

**Lái xe:**

- Chọn vào ca với GPS
- Chọn loại ca (cả ca / nửa ca ngày / nửa ca đêm)
- Chọn xe 4 số cuối → gợi ý
- Bảng tóm tắt vào ca đầy đủ
- Nút kết thúc ca ở dưới cùng
- Cập nhật bảng tóm tắt khi kết thúc ca
- Danh sách chuyến sắp theo thời gian đóng hàng sớm nhất
- **Tất cả điều kiện kiểm soát Km** (validation km >= km trước)
- Tất cả điều kiện đảo lái (4 nguyên tắc)
- **Rule: 1 đơn 1 lần** (chưa xong đơn 1 không vào đơn 2)
- Nút kết thúc ca + nhập Km kết thúc ca
- Tính Km có hàng / không hàng theo công thức

### ⚠️ Làm rồi nhưng chưa đúng điều kiện

- Thu hồi đơn: đúng action nhưng chưa check điều kiện (phải trước khi "Đi giao hàng")
- Hủy đơn: đúng action nhưng chưa check điều kiện (phải trước khi "Đến điểm giao hàng")
- Xóa đơn: đúng nhưng chưa check điều kiện đúng
- Đảo lái: có nhưng chưa đủ điều kiện kiểm soát
- Các bước lái xe: có nhưng thiếu điều kiện validation

### ✅ Đã làm

- Đăng nhập lái xe
- Gửi đơn cho lái xe (Gán lái cơ bản)
- Chọn chuyến thực hiện
- Chi tiết đơn hàng (chưa đủ)
- Bắt đầu chuyến, hoàn thiện các trạng thái cơ bản
- Chỉnh sửa nhanh hành trình ở danh sách

---

## Ghi chú kỹ thuật cho Filament

```php
// Filament Actions với điều kiện
Tables\Actions\Action::make('cancel')
    ->visible(fn (Order $record) => $record->status->canCancel())
    ->requiresConfirmation()
    ->action(fn (Order $record) => $record->cancel());

// Filament Table Filters
Tables\Filters\SelectFilter::make('destination_zone')
    ->options(['NBA' => 'NBA', 'TN' => 'TN', 'BN' => 'BN', 'NBO' => 'NBO']);

// Status Badge
Tables\Columns\BadgeColumn::make('status')
    ->enum(OrderStatus::class)
    ->color(fn ($state) => OrderStatus::from($state)->color());

// Realtime polling (Filament page)
protected static ?string $pollingInterval = '5s';

// Alpine.js cho copy-to-clipboard
// x-data="{ copied: false }"
// @click="navigator.clipboard.writeText(info); copied=true; setTimeout(()=>copied=false, 2000)"
```

---

_Tài liệu tạo từ: `TMS_ASG.xlsx` + `Check_TMS.xlsx`_  
_Stack: Laravel 12 · Filament v3 · Alpine.js v3 · Tailwind CSS v4_
