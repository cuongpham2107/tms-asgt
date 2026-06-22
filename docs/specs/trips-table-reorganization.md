# Spec: TripsTable Reorganization

## Objective

Reorganize `TripsTable.php` column set to match the CSV layout provided by the user, with live data sourced from the `Vehicle` model for GPS position, speed, and status.

## Column Layout (following CSV order)

| # | CSV Column | Source | Type | Notes |
|---|-----------|--------|------|-------|
| 1 | Địa điểm | `ShiftVehicle` → Orders → `area.code` (unique, badged) | `TextColumn` badge | Existing (no change) |
| 2 | BSX | `Vehicle.plate_number` + `Vehicle.load_capacity` | `TextColumn` | Existing `renderBsx()` |
| 3 | STT | Static `'CH'` | `TextColumn` | Existing (no change) |
| 4 | Trạng thái | Derived from Order statuses | `TextColumn` badge | **Changed**: see logic below |
| 5 | Điểm đi | Orders → `pickupLocation.name` | `TextColumn` | Existing (no change) |
| 6 | Điểm đến | Orders → `deliveryPoints` → address | `TextColumn` | Existing (no change) |
| 7 | Xe chờ | `ShiftVehicle.updated_at` formatted `H:i` | `TextColumn` | **Changed**: was order count, now time |
| 8 | Vị trí thực tế trên GPS | `Vehicle.gps_lat` / `Vehicle.gps_lng` | `UniqueMapColumn` | **Replaces** "Bản đồ" column |
| 9 | Tốc độ | `Vehicle.gps_speed` | `TextColumn` | Existing (no change) |
| 10 | Status | Movement status (`Xe dừng`/`Đang chạy`) | `TextColumn` badge | **Renamed** from "Tình trạng" |
| 11 | Chuyến | Today's trip count for vehicle | `TextColumn` | Existing (no change) |
| 12 | KM over | `end_km - start_km` or `current_mileage - start_km` | `TextColumn` | Existing (no change) |
| 13 | Tên lái xe | `Shift.driver.name` + driver swaps | `TextColumn` | Existing "Lái xe" (no change) |
| 14 | Trực | Orders → `area.code` (imploded) | `TextColumn` | Existing (no change) |
| 15 | Ca | `Shift.shift_type.getLabel()` | `TextColumn` badge | Existing (no change) |

### Removed columns
- **Ngày** (`start_time`) — removed per user request
- **Bản đồ** — replaced by "Vị trí thực tế trên GPS"

### "Trạng thái" logic (from Order status)

| Condition | Label | Color |
|-----------|-------|-------|
| Trip has no orders | Đang chờ | gray |
| Any order in active status (started, arrived_pickup, delivering, arrived_delivery) | Chưa hạ | warning |
| All orders in assigned/sent | Chưa đến | info |
| All orders delivered/completed | Hoàn thành | success |
| All orders cancelled | Đã hủy | danger |
| Default fallback | Đã phân | warning |
| `end_time !== null` (trip ended) | Hoàn thành | success |

### "Vị trí thực tế trên GPS" (UniqueMapColumn)

- Shows a static mini map with a marker at `Vehicle.gps_lat`, `Vehicle.gps_lng`
- The modal (on click) shows a larger map centered on the vehicle's GPS position with:
  - Vehicle marker at actual GPS coordinates
  - Vehicle plate + driver info in header
- **No** route lines, pickup/delivery markers, or OSRM segments (this is live GPS, not the trip route)

## Commands

```bash
# Test
php artisan test --compact --filter=TripsTable

# Lint
vendor/bin/pint --format agent

# Dev
composer run dev
```

## Project Structure

```
app/Filament/Resources/Trips/Tables/
  TripsTable.php       ← Main table (this spec modifies)
app/Filament/Tables/Columns/
  UniqueMapColumn.php  ← Custom map column (reused)
app/Models/
  Vehicle.php          ← GPS fields: gps_lat, gps_lng, gps_speed, gps_address
  ShiftVehicle.php     ← updated_at for Xe chờ
```

## Testing Strategy

- Use Pest (existing framework)
- Feature test for the TripsTable: verify columns render with correct data sources
- Verify "Vị trí thực tế trên GPS" shows Vehicle GPS data
- Verify "Trạng thái" shows correct labels based on order statuses

## Boundaries

- **Always:** Run `pint` after changes, run tests before committing, follow existing code patterns in this file
- **Ask first:** Adding new dependencies, changing database schema
- **Never:** Remove existing functionality without approval, modify Vehicle model without asking

## Success Criteria

1. All 15 columns match the CSV order
2. "Vị trí thực tế trên GPS" renders a UniqueMapColumn with Vehicle GPS coordinates
3. "Trạng thái" shows Chưa hạ/Chưa đến/Hoàn thành based on order status logic
4. "Xe chờ" shows `updated_at` as `H:i`
5. "Status" replaces "Tình trạng" label
6. "Ngày" and "Bản đồ" columns are removed
7. All tests pass

## Open Questions

1. Should the "Vị trí thực tế trên GPS" modal show additional info (driver name, last update time)?
2. Should the old "Hành trình" timeline action button be kept?
