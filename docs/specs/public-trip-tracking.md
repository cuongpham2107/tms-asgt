# Spec: Trang theo dõi lộ trình chuyến xe (public)

## Objective

Cho khách hàng một link xem lộ trình chuyến hàng của mình mà **không cần đăng nhập**:

- Bản đồ lộ trình dự kiến: điểm xuất phát → điểm lấy hàng (theo từng đơn) → điểm giao hàng (theo `sequence`) → điểm kết thúc.
- Vị trí xe hiện tại lấy từ GPS EUP (`vehicles.gps_lat/gps_lng`), tự cập nhật mỗi 30 giây.
- Trạng thái chuyến, biển số xe, tên tài xế (không số điện thoại), thời điểm GPS cập nhật gần nhất.

Điều hành bấm **"Copy link theo dõi"** trong bảng chuyến (`TripsTable`) để lấy URL gửi khách qua Zalo/SMS.

**User story**

> Là điều hành, tôi muốn copy link theo dõi của một chuyến để gửi khách.
> Là khách hàng, tôi mở link trên điện thoại, thấy xe đang ở đâu và khi nào tới điểm giao, không cần tài khoản.

**Success criteria (đo được)**

1. Bấm "Copy link theo dõi" → clipboard chứa URL `https://<domain>/theo-doi/{trip}?expires=...&signature=...`; mở URL trong tab ẩn danh trả 200.
2. Trang hiển thị: mã chuyến, trạng thái, biển số, tên tài xế, danh sách điểm lấy/giao theo đúng thứ tự, marker xe, polyline OSRM.
3. Sửa `trip` id hoặc bỏ `signature` → 403; link hết hạn → 403 kèm thông báo tiếng Việt thân thiện.
4. Vị trí xe tự nhích sau ≤ 35 giây mà không reload trang; khi chuyến `completed`/`cancelled` thì ngừng polling.
5. HTML và JSON public **không chứa** SĐT/CCCD tài xế, thông tin khách hàng, giá cước.
6. Trang dùng tốt trên viewport 375px; bản đồ cao ≥ 55vh, không nhảy layout khi cập nhật marker.
7. `php artisan test --compact --filter=PublicTripTracking` pass; `vendor/bin/pint --dirty --format agent` sạch.

## Tech Stack

- Laravel 13 / PHP 8.4, Blade (standalone, không qua Vite), Pest.
- Leaflet 1.9.4 + tile OpenStreetMap (CDN, giống bản đồ admin).
- Tuyến bám đường: `App\Services\OsrmService::getRouteFromPoints()` (cache 30 phút sẵn có).
- Filament v5 cho action trong bảng chuyến.
- Không thêm dependency, không cần migration (dùng signed URL của Laravel).

## Commands

```bash
Test:  php artisan test --compact --filter=PublicTripTracking
Lint:  vendor/bin/pint --dirty --format agent
Route: php artisan route:list --path=theo-doi
Serve: composer run dev
```

## Project Structure

```
app/Http/Controllers/PublicTripTrackingController.php   # show() + position()
app/Services/Trip/TripTrackingMapService.php            # dựng points/markers/polyline + payload vị trí
app/Filament/Resources/Trips/Actions/CopyTrackingLinkAction.php
resources/views/public/trip-tracking.blade.php          # trang khách (standalone HTML)
resources/views/public/trip-tracking-expired.blade.php  # 403 thân thiện
routes/web.php                                          # 2 route public, middleware signed + throttle
app/Filament/Resources/Trips/Tables/TripsTable.php      # đăng ký action trong recordActions
tests/Feature/PublicTripTrackingTest.php
docs/specs/public-trip-tracking.md
```

## Routes & contract

```php
// routes/web.php
Route::middleware(['signed', 'throttle:120,1'])->prefix('theo-doi/{trip}')->group(function () {
    Route::get('/', [PublicTripTrackingController::class, 'show'])->name('trips.public-tracking');
    Route::get('/vi-tri', [PublicTripTrackingController::class, 'position'])->name('trips.public-tracking.position');
});
```

- Link do `CopyTrackingLinkAction::makeUrl(Trip $trip)` sinh bằng
  `URL::temporarySignedRoute('trips.public-tracking', now()->addDays(3), ['trip' => $trip])`.
- Link vị trí sinh cùng thời điểm hết hạn, nhúng vào trang qua `@json($positionUrl)`; nếu link cha hết hạn thì polling dừng và hiện thông báo.
- TTL mặc định **3 ngày** kể từ lúc copy (`CopyTrackingLinkAction::LINK_TTL_DAYS`), bấm lại để lấy link mới. Không lưu DB.
- `position` trả JSON, `Cache-Control: no-store`:

```json
{
  "status": "delivering",
  "status_label": "Đang giao",
  "is_finished": false,
  "has_position": true,
  "position": {
    "latitude": 21.0285,
    "longitude": 105.8542,
    "speed_kmh": 45.5,
    "address": "Hoàn Kiếm, Hà Nội",
    "updated_at": "2026-10-22T10:00:00+07:00",
    "updated_at_human": "2 phút trước",
    "is_stale": false
  }
}
```

- Không có GPS → `has_position: false`, `position: null`; UI hiện "Chưa có tín hiệu GPS".
- GPS cũ hơn 15 phút → `is_stale: true`; UI hiện cảnh báo màu vàng.

## Luồng hiển thị

1. Header: logo `public/images/ASG.png`, mã chuyến, badge trạng thái (màu theo `TripStatus::getColor()`), biển số + tên tài xế.
2. Tóm tắt: `Điểm lấy hàng` (các `pickupLocation` theo `planned_loading_at`) → `Điểm giao hàng` (các `deliveryPoints` theo `sequence`), giờ dự kiến lấy hàng, giờ bắt đầu nếu có.
3. Bản đồ Leaflet:
   - Marker xanh lá: điểm xuất phát (`startLocation`), xanh dương: điểm kết thúc (`endLocation`).
   - Marker cam, có số thứ tự: điểm lấy hàng; marker đỏ, có số thứ tự: điểm giao hàng.
   - Polyline xanh đậm: tuyến OSRM bám đường; nét đứt xám: tuyến nối thẳng dự phòng khi OSRM lỗi.
   - Marker xe (`images/truck.png`, kèm vòng halo) + tooltip biển số/tốc độ/giờ GPS.
   - `fitBounds` toàn bộ điểm; tâm mặc định là xe nếu có.
4. Thanh dưới: lần cập nhật GPS cuối, ghi chú "Tự động cập nhật mỗi 30 giây", link hết hạn ngày nào.
5. Trạng thái rỗng: không có toạ độ nào → ẩn bản đồ, hiện "Chuyến chưa có lộ trình".
6. Chuyến `pending`: vẫn hiện vị trí xe (xe đang chờ) nhưng badge "Chờ chạy"; chuyến `completed`/`cancelled`: bản đồ tĩnh, ngừng polling.

Thứ tự điểm lấy/giao lấy đúng như logic `TripsTable::getPickupLocations()` / `getDeliveryDestination()`: sắp theo `planned_loading_at` và `sequence`, gộp các điểm trùng liền kề.

## Code style

Theo pattern action hiện có (`CopyTransportInfoAction`):

```php
class CopyTrackingLinkAction
{
    public const LINK_TTL_DAYS = 7;

    public static function make(): Action
    {
        return Action::make('copy_tracking_link')
            ->label('Copy link theo dõi')
            ->icon('heroicon-o-link')
            ->color('gray')
            ->action(function (Trip $record, Component $livewire): void {
                $url = self::makeUrl($record);
                $livewire->js('window.navigator.clipboard.writeText('.json_encode($url, JSON_UNESCAPED_UNICODE).')');

                Notification::make()->title('Đã copy link theo dõi')->body($url)->success()->send();
            });
    }

    public static function makeUrl(Trip $trip): string
    {
        return URL::temporarySignedRoute(
            'trips.public-tracking',
            now()->addDays(self::LINK_TTL_DAYS),
            ['trip' => $trip],
        );
    }
}
```

- Controller mỏng: load relation, gọi service, trả view/JSON. Không query trong Blade.
- Service thuần, không phụ thuộc Filament/Livewire, trả array → test được không cần HTTP.
- View dùng tiếng Việt, CSS inline theo tinh thần `privacy-policy.blade.php`, không Tailwind build.

## Testing Strategy

`tests/Feature/PublicTripTrackingTest.php` — Pest + `RefreshDatabase` + `Http::fake()` cho OSRM (tránh gọi mạng thật).

| # | Case | Kỳ vọng |
|---|------|---------|
| 1 | Signed URL hợp lệ | 200, chứa mã chuyến, biển số, tên tài xế, mã điểm lấy/giao |
| 2 | Không có `signature` | 403 |
| 3 | Sửa `trip` id (signature cũ) | 403 |
| 4 | Link hết hạn (`travel(8)->days()`) | 403, view hết hạn |
| 5 | `position` hợp lệ | JSON đúng `latitude/longitude/status_label`, header `no-store` |
| 6 | Vehicle chưa có GPS | `has_position: false`, `position: null` |
| 7 | GPS cũ > 15 phút | `is_stale: true` |
| 8 | Trang không lộ dữ liệu riêng tư | không chứa SĐT, CCCD |
| 9 | Service: nhiều đơn, nhiều điểm giao | điểm đúng thứ tự, gộp trùng liền kề, có fallback `startLocation/endLocation` |
| 10 | OSRM lỗi/timeout | vẫn 200, có polyline dự phòng |
| 11 | `CopyTrackingLinkAction::makeUrl()` | `URL::hasValidSignature()` = true, hết hạn sau 3 ngày |

Test thủ công: mở link trên Chrome DevTools mobile 375px, kiểm tra marker nhích sau 30s, console không lỗi, JSON không chứa `phone`.

## Boundaries

- **Always**: ký URL bằng `URL::temporarySignedRoute`; giữ controller/service mỏng; chạy Pint + test trước khi kết thúc; copy tiếng Việt có dấu.
- **Ask first**: thêm migration/cột DB; đổi TTL mặc định; tái cấu trúc `TripsTable::buildGpsMapConfig`; thêm config/env key mới.
- **Never**: đưa SĐT/CCCD/giá cước/thông tin khách lên trang public; tắt middleware `signed`; thêm JS/CSS framework mới; sửa bản đồ admin khi chưa có yêu cầu.

## Quyết định đã chốt

- Signed URL có hạn (không thêm cột token) — đã chọn.
- Hiển thị tất cả điểm lấy/giao theo thứ tự — đã chọn.
- Leaflet + OSM + OSRM — đã chọn.
- Polling 30 giây, có nút refresh thủ công — đã chọn.
- Biển số + tên tài xế, **không** SĐT — đã chọn.

## Open Questions

1. Khi link hết hạn, khách chỉ thấy thông báo liên hệ điều hành (không công khai SĐT) — đã chốt.
