# driver-duty-filter - Work Plan

## TL;DR (For humans)

**What:** Lọc `DriverDutyReport` chỉ hiển thị driver có ca trong ngày. Cột "Ca" hiển thị format "X, X/2, Y/2" đếm theo loại ca: Full (Cả ca), MorningHalf (Nửa ca ngày), NightHalf (Nửa ca đêm).

**Why this approach:** `buildQuery()` đã có date range và subquery cho KM. Chỉ cần thêm `whereHas('driverShifts')` để lọc. `paginateTableQuery()` đang dùng `->first()` lấy 1 ca — sửa thành `->get()` đếm tất cả ca trong range.

**What it will NOT do:** Không đổi `DriverDutySummaryWidget`. Không đổi logic date range (8h→8h). Không thêm cột mới.

**Effort:** 1 file, ~30 dòng thay đổi.

**Decisions I made for you:** Không có — format đã được bạn xác nhận rõ ràng.

## Scope

### IN

| # | Component | File | Mô tả |
|---|-----------|------|-------|
| 1 | Filter drivers | `DriverDutyReport.php` | `buildQuery()` thêm `whereHas('driverShifts')` lọc theo date range |
| 2 | Shift count display | `DriverDutyReport.php` | Cột "Ca" hiển thị "X, X/2, Y/2" |
| 3 | Fetch all shifts | `DriverDutyReport.php` | `paginateTableQuery()` lấy tất cả ca thay vì 1 ca |

### OUT (Must-NOT-Have)

- ❌ Không sửa `DriverDutySummaryWidget`
- ❌ Không đổi logic `dateRange()` (giữ 8h→8h)
- ❌ Không thêm cột mới vào bảng
- ❌ Không đổi filter pills (today/week/month)
- ❌ Không đổi station filter

## Verification strategy

**Tests-after.** Không viết test mới (1 file thay đổi, logic đơn giản). Agent QA: chạy trang, verify danh sách chỉ có driver có ca, format ca đúng.

## Execution strategy

1 wave duy nhất — 3 todos trên cùng 1 file.

## Todos

### Wave 1: Filter + Format

#### T1.1: Filter buildQuery to only show drivers with shifts
- **References:** `app/Filament/Pages/DriverDutyReport.php` method `buildQuery()` (line 195-253)
- **Acceptance:** 
  - Thêm `->whereHas('driverShifts', function ($q) use ($from, $to) { $q->whereBetween('start_time', [$from, $to])->orWhereNull('end_time'); })` vào query chain, sau `->role('driver')` và trước `->when($this->activeStationFilter...)`
  - Driver không có ca trong range bị loại khỏi danh sách
- **QA happy:** Mở trang → danh sách chỉ hiển thị driver có ít nhất 1 ca trong hôm nay. Driver không có ca không xuất hiện.
- **QA failure:** Danh sách rỗng khi đáng lẽ có driver → kiểm tra logic date range
- **Commit:** `feat: filter DriverDutyReport to only show drivers with shifts`

#### T1.2: Update paginateTableQuery to fetch all shifts and count by type
- **References:** `app/Filament/Pages/DriverDutyReport.php` method `paginateTableQuery()` (line 256-310), `app/Enums/ShiftType.php`
- **Acceptance:**
  - Dòng 263-269: thay `->first()` thành `->get()` để lấy TẤT CẢ ca trong range
  - Thêm logic đếm ca theo loại:
    ```php
    $shifts = DriverShift::where('driver_id', $driver->id)
        ->where(function ($q) use ($from, $to) {
            $q->whereBetween('start_time', [$from, $to])
              ->orWhereNull('end_time');
        })
        ->orderByDesc('start_time')
        ->get();
    
    $fullCount = $shifts->where('shift_type', ShiftType::Full)->count();
    $morningHalfCount = $shifts->where('shift_type', ShiftType::MorningHalf)->count();
    $nightHalfCount = $shifts->where('shift_type', ShiftType::NightHalf)->count();
    ```
  - `$shift` variable (cho vehicle/trip lookup) vẫn dùng `$shifts->first()` — lấy ca gần nhất như cũ
- **QA happy:** Driver có 2 ca (1 Full + 1 MorningHalf) → hiển thị "1, 1/2, 0/2"
- **QA failure:** Count sai → kiểm tra `where()` condition trên collection
- **Commit:** combined with T1.3

#### T1.3: Update shift column display format
- **References:** `app/Filament/Pages/DriverDutyReport.php` column `shift_type` (line 94-97), `app/Enums/ShiftType.php`
- **Acceptance:**
  - Dòng 94-97: thay `formatStateUsing` hiện tại thành:
    ```php
    ->formatStateUsing(function ($state, $record) {
        $full = (int)($record->full_count ?? 0);
        $morning = (int)($record->morning_half_count ?? 0);
        $night = (int)($record->night_half_count ?? 0);
        
        $parts = [];
        if ($full > 0) $parts[] = $full;
        else $parts[] = '0';
        $parts[] = ($morning > 0 ? $morning : '0') . '/2';
        $parts[] = ($night > 0 ? $night : '0') . '/2';
        
        return implode(', ', $parts);
    })
    ```
  - Thêm `full_count`, `morning_half_count`, `night_half_count` vào `$driver` object trong `paginateTableQuery()` (dòng 288-300)
  - Format kết quả: "1, 0/2, 0/2" (1 Full, 0 MorningHalf, 0 NightHalf)
- **QA happy:** Cột "Ca" hiển thị đúng format. VD: driver có 1 Full → "1, 0/2, 0/2"
- **QA failure:** Format sai → kiểm tra count logic trong T1.2
- **Commit:** `feat: show multi-shift count in DriverDutyReport`

## Final verification wave

| ID | Check | Method |
|----|-------|--------|
| F1 | Plan compliance | Diff `DriverDutyReport.php` — only 1 file changed |
| F2 | Code quality | `vendor/bin/pint --format agent` — zero errors |
| F3 | Real QA | Mở trang `/app/driver-duty-report` → danh sách có driver + ca format đúng |
| F4 | Scope fidelity | `grep -r "whereHas.*driverShifts" app/Filament/Pages/DriverDutyReport.php` — confirmed |

## Commit strategy

1 commit: `feat: filter DriverDutyReport to only show drivers with shifts today`

## Success criteria

1. Trang chỉ hiển thị driver có ít nhất 1 ca trong date range đã chọn
2. Cột "Ca" hiển thị "X, X/2, Y/2" (Full, MorningHalf, NightHalf)
3. Driver không có ca KHÔNG xuất hiện trong danh sách
4. Filter pills (today/week/month/station) vẫn hoạt động bình thường
