# driver-duty-filter — Draft

## Metadata

- **slug:** driver-duty-filter
- **intent:** clear
- **review_required:** false
- **status:** generating

## Components

| ID | Component | Outcome |
|----|-----------|---------|
| C1 | Filter drivers with shifts only | `buildQuery()` adds `whereHas('driverShifts', ...)` |
| C2 | Multi-shift count display | Column "Ca" shows "X, X/2, Y/2" format |

## Decisions

- **D1:** Format "X, X/2, Y/2" = Full_count, MorningHalf_count, NightHalf_count — user confirmed
- **D2:** Filter uses existing `dateRange()` logic (8h→8h) — no change needed
- **D3:** `paginateTableQuery()` fetches ALL shifts in range, counts by type — replaces single `->first()` with `->get()`
