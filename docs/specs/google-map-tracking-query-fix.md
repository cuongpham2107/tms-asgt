# Spec: Sửa lỗi ambiguous column `status` trong GoogleMapTracking

## Objective

Sửa lỗi `ambiguous column name: status` trên page GoogleMapTracking. Lỗi xảy ra khi eager load `orders` qua `HasManyThrough` (Vehicle → Trip → Order) — cả 2 bảng `orders` và `trips` đều có cột `status`, nhưng query constraint không prefix tên bảng.

## Root Cause

Khi đổi `Vehicle::driverShifts()` từ `BelongsToMany` (pivot `shift_vehicles`) sang `HasManyThrough` (qua `trips`), đồng thời thêm `Vehicle::orders()` cũng là `HasManyThrough` (qua `trips`), query sinh ra:

```sql
SELECT "orders".*, "trips"."vehicle_id" as "laravel_through_key"
FROM "orders"
INNER JOIN "trips" ON "trips"."id" = "orders"."trip_id"
WHERE "trips"."vehicle_id" IN (...)
  AND ("status" IN (assigned, sent) OR ...)   ← ambiguous: orders.status hay trips.status?
  AND "orders"."deleted_at" IS NULL
```

## Tech Stack

- Laravel 13 / PHP 8.4
- SQLite (dev) / MySQL (prod)
- Filament v5

## Commands

```bash
Test: php artisan test --filter=GoogleMapTracking
Lint: vendor/bin/pint --format agent
Route: php artisan route:list --method=GET --path=google-map-tracking
```

## Files chạm tới

- `app/Filament/Pages/GoogleMapTracking.php` — sửa eager load constraint

## Giải pháp

- Line 610: `whereIn('status', $activeStatuses)` → `whereIn('orders.status', $activeStatuses)`
- Line 611: `orWhereDate('planned_loading_at', today())` → không cần đổi vì chỉ `orders` có cột này

## Success Criteria

1. Page GoogleMapTracking load không lỗi SQL
2. Orders filter đúng theo `status` trên bảng `orders`
3. Không thay đổi behavior nào khác

## Boundaries

- Always: prefix column với tên table trong eager load constraints khi dùng HasManyThrough
- Ask first: thay đổi relationship type trên model
- Never: sửa query mà không test
