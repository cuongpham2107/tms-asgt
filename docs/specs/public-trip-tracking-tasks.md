# Tasks: Trang theo dõi lộ trình chuyến xe (public)

- [x] Task 1: Service dữ liệu bản đồ & vị trí
  - Acceptance: `TripTrackingMapService::buildMapData()` trả points/markers/pickups/deliveries/route/center; `positionPayload()` trả đúng contract trong spec, `is_stale` sau 15 phút, `is_finished` với completed/cancelled.
  - Verify: unit test service (case 9, 10).
  - Files: `app/Services/Trip/TripTrackingMapService.php`.

- [x] Task 2: Routes + controller
  - Acceptance: `GET /theo-doi/{trip}` và `/vi-tri` chỉ chấp nhận signed URL, throttle 120/phút; JSON có `Cache-Control: no-store`.
  - Verify: `php artisan route:list --path=theo-doi`, test case 1–7.
  - Files: `routes/web.php`, `app/Http/Controllers/PublicTripTrackingController.php`.

- [x] Task 3: Blade trang khách
  - Acceptance: hiển thị header/trạng thái/điểm lấy-giao/bản đồ Leaflet/legend/chú thích; polling 30s cập nhật marker xe; dừng khi chuyến kết thúc; responsive 375px; không lộ SĐT/CCCD.
  - Verify: mở link thật trên trình duyệt, test case 8.
  - Files: `resources/views/public/trip-tracking.blade.php`.

- [x] Task 4: Trang 403 thân thiện
  - Acceptance: link sai/hết hạn trả 403 và render `public.trip-tracking-expired`.
  - Verify: test case 2–4.
  - Files: `resources/views/public/trip-tracking-expired.blade.php`, `bootstrap/app.php`.

- [x] Task 5: Action copy link trong bảng chuyến
  - Acceptance: action "Copy link theo dõi" trong nhóm thao tác của `TripsTable`, copy URL hết hạn sau 3 ngày, có notification.
  - Verify: test case 11 + thao tác tay trên `/app/trips`.
  - Files: `app/Filament/Resources/Trips/Actions/CopyTrackingLinkAction.php`, `app/Filament/Resources/Trips/Tables/TripsTable.php`.

- [x] Task 6: Test suite
  - Acceptance: 11 case trong spec pass, OSRM được fake.
  - Verify: `php artisan test --compact --filter=PublicTripTracking`.
  - Files: `tests/Feature/PublicTripTrackingTest.php`.

- [x] Task 7: Format & hồi quy
  - Acceptance: Pint sạch, full test suite xanh.
  - Verify: `vendor/bin/pint --dirty --format agent` và `php artisan test --compact`.
  - Files: tất cả file đã sửa.
