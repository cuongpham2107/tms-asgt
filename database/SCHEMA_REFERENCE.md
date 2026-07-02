# Database Schema Reference — TMS ASGT

> Generated from Laravel migrations for ASP.NET migration

---

## 1. `users` — Người dùng / Tài xế

| Column             | Type            | Nullable | Default     | Notes                              |
|--------------------|-----------------|----------|-------------|------------------------------------|
| id                 | int (PK)        | NO       | auto        |                                    |
| name               | string(255)     | NO       |             |                                    |
| email              | string(255)     | NO       |             | UNIQUE                             |
| email_verified_at  | datetime        | YES      |             |                                    |
| password           | string(255)     | NO       |             |                                    |
| date_of_birth      | date            | YES      |             |                                    |
| license_class      | enum            | YES      |             | B, B1, C1, C, FC, D, E            |
| license_number     | string(255)     | YES      |             |                                    |
| license_expiry_date| date            | YES      |             |                                    |
| license_image      | string(255)     | YES      |             |                                    |
| cccd               | string(20)      | YES      |             | Số CCCD                           |
| cccd_issue_date    | date            | YES      |             | Ngày cấp CCCD                     |
| certificates       | json            | YES      |             | Chứng chỉ đi kèm                   |
| station            | string(255)     | YES      |             | Điểm trực                          |
| license_issue_date | date            | YES      |             | Năm cấp bằng                       |
| phone              | string(20)      | YES      |             |                                    |
| address            | string(255)     | YES      |             |                                    |
| avatar             | string(255)     | YES      |             |                                    |
| is_active          | boolean         | NO       | false       |                                    |
| remember_token     | string(100)     | YES      |             |                                    |
| created_at         | datetime        | YES      |             |                                    |
| updated_at         | datetime        | YES      |             |                                    |
| deleted_at         | datetime        | YES      |             | soft delete (Laravel default) — actually users table doesn't use softDeletes |

---

## 2. `customers` — Khách hàng

| Column         | Type          | Nullable | Default | Notes                 |
|----------------|---------------|----------|---------|-----------------------|
| id             | int (PK)      | NO       | auto    |                       |
| code           | string(50)    | NO       |         | UNIQUE, Mã khách hàng |
| name           | string(255)   | NO       |         | Tên khách hàng        |
| phone          | string(20)    | YES      |         |                       |
| address        | text          | YES      |         |                       |
| contact_person | string(255)   | YES      |         | Người liên hệ         |
| is_active      | boolean       | NO       | true    |                       |
| email          | string(255)   | YES      |         | added later           |
| created_at     | datetime      | YES      |         |                       |
| updated_at     | datetime      | YES      |         |                       |
| deleted_at     | datetime      | YES      |         | soft delete           |

---

## 3. `areas` — Phân nhánh / Khu vực (renamed from `order_categories`)

| Column     | Type        | Nullable | Default | Notes                                   |
|------------|-------------|----------|---------|-----------------------------------------|
| id         | int (PK)    | NO       | auto    |                                         |
| type       | string(255) | NO       |         | UNIQUE with code, e.g. NBA, TN...       |
| code       | string(255) | NO       |         |                                         |
| name       | string(255) | NO       |         |                                         |
| color      | string(255) | YES      |         | UI color                                |
| sort_order | int         | YES      |         | Display sort                            |
| is_active  | tinyint(1)  | NO       |         |                                         |
| created_at | datetime    | YES      |         |                                         |
| updated_at | datetime    | YES      |         |                                         |

**Unique**: (`type`, `code`)

---

## 4. `locations` — Địa điểm

| Column    | Type          | Nullable | Default   | Notes                                  |
|-----------|---------------|----------|-----------|----------------------------------------|
| id        | int (PK)      | NO       | auto      |                                        |
| code      | string(30)    | NO       |           | UNIQUE, e.g. NBA, TN, BN              |
| name      | string(255)   | NO       |           | Tên đầy đủ                            |
| address   | text          | YES      |           |                                        |
| lat       | decimal(10,7) | YES      |           | Vĩ độ                                  |
| lng       | decimal(10,7) | YES      |           | Kinh độ                                |
| loc_type  | enum          | NO       | warehouse | pickup, delivery, warehouse, other     |
| is_active | boolean       | NO       | true      |                                        |
| area_id   | int           | YES      |           | FK → areas.id                          |
| created_at| datetime      | YES      |           |                                        |
| updated_at| datetime      | YES      |           |                                        |

**FK**: `area_id` → `areas.id` (SET NULL on delete)

---

## 5. `customer_location` — Pivot Khách hàng ↔ Địa điểm

| Column      | Type        | Nullable | Default | Notes                                    |
|-------------|-------------|----------|---------|------------------------------------------|
| id          | int (PK)    | NO       | auto    |                                          |
| customer_id | int         | NO       |         | FK → customers.id (CASCADE)             |
| location_id | int         | NO       |         | FK → locations.id (CASCADE)             |
| loc_type    | string(30)  | YES      |         | Ghi đè loại địa điểm cho cặp này        |
| created_at  | datetime    | YES      |         |                                          |
| updated_at  | datetime    | YES      |         |                                          |

**Unique**: (`customer_id`, `location_id`)

---

## 6. `vehicles` — Xe

| Column              | Type           | Nullable | Default | Notes                                          |
|---------------------|----------------|----------|---------|------------------------------------------------|
| id                  | int (PK)       | NO       | auto    |                                                |
| plate_number        | string(20)     | NO       |         | UNIQUE, Biển số xe                             |
| registration_number | string(30)     | YES      |         | Số đăng ký xe                                  |
| vehicle_type        | enum           | NO       | normal  | normal, cold, anti_vibration, container, flatbed, bat_wing, other |
| owner               | string(255)    | NO       |         | ASGT, Tam Bảo, HMA, VT123, Hải Như, ACE, CBT… |
| make                | string(255)    | YES      |         | Hãng xe                                        |
| model_year          | year           | YES      |         | Năm sản xuất                                    |
| load_capacity       | decimal(8,2)   | YES      |         | Tải trọng (tấn)                                |
| door_count          | tinyint        | YES      |         | Số cửa                                         |
| total_weight        | decimal(8,2)   | YES      |         | Tổng trọng tải (tấn)                           |
| cargo_volume        | decimal(10,2)  | YES      |         | Số khối thực tế (m³)                           |
| box_length          | int            | YES      |         | Dài (mm)                                       |
| box_width           | int            | YES      |         | Rộng (mm)                                      |
| box_height          | int            | YES      |         | Cao (mm)                                       |
| fuel_type           | string(255)    | YES      |         | Loại nhiên liệu                                |
| current_mileage     | decimal(10,2)  | YES      |         | Số km hiện tại                                 |
| current_driver_id   | int            | YES      |         | FK → users.id (SET NULL) — lái xe hiện tại    |
| gps_lat             | decimal(10,7)  | YES      |         | Vĩ độ GPS hiện tại                             |
| gps_lng             | decimal(10,7)  | YES      |         | Kinh độ GPS hiện tại                           |
| gps_speed           | decimal(8,2)   | YES      |         | Tốc độ (km/h)                                  |
| gps_direction       | smallint       | YES      |         | Hướng di chuyển (độ)                           |
| gps_address         | string(500)    | YES      |         | Địa chỉ GPS hiện tại                           |
| last_gps_update     | datetime       | YES      |         | Thời điểm cập nhật GPS cuối                    |
| is_active           | boolean        | NO       | true    |                                                |
| status              | enum           | NO       | on      | on, off, bdsc, running                         |
| off_reason          | string(255)    | YES      |         | Lý do OFF: BDSC / Đăng kiểm / Bất thường       |
| type                | enum           | NO       | company | company, rent                                  |
| notes               | text           | YES      |         |                                                |
| created_at          | datetime       | YES      |         |                                                |
| updated_at          | datetime       | YES      |         |                                                |
| deleted_at          | datetime       | YES      |         | soft delete                                    |

**FK**: `current_driver_id` → `users.id` (SET NULL)

---

## 7. `vehicle_documents` — Giấy tờ xe

| Column             | Type        | Nullable | Default     | Notes                                  |
|--------------------|-------------|----------|-------------|----------------------------------------|
| id                 | int (PK)    | NO       | auto        |                                        |
| vehicle_id         | int         | NO       |             | FK → vehicles.id                        |
| doc_type           | enum        | NO       |             | registration, inspection               |
| certificate_number | string(255) | NO       |             | Số giấy chứng nhận                     |
| issued_by          | string(255) | NO       |             | Cơ quan cấp                            |
| issued_date        | date        | NO       |             | Ngày cấp                               |
| expiry_date        | date        | NO       |             | Ngày hết hạn                           |
| renewal_cost       | bigint      | YES      |             | Chi phí gia hạn (VNĐ)                  |
| last_renewed_date  | date        | YES      |             | Ngày gia hạn gần nhất                  |
| notes              | text        | YES      |             |                                        |
| status             | enum        | NO       | active      | active, expiring_soon, expired         |
| created_by         | int         | NO       |             | FK → users.id                          |
| created_at         | datetime    | YES      |             |                                        |
| updated_at         | datetime    | YES      |             |                                        |
| deleted_at         | datetime    | YES      |             | soft delete                            |

**Unique**: (`vehicle_id`, `doc_type`, `certificate_number`)
**FK**: `vehicle_id` → `vehicles.id`, `created_by` → `users.id`

---

## 8. `vehicle_maintenance_schedules` — Lịch bảo dưỡng

| Column               | Type        | Nullable | Default | Notes                                     |
|----------------------|-------------|----------|---------|-------------------------------------------|
| id                   | int (PK)    | NO       | auto    |                                           |
| vehicle_id           | int         | NO       |         | FK → vehicles.id                          |
| job_type             | enum        | NO       |         | periodic_maintenance, repair, inspection  |
| priority             | enum        | NO       | medium  | urgent, high, medium, low                 |
| name                 | string(255) | NO       |         | Tên lịch nhắc                             |
| description          | text        | YES      |         |                                           |
| trigger_type         | enum        | NO       |         | by_km, by_date, both                      |
| km_interval          | int         | YES      |         | Chu kỳ km (vd: 5000)                     |
| km_current           | decimal(10,1)| YES     |         | Km snapshot tại thời điểm cấu hình        |
| km_next_trigger      | decimal(10,1)| YES     |         | Mốc km kích hoạt                          |
| km_remind_before     | int         | YES      | 500     | Nhắc trước N km                          |
| date_interval_days   | smallint    | YES      |         | Chu kỳ ngày (vd: 90)                     |
| last_service_date    | date        | YES      |         | Ngày BDSC lần cuối                        |
| date_next_trigger    | date        | YES      |         | Ngày nhắc tiếp theo                       |
| date_remind_before_days | smallint | YES      | 14      | Cảnh báo trước N ngày                     |
| estimated_cost       | bigint      | YES      |         | Chi phí dự kiến (VNĐ)                     |
| garage               | string(255) | YES      |         | Garage mặc định                           |
| is_mandatory         | boolean     | NO       | false   | Bắt buộc — cảnh báo đỏ                    |
| auto_create_job      | boolean     | NO       | false   | Tự động tạo job khi đến hạn               |
| is_active            | boolean     | NO       | true    |                                           |
| alert_status         | enum        | NO       | ok      | ok, warning, due, overdue                 |
| last_triggered_at    | datetime    | YES      |         | Lần cuối kích hoạt                        |
| created_by           | int         | NO       |         | FK → users.id                             |
| created_at           | datetime    | YES      |         |                                           |
| updated_at           | datetime    | YES      |         |                                           |

**FK**: `vehicle_id` → `vehicles.id`, `created_by` → `users.id`

---

## 9. `vehicle_maintenance_jobs` — Công việc bảo dưỡng

| Column          | Type           | Nullable | Default  | Notes                                    |
|-----------------|----------------|----------|----------|------------------------------------------|
| id              | int (PK)       | NO       | auto     |                                          |
| vehicle_id      | int            | NO       |          | FK → vehicles.id                         |
| job_type        | enum           | NO       |          | periodic_maintenance, repair, inspection, registration, insurance |
| priority        | enum           | NO       | medium   | urgent, high, medium, low                |
| title           | string(255)    | NO       |          | Tiêu đề công việc                        |
| description     | text           | YES      |          |                                          |
| planned_date    | date           | NO       |          | Ngày dự kiến                             |
| remind_before_days | smallint    | NO       | 3        | Nhắc trước N ngày                        |
| estimated_cost  | bigint         | YES      |          | Chi phí dự kiến (VNĐ)                    |
| actual_cost     | bigint         | YES      |          | Chi phí thực tế (VNĐ)                    |
| garage          | string(255)    | YES      |          | Garage / Xưởng                           |
| technician      | string(255)    | YES      |          | Kỹ thuật viên                            |
| km_at_service   | decimal(10,1)  | YES      |          | Km đồng hồ tại thời điểm BDSC            |
| next_service_date | date         | YES      |          | Ngày BDSC tiếp theo                      |
| notes           | text           | YES      |          |                                          |
| status          | enum           | NO       | pending  | pending, in_progress, completed, cancelled, overdue |
| completed_at    | datetime       | YES      |          |                                          |
| schedule_id     | int            | YES      |          | FK → vehicle_maintenance_schedules.id (SET NULL) |
| created_by      | int            | NO       |          | FK → users.id                            |
| created_at      | datetime       | YES      |          |                                          |
| updated_at      | datetime       | YES      |          |                                          |
| deleted_at      | datetime       | YES      |          | soft delete                              |

---

## 10. `driver_shifts` — Ca trực tài xế

| Column        | Type           | Nullable | Default | Notes                           |
|---------------|----------------|----------|---------|----------------------------------|
| id            | int (PK)       | NO       | auto    |                                  |
| driver_id     | int            | NO       |         | FK → users.id                   |
| shift_type    | enum           | NO       |         | full, morning_half, night_half  |
| start_time    | datetime       | NO       |         | Thời gian vào ca                 |
| start_km      | decimal(10,1)  | YES      |         | Km bắt đầu ca                    |
| start_gps_lat | decimal(10,7)  | YES      |         |                                  |
| start_gps_lng | decimal(10,7)  | YES      |         |                                  |
| end_time      | datetime       | YES      |         | Thời gian kết thúc ca            |
| end_km        | decimal(10,1)  | YES      |         | Km kết thúc ca                   |
| end_gps_lat   | decimal(10,7)  | YES      |         |                                  |
| end_gps_lng   | decimal(10,7)  | YES      |         |                                  |
| total_km      | decimal(8,1)   | YES      |         | = end_km - start_km             |
| total_km_loaded | decimal(8,1) | YES      |         | Km có hàng                       |
| total_km_empty  | decimal(8,1) | YES      |         | Km không hàng                    |
| created_at    | datetime       | YES      |         |                                  |
| updated_at    | datetime       | YES      |         |                                  |

**FK**: `driver_id` → `users.id`

---

## 11. `trips` — Chuyến xe

| Column          | Type           | Nullable | Default | Notes                |
|-----------------|----------------|----------|---------|-----------------------|
| id              | int (PK)       | NO       | auto    |                       |
| trip_code       | string(255)    | NO       |         | UNIQUE, Mã chuyến     |
| vehicle_id      | int            | YES      |         | FK → vehicles.id (SET NULL) |
| driver_id       | int            | YES      |         | FK → users.id (SET NULL)    |
| shift_id        | int            | YES      |         | FK → driver_shifts.id (SET NULL) |
| status          | string(255)    | YES      |         |                       |
| started_at      | datetime       | YES      |         |                       |
| completed_at    | datetime       | YES      |         |                       |
| start_km        | decimal(10,1)  | YES      |         |                       |
| end_km          | decimal(10,1)  | YES      |         |                       |
| total_km        | decimal(10,1)  | YES      |         |                       |
| total_km_loaded | decimal(10,1)  | YES      |         |                       |
| total_km_empty  | decimal(10,1)  | YES      |         |                       |
| created_at      | datetime       | YES      |         |                       |
| updated_at      | datetime       | YES      |         |                       |

---

## 12. `orders` — Đơn hàng

| Column              | Type           | Nullable | Default   | Notes                                    |
|---------------------|----------------|----------|-----------|------------------------------------------|
| id                  | int (PK)       | NO       | auto      |                                          |
| order_code          | string(50)     | NO       |           | UNIQUE, Mã đơn tự sinh                   |
| type                | enum           | NO       | HHHK      | HHHK, external                           |
| area_id             | int            | NO       |           | FK → areas.id                            |
| customer_id         | int            | NO       |           | FK → customers.id                        |
| cargo_name          | string(255)    | YES      |           | Tên hàng                                 |
| cargo_type          | enum           | NO       | GCR       | GCR (thường), DGR (nguy hiểm)           |
| total_packages      | int            | YES      |           | Tổng số kiện                             |
| total_weight        | decimal(10,2)  | YES      |           | Trọng lượng (kg)                         |
| chargeable_weight   | decimal(10,2)  | YES      |           | Tải trọng tính cước (tấn)               |
| pickup_location_id  | int            | YES      |           | FK → locations.id (SET NULL)            |
| pickup_address      | string(255)    | YES      |           | Địa chỉ lấy hàng manual                 |
| pickup_contact      | string(255)    | YES      |           | Người liên hệ                            |
| pickup_phone        | string(20)     | YES      |           |                                          |
| planned_loading_at  | datetime       | YES      |           | Thời gian dự kiến đóng hàng              |
| trip_id             | int            | YES      |           | FK → trips.id — chuyến xe               |
| trip_sequence       | tinyint        | YES      |           | Thứ tự trong chuyến                      |
| status              | enum           | NO       | draft     | draft, assigned, sent, in_transit, driver_swap, completed, cancelled |
| priority            | enum           | NO       | medium    | urgent, high, medium, low                |
| is_return_trip      | boolean        | NO       | false     | Là chuyến quay đầu                       |
| parent_order_id     | int            | YES      |           | FK → orders.id (SET NULL) — đơn gốc     |
| created_by          | int            | NO       |           | FK → users.id — điều hành tạo đơn       |
| sent_at             | datetime       | YES      |           | Thời điểm gửi lệnh                       |
| cancelled_at        | datetime       | YES      |           |                                          |
| cancel_reason       | string(255)    | YES      |           |                                          |
| notes               | text           | YES      |           | Yêu cầu đặc biệt                         |
| created_at          | datetime       | YES      |           |                                          |
| updated_at          | datetime       | YES      |           |                                          |
| deleted_at          | datetime       | YES      |           | soft delete                              |

**FK**: `area_id` → `areas`, `customer_id` → `customers`, `pickup_location_id` → `locations`, `parent_order_id` → `orders`, `created_by` → `users`

---

## 13. `order_delivery_points` — Điểm giao hàng của đơn

| Column         | Type           | Nullable | Default  | Notes                            |
|----------------|----------------|----------|----------|----------------------------------|
| id             | int (PK)       | NO       | auto     |                                  |
| order_id       | int            | NO       |          | FK → orders.id (CASCADE)        |
| location_id    | int            | YES      |          | FK → locations.id (SET NULL)    |
| address        | string(255)    | YES      |          | Địa chỉ giao (manual)           |
| contact_person | string(255)    | YES      |          | Người liên hệ                    |
| contact_phone  | string(20)     | YES      |          |                                  |
| total_packages | int            | YES      |          | Số kiện giao tại điểm này       |
| total_weight   | decimal(10,2)  | YES      |          | Trọng lượng giao tại điểm (kg)  |
| sequence       | tinyint        | NO       | 1        | Thứ tự giao hàng                 |
| status         | enum           | NO       | pending  | pending, arrived, delivered      |
| arrived_at     | datetime       | YES      |          | Tài xế đến điểm giao             |
| delivered_at   | datetime       | YES      |          | Giao hàng thành công             |
| created_at     | datetime       | YES      |          |                                  |
| updated_at     | datetime       | YES      |          |                                  |

---

## 14. `order_edit_logs` — Lịch sử sửa đơn

| Column    | Type        | Nullable | Default | Notes                      |
|-----------|-------------|----------|---------|----------------------------|
| id        | int (PK)    | NO       | auto    |                            |
| order_id  | int         | NO       |         | FK → orders.id (CASCADE)  |
| user_id   | int         | NO       |         | FK → users.id (CASCADE)   |
| field     | string(255) | NO       |         | Tên trường bị thay đổi     |
| old_value | text        | YES      |         |                            |
| new_value | text        | YES      |         |                            |
| created_at| datetime    | YES      |         |                            |
| updated_at| datetime    | YES      |         |                            |

---

## 15. `order_templates` — Mẫu đơn định kỳ

| Column         | Type         | Nullable | Default | Notes                              |
|----------------|--------------|----------|---------|-------------------------------------|
| id             | int (PK)     | NO       | auto    |                                     |
| name           | string(255)  | NO       |         | Tên mẫu đơn                        |
| order_data     | json         | NO       |         | JSON đủ các trường của orders       |
| quantity       | int          | NO       | 1       | Số lượng đơn tạo mỗi lần           |
| cron_expression| string(100)  | YES      |         | Biểu thức cron                     |
| daily_run_at   | time         | YES      |         | Giờ chạy hàng ngày                  |
| is_active      | boolean      | NO       | true    |                                     |
| created_by     | int          | NO       |         | FK → users.id                       |
| created_at     | datetime     | YES      |         |                                     |
| updated_at     | datetime     | YES      |         |                                     |

---

## 16. `empty_kilometers` — Km không hàng

| Column        | Type           | Nullable | Default | Notes                          |
|---------------|----------------|----------|---------|--------------------------------|
| id            | int (PK)       | NO       | auto    |                                |
| driver_id     | int            | NO       |         | FK → users.id                 |
| vehicle_id    | int            | YES      |         | FK → vehicles.id               |
| shift_id      | int            | YES      |         | FK → driver_shifts.id          |
| start_km      | decimal(8,1)   | NO       |         | Km bắt đầu                     |
| end_km        | decimal(8,1)   | NO       |         | Km kết thúc                    |
| distance      | decimal(8,1)   | YES      |         | = end_km - start_km           |
| start_gps_lat | decimal(10,7)  | YES      |         |                                |
| start_gps_lng | decimal(10,7)  | YES      |         |                                |
| end_gps_lat   | decimal(10,7)  | YES      |         |                                |
| end_gps_lng   | decimal(10,7)  | YES      |         |                                |
| started_at    | datetime       | NO       |         |                                |
| ended_at      | datetime       | NO       |         |                                |
| note          | string(255)    | YES      |         | Ghi chú                        |
| created_at    | datetime       | YES      |         |                                |
| updated_at    | datetime       | YES      |         |                                |

---

## 17. `trip_checkpoints` — Checkpoint / Mốc chuyến

| Column            | Type           | Nullable | Default | Notes                              |
|-------------------|----------------|----------|---------|------------------------------------|
| id                | int (PK)       | NO       | auto    |                                    |
| trip_id           | int            | NO       |         | FK → trips.id (CASCADE)           |
| order_id          | int            | YES      |         | FK → orders.id (SET NULL)         |
| delivery_point_id | int            | YES      |         | FK → order_delivery_points.id (SET NULL) |
| checkpoint_type   | enum           | NO       |         | started, arrived_pickup, left_pickup, arrived_delivery, completed, driver_swap |
| occurred_at       | datetime       | NO       |         | Thời điểm thực tế từ app           |
| km_reading        | decimal(10,1)  | YES      |         | Số km đồng hồ xe                   |
| gps_lat           | decimal(10,7)  | YES      |         |                                    |
| gps_lng           | decimal(10,7)  | YES      |         |                                    |
| voice_note        | text           | YES      |         | Ghi chú voice → text               |
| driver_id         | int            | YES      |         | FK → users.id (SET NULL) — added later |
| shift_id          | int            | YES      |         | FK → driver_shifts.id (SET NULL) — added later |
| created_at        | timestamp      | YES      |         | useCurrent                         |

---

## 18. `trip_photos` — Ảnh chuyến xe

| Column             | Type        | Nullable | Default | Notes                                  |
|--------------------|-------------|----------|---------|----------------------------------------|
| id                 | int (PK)    | NO       | auto    |                                        |
| trip_checkpoint_id | int         | NO       |         | FK → trip_checkpoints.id (CASCADE)    |
| photo_path         | string(255) | NO       |         | Đường dẫn file trong storage           |
| photo_url          | string(255) | YES      |         | URL công khai (cloud)                  |
| created_at         | timestamp   | YES      |         | useCurrent                             |

---

## 19. `driver_swaps` — Đảo lái

| Column        | Type           | Nullable | Default | Notes                          |
|---------------|----------------|----------|---------|--------------------------------|
| id            | int (PK)       | NO       | auto    |                                |
| trip_id       | int            | NO       |         | FK → trips.id (CASCADE)       |
| from_driver_id| int            | NO       |         | FK → users.id — lái cũ        |
| to_driver_id  | int            | NO       |         | FK → users.id — lái mới       |
| from_shift_id | int            | NO       |         | FK → driver_shifts.id          |
| to_shift_id   | int            | YES      |         | FK → driver_shifts.id (SET NULL) |
| handover_km   | decimal(10,1)  | YES      |         | Km bàn giao                    |
| reason        | enum           | NO       |         | shift_handover, cargo_not_unloaded, other |
| note          | text           | YES      |         |                                |
| created_by    | int            | NO       |         | FK → users.id — điều hành     |
| created_at    | timestamp      | YES      |         | useCurrent                     |

---

## Relationship Map

```
areas ──┬── locations        users ──┬── drivers_shifts
         │                            │── driver_swaps (from/to)
         └── orders                   │── driver_swaps.created_by
                                      │── empty_kilometers
customers ─── orders                  │── orders.created_by
                                      │── trip_checkpoints.driver_id
                                      │── vehicle_maintenance_*.created_by
                                      │── vehicle_documents.created_by
locations ─── order_delivery_points   │── order_templates.created_by
          ─── orders.pickup_location   │── vehicles.current_driver_id
          ─── customer_location
                                      vehicles ──┬── vehicle_documents
orders ──┬── order_delivery_points               │── vehicle_maintenance_schedules
         │── order_edit_logs                      │── vehicle_maintenance_jobs
         │── orders.parent_order_id               │── empty_kilometers
         │── trip_checkpoints                      │── trips
         │── driver_swaps.trip_id                  │── driver_swaps
         └── trips                                └── trip_checkpoints
```

## ENUM Reference Summary

| Table                    | Column           | Values                                                      |
|--------------------------|------------------|-------------------------------------------------------------|
| users                    | license_class    | B, B1, C1, C, FC, D, E                                     |
| vehicles                 | vehicle_type     | normal, cold, anti_vibration, container, flatbed, bat_wing, other |
| vehicles                 | status           | on, off, bdsc, running                                      |
| vehicles                 | type             | company, rent                                                |
| locations                | loc_type         | pickup, delivery, warehouse, other                           |
| orders                   | type             | HHHK, external                                               |
| orders                   | cargo_type       | GCR, DGR                                                     |
| orders                   | status           | draft, assigned, sent, in_transit, driver_swap, completed, cancelled |
| orders                   | priority         | urgent, high, medium, low                                    |
| order_delivery_points    | status           | pending, arrived, delivered                                  |
| driver_shifts            | shift_type       | full, morning_half, night_half                               |
| trip_checkpoints         | checkpoint_type  | started, arrived_pickup, left_pickup, arrived_delivery, completed, driver_swap |
| driver_swaps             | reason           | shift_handover, cargo_not_unloaded, other                    |
| vehicle_documents        | doc_type         | registration, inspection                                     |
| vehicle_documents        | status           | active, expiring_soon, expired                               |
| vehicle_maintenance_jobs | job_type         | periodic_maintenance, repair, inspection, registration, insurance |
| vehicle_maintenance_jobs | priority         | urgent, high, medium, low                                    |
| vehicle_maintenance_jobs | status           | pending, in_progress, completed, cancelled, overdue          |
| vehicle_maintenance_schedules | job_type     | periodic_maintenance, repair, inspection                     |
| vehicle_maintenance_schedules | priority     | urgent, high, medium, low                                    |
| vehicle_maintenance_schedules | trigger_type | by_km, by_date, both                                         |
| vehicle_maintenance_schedules | alert_status | ok, warning, due, overdue                                    |
