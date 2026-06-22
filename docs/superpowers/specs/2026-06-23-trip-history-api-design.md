# Trip History API Design

**Date:** 2026-06-23

## Overview

Add an API endpoint for drivers to view their completed/swap/cancelled trip history with full details (orders, checkpoints, km, driver swaps).

## Background

Currently the driver app has:
- `GET /api/driver/orders/history` — order-level history (completed orders)
- `GET /api/driver/trips/active` — current active trip
- `GET /api/driver/trips/{trip}` — single trip detail

Missing: a paginated list of historical trips the driver has completed or been involved in.

## Endpoint

```
GET /api/driver/trips/history
```

### Query Parameters

| Parameter    | Type   | Default | Description                                  |
|-------------|--------|---------|----------------------------------------------|
| `per_page`  | int    | 15      | Số bản ghi mỗi trang                         |
| `from_date` | string | null    | Lọc từ ngày (started_at >=, ISO date)        |
| `to_date`   | string | null    | Lọc đến ngày (started_at <=, ISO date)       |
| `status`    | string | null    | Lọc theo trạng thái trip                     |
| `vehicle_id`| int    | null    | Lọc theo ID phương tiện                      |

### Status Values

- `completed`
- `driver_swap`

> Note: `Cancelled` is not in the current `TripStatus` enum — only `Completed` and `DriverSwap` are valid history statuses.

### Response Shape

```json
{
    "data": [
        {
            "id": 1,
            "trip_code": "TRIP-001",
            "vehicle_id": 1,
            "status": "Completed",
            "started_at": "2026-06-22T10:00:00Z",
            "completed_at": "2026-06-22T18:00:00Z",
            "start_km": 10000.0,
            "end_km": 10450.0,
            "total_km": 450.0,
            "total_km_loaded": 380.0,
            "total_km_empty": 70.0,
            "vehicle": { "id": 1, "plate_number": "51C-123.45" },
            "shift": { "id": 1, "shift_type": "full", "start_time": "...", "end_time": "..." },
            "driver_swaps": [
                { "id": 1, "from_driver": { ... }, "to_driver": { ... }, "reason": "Hết ca" }
            ],
            "orders": [
                {
                    "id": 1,
                    "order_code": "ORD-001",
                    "status": "Completed",
                    "customer": { ... },
                    "pickupLocation": { ... },
                    "deliveryPoints": [ ... ],
                    "tripCheckpoints": [
                        { "id": 1, "checkpoint_type": "arrived_pickup", "km_reading": 10010, "photos": [ ... ] }
                    ]
                }
            ],
            "checkpoints": [
                { "id": 1, "checkpoint_type": "started", "occurred_at": "...", "photos": [ ... ] }
            ],
            "created_at": "2026-06-22T09:00:00Z",
            "updated_at": "2026-06-22T18:00:00Z"
        }
    ],
    "meta": {
        "current_page": 1,
        "last_page": 3,
        "per_page": 15,
        "total": 35
    }
}
```

## Implementation

### Controller

Add `history()` method to `TripController`:

- Query `Trip::query()` with eager-loaded relationships: `vehicle`, `shift`, `driver`, `driverSwaps.toDriver`, `orders` (nested: customer, pickupLocation, deliveryPoints, tripCheckpoints with photos), `checkpoints` (with photos)
- Filter by `driver_id = $user->id`
- Filter by `status IN [Completed, DriverSwap, Cancelled]`
- Apply optional filters: `from_date`, `to_date`, `status`, `vehicle_id`
- Order by `started_at DESC`
- Paginate with configurable `per_page` (default 15)

### Resource

- Add `driverSwaps` to `TripResource` via `whenLoaded('driverSwaps')`
- Use existing `DriverSwapResource` collection

### Route

```
Route::get('/driver/trips/history', [TripController::class, 'history']);
```

Must be placed **before** `trips/{trip}` to avoid route parameter collision.

## Error Handling

- Authentication: 401 nếu không có token
- Validation: các query param không hợp lệ (ví dụ `status` sai) trả về 422
- Empty result: trả về `data: []` với meta đầy đủ

## Testing

- Test thành công: trả về paginated list
- Test filter: từng filter hoạt động riêng và kết hợp
- Test empty: driver không có trip history
- Test route priority: "history" không bị nhầm là {trip} param
- Test authorize: driver chỉ thấy trip của mình
