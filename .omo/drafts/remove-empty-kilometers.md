# remove-empty-kilometers — Draft

## Metadata

- **slug:** remove-empty-kilometers
- **intent:** clear
- **review_required:** false
- **status:** complete
- **created:** 2026-07-15

## Components

| ID | Component | Outcome | Status |
|----|-----------|---------|--------|
| C1 | Remove dead EmptyKilometer code | 7 references cleaned (migration drop, model, controller, resource, policy, route, demo script) | planned |
| C2 | Schema: is_empty_run + note on trips | Migration + Trip model updated | planned |
| C3 | CreateEmptyRunAction headerAction | New Filament action on ListTrips | planned |
| C4 | Update ReassignDriverAction | create_return_trip → is_empty_run=true | planned |
| C5 | Mobile: empty-run trip display | Badge + driver updates pre-created checkpoints | planned |
| C6 | Verify existing tests | No regressions expected | planned |

## Decisions

- **D1:** `is_empty_run` column (boolean) + `TripStatus::ReturnTrip` — cả hai cùng tồn tại (boolean cho filter UI, status cho TripCheckpointService guard)
- **D2:** Location trực tiếp cho checkpoint (không qua OrderDeliveryPoint) — user choice
- **D3:** CheckpointFactory bị bypass — checkpoint tạo trực tiếp trong action với `order_id = null`
- **D4:** Không viết test mới — user choice
- **D5:** Giữ migration gốc `2026_05_21_152105` — tạo migration MỚI để drop table
- **D6:** Không sửa TripCheckpointService, CheckpointEndHandler, KM calculators

## Metis findings incorporated

- 🔴 CheckpointFactory bypass → documented in plan (tạo checkpoint trực tiếp, không qua factory)
- 🔴 is_empty_run + ReturnTrip redundancy → resolved: cả hai cùng dùng (is_empty_run cho filter, status cho guard)
- 🔴 Migration deletion → fixed: tạo migration mới để drop, giữ nguyên migration gốc
- 🟡 Demo script → update (xóa line 224), không xóa file
- 🟡 Shield cleanup → thêm todo T2.7
