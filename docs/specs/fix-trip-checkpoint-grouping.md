# Spec: Fix TripCheckpoint Grouping Logic & Photo Attachment

## Objective

Fix bug trong `TripCheckpointController` khi xử lý `arrived_delivery` và `completed` cho nhiều orders cùng điểm đến (`location_id`). Hiện tại logic gộp nhóm theo `location_id` không hoạt động đúng, dẫn đến:
- Các orders cùng điểm đến bị tạo checkpoint riêng lẻ (khác `occurred_at`)
- Photo chỉ được gắn vào checkpoint cuối cùng trong nhóm
- `delivery_point_id` trong checkpoint bị gán sai cho các orders không phải gốc

## Tech Stack
- Laravel 13 / PHP 8.4
- MySQL (database)
- Pest 4 (testing)

## Commands
```
Build: composer dump-autoload
Test: php artisan test --compact --filter=TripCheckpoint
Lint: vendor/bin/pint --format agent
Dev: php artisan serve
```

## Project Structure
```
app/Http/Controllers/Api/TripCheckpointController.php → code cần sửa
app/Http/Requests/TripCheckpointRequest.php           → validation (có thể cần sửa)
tests/Feature/Http/Controllers/Api/TripCheckpointControllerTest.php → tests
```

## Code Style
- Giữ nguyên style hiện tại
- Constructor property promotion
- Type hints & return types
- Early returns
- DB transaction

## Testing Strategy
- Feature tests với `RefreshDatabase`
- Test case: 2 orders cùng `location_id` → gửi 1 request `arrived_delivery` → verify tạo checkpoint cho cả 2
- Test case: photo upload với grouped orders → verify photo được gắn cho từng checkpoint
- Test case: orders khác `location_id` → verify chỉ tạo checkpoint cho order được chỉ định
- Test case: `delivery_point_id` đúng cho từng order trong group
- Test case: `handleCompleted` group update với delivery points riêng

## Boundaries
- **Always:** Chạy test trước commit, format code với Pint, xác thực input
- **Ask first:** Thay đổi database schema, thêm dependency, thay đổi API response format
- **Never:** Commit secrets, xoá test không approve, sửa vendor files

## Success Criteria
1. Khi gửi `arrived_delivery` cho 1 order có `delivery_point_id` → tạo checkpoint `arrived_delivery` cho **tất cả** orders trong trip cùng `location_id`
2. Photo được upload → gắn vào **từng** checkpoint trong nhóm
3. `delivery_point_id` trong mỗi checkpoint là đúng của order đó (truy vấn từ OrderDeliveryPoint theo order_id + location_id)
4. `handleArrivedDelivery` → update `OrderDeliveryPointStatus::Arrived` cho tất cả delivery points của các order trong group
5. `handleCompleted` → cập nhật `OrderDeliveryPointStatus::Delivered` và `OrderStatus::Completed` cho tất cả orders cùng `location_id`
6. Nếu `arrived_delivery` đã tồn tại cho order nào → skip, không tạo duplicate
7. Nếu order không có `delivery_point_id` hoặc `delivery_point_id` không có `location_id` → chỉ xử lý order duy nhất đó
8. `occurred_at` giống nhau cho tất cả checkpoints trong cùng một group

## Open Questions (Resolved)
- Photo handling: **Gắn ảnh vào tất cả checkpoints** trong nhóm (cùng file path, TripPhoto riêng cho mỗi checkpoint)
- delivery_point_id: **Mỗi checkpoint dùng delivery_point_id của chính order đó**
