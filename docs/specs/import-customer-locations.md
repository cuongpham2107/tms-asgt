# Spec: Import Customer & Location Data from CSV

## Objective

Import 2 CSV files (`customer-location-hhhk.csv`, `customer-location-hangngoai.csv`) into the `customers` and `locations` tables. Replace all existing demo data with real production data from the CSV files.

## Tech Stack

- Laravel 13 / PHP 8.4
- MySQL (via Laravel schema)
- Models: `App\Models\Customer`, `App\Models\Location`
- Enums: `App\Enums\LocationType`

## Commands

```bash
# Run seeder
php artisan db:seed --class=CustomerLocationSeeder

# Verify
php artisan tinker --execute 'echo "Customers: " . App\Models\Customer::count() . ", Locations: " . App\Models\Location::count();'

# Run seeder + verify (repeatable)
php artisan db:seed --class=CustomerLocationSeeder && php artisan tinker --execute 'print_r(["customers" => App\Models\Customer::pluck("code")->all(), "locations" => App\Models\Location::pluck("code")->all()]);'
```

## Project Structure

```
database/seeders/CustomerLocationSeeder.php   → New seeder
docs/specs/import-customer-locations.md       → This spec
customer-location-hhhk.csv                    → Source data (HHHK)
customer-location-hangngoai.csv               → Source data (hàng ngoại)
```

## CSV → Model Mapping

| CSV Column | Customer | Location |
|---|---|---|
| Mã khách hàng | `code` | — |
| Địa điểm viết tắt | — | `code` |
| Tên công ty chi tiết / Công ty | `name` | — |
| Địa chỉ | `address` | `address` |
| Xã/phường (HN only) | — | — (ignored) |
| Tỉnh (HN only) | — | — (ignored) |
| — | `is_active = true` | `is_active = true`, `loc_type = LocationType::Pickup` |

**Location.name** = value of "Địa điểm viết tắt" (same as `code`).

## Code Style

```php
// Seeder pattern — use DB::table for bulk upsert
DB::table('customers')->upsert(
    $customerRows,
    'code',
    ['name', 'address']
);
```

Key conventions:
- Use `DB::table` (not Eloquent) for bulk performance
- Use `upsert()` for idempotent re-runs (won't fail)
- Parse CSV with `fgetcsv` (built-in, no extra deps)
- Delete old data before import: `DB::table('customers')->truncate()` + `DB::table('locations')->truncate()`
- Run import inside `DB::transaction`

## Testing Strategy

- No automated tests for a one-time import seeder
- Verify manually: check record counts match expectations
  - `customer-location-hhhk.csv`: 23 data rows
  - `customer-location-hangngoai.csv`: 206 data rows
  - Total expected customers should be ≤ total rows (deduplicated by code)
  - Total expected locations should equal number of rows (229)

## Boundaries

- **Always:** Truncate old data before import; wrap in transaction; validate CSV exists
- **Ask first:** Changing table schema, adding dependencies, modifying existing seeders
- **Never:** Commit hardcoded secrets; edit vendor files; run in production without backup

## Success Criteria

- [ ] Running `php artisan db:seed --class=CustomerLocationSeeder` completes without error
- [ ] `Customer::count()` equals number of unique `Mã khách hàng` values across both files
- [ ] `Location::count()` equals total number of rows across both files (229)
- [ ] All `Customer.code` and `Location.code` values match the source CSV columns
- [ ] Running the seeder twice produces the same result (idempotent)

## Open Questions

- [x] Xoá dữ liệu cũ trước khi import? → Yes (truncate)
- [x] Location name = gì? → Địa điểm viết tắt (same as code)
- [x] Seeder hay command? → Database seeder
- [x] Xử lý trùng code? → Upsert, không skip
