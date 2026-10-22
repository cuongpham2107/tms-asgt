# Plan: Trang theo dõi lộ trình chuyến xe (public)

## Dependencies

```
TripStatus / Trip / Vehicle (có sẵn)
        │
        ▼
TripTrackingMapService ──→ PublicTripTrackingController ──→ Blade view
        │                              │
        │                              ▼
CopyTrackingLinkAction ────────→ routes/web.php (signed + throttle)
        │                              │
        ▼                              ▼
TripsTable recordActions        bootstrap/app.php (403 thân thiện)
        │
        ▼
Tests (service + HTTP + action)
```

Không có migration, không có dependency mới, không đụng bản đồ admin.

## Implementation Order

1. **`TripTrackingMapService`** — dựng points/markers/polyline, danh sách điểm lấy/giao, payload vị trí xe, gắn cờ GPS cũ.
2. **Routes + controller** — 2 route `signed` + `throttle`, trang HTML và endpoint JSON.
3. **Blade views** — trang theo dõi (Leaflet + polling 30s) và trang 403 hết hạn.
4. **Xử lý 403** — render `trip-tracking-expired` cho `InvalidSignatureException` khi path `theo-doi/*`.
5. **Filament action** — `CopyTrackingLinkAction` (sinh URL + copy clipboard + notification), đăng ký vào `TripsTable`.
6. **Tests** — 11 case trong `tests/Feature/PublicTripTrackingTest.php`.
7. **Pint + full suite** — `vendor/bin/pint --dirty --format agent`, `php artisan test --compact`.

## Risks & Mitigation

| Risk | Mitigation |
|------|------------|
| OSRM chậm/timeout khi render trang | `OsrmService` đã timeout 3s + cache 30 phút; fallback polyline nối thẳng |
| Link bị chia sẻ ngoài ý muốn | Signed URL hết hạn 3 ngày, `noindex`, throttle 120 req/phút/IP, không lộ dữ liệu cá nhân |
| Polling sau khi link hết hạn | Fetch trả 403 → dừng interval, hiện thông báo "liên hệ điều hành" |
| GPS EUP ngừng cập nhật | Cờ `is_stale` sau 15 phút, hiện cảnh báo vàng |
| Trip không có toạ độ | `has_points = false` → ẩn bản đồ, hiện trạng thái rỗng thay vì bản đồ trắng |
| Test gọi mạng thật | `Http::fake()` cho `router.project-osrm.org` |

## Verification Checkpoints

- Sau bước 1: unit test service pass, markers đúng thứ tự, không trùng điểm liền kề.
- Sau bước 3: mở link hợp lệ ở ẩn danh thấy bản đồ + marker xe; sửa signature thấy trang 403.
- Sau bước 5: bấm action trong bảng chuyến, clipboard có URL mở được.
- Sau bước 6: `php artisan test --compact --filter=PublicTripTracking` xanh.
- Sau bước 7: Pint sạch, full suite không hồi quy.
