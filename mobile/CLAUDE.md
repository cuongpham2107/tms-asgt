# TMS Driver Mobile

Expo React Native app cho tài xế TMS-ASGT, kết nối với Laravel API backend.

## Cấu trúc

```
app/
  _layout.tsx          — Root layout + AuthGuard
  login.tsx            — Màn hình đăng nhập
  shift.tsx            — Chọn ca / Kết thúc ca
  trip-detail.tsx      — Chi tiết chuyến + Kết thúc chuyến
  order-detail.tsx     — Chi tiết đơn hàng + Checkpoints
  completed-trips.tsx  — Danh sách chuyến đã hoàn thành
  (tabs)/
    _layout.tsx        — Tab navigator
    index.tsx          — Dashboard (tổng quan)
    trips.tsx          — Danh sách chuyến
    orders.tsx         — Danh sách đơn hàng
    stats.tsx          — Thống kê
    profile.tsx        — Cá nhân
src/
  lib/
    api.ts             — API client (Laravel Sanctum)
    auth.tsx           — Auth context
    types.ts           — TypeScript interfaces
```

## API Backend

Kết nối với TMS-ASGT Laravel backend qua Sanctum API:

| Endpoint | Mô tả |
|----------|-------|
| `POST /api/driver/login` | Đăng nhập (email + password → token) |
| `POST /api/driver/shifts/start` | Bắt đầu ca |
| `POST /api/driver/shifts/end` | Kết thúc ca (chuyến đang chạy tự chuyển đảo lái) |
| `GET /api/driver/shifts/active` | Trạng thái ca + `min_app_version` |
| `GET /api/driver/shifts/current` | Ca hiện tại |
| `GET /api/driver/trips/active` | Chuyến đang active |
| `GET /api/driver/trips/{id}` | Chi tiết chuyến |
| `POST /api/driver/trips/{id}/complete` | Kết thúc chuyến (chỉ khi `available_actions` có `end`) |
| `POST /api/driver/trips/{id}/swap` | Xin đảo lái `{reason, note?}` |
| `POST /api/driver/gps-points` | Gửi lô điểm GPS (`src/tracking/`) |
| `POST /api/driver/trips/{id}/checkpoints` | Tạo checkpoint |
| `GET /api/driver/orders` | Danh sách đơn hàng |
| `GET /api/driver/orders/{id}` | Chi tiết đơn hàng |
| `GET /api/driver/orders/stats` | Thống kê đơn hàng |
| `GET /api/driver/trips/stats` | Thống kê chuyến |

## Cài đặt & Chạy

```bash
cd tms-mobile
npm install
npx expo start
```

Trước khi chạy, cập nhật IP trong `src/lib/api.ts` trỏ đến máy chạy Laravel backend.

## Luồng chính

1. **Đăng nhập** → Nhận token Sanctum
2. **Chọn ca** → `POST /shifts/start`
3. **Dashboard** → Xem chuyến đang chạy, thống kê ca
4. **Chuyến đi** → Xem chi tiết, orders trong chuyến
5. **Đơn hàng** → Gửi checkpoints (đến lấy hàng, đến giao, giao xong)
6. **Kết thúc chuyến** → `POST /trips/{id}/complete`; **Đảo lái** → `POST /trips/{id}/swap`
7. **Kết thúc ca** → `POST /shifts/end` (flush GPS rồi dừng ghi)

Nút thao tác chỉ hiển thị theo `available_actions` của trip/order; nhãn trạng thái dùng `status_label` từ API. Không còn nhập km: km tính từ GPS ghi nền suốt ca (`src/tracking/`, cần quyền vị trí "Luôn luôn" — màn `app/gps-permission.tsx`).
