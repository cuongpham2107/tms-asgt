# driver-duty-summary-table - Work Plan

## TL;DR (For humans)

**What:** Bỏ `DriverDutySummaryWidget`, thay bằng summary table bên trái layout grid 2-1. Table hiển thị theo điểm trực: Đi làm (TTL, X, Y/2), Nghỉ (X), TTL. Có dòng tổng cuối bảng.

**Why:** Widget stats cards khó đọc số liệu chi tiết. Table giúp nhìn tổng quan từng station rõ ràng hơn.

**Effort:** 2 files: `DriverDutyReport.php` + `driver-duty-report.blade.php`. ~50 dòng.

## Scope

### IN
- Xóa `getHeaderWidgets()` và `getHeaderWidgetsColumns()` khỏi `DriverDutyReport.php`
- Thêm method `getSummaryData()` tính summary theo station (chuyển logic từ widget)
- Blade view: grid 2-1 (`grid grid-cols-3`), left `col-span-2` summary table
- Summary table columns: Điểm trực | Đi làm (TTL, X, Y/2) | Nghỉ (X) | TTL
- Dòng "Tổng lái xe" cuối bảng

### OUT
- ❌ Không đổi logic date range filter
- ❌ Không đổi station filter
- ❌ Không đổi main table
- ❌ Không xóa file `DriverDutySummaryWidget.php` (giữ lại, chỉ bỏ register)

## Verification strategy

Tests-after. Agent QA: mở trang, verify layout grid 2-1, số liệu table khớp.

## Execution strategy

1 wave, 2 files.

## Todos

#### 1. Remove widget + add summary method to DriverDutyReport.php
- **References:** `app/Filament/Pages/DriverDutyReport.php`, `app/Filament/Widgets/DriverDutySummaryWidget.php`
- **Acceptance:**
  - Xóa `getHeaderWidgets()` method (lines 47-52)
  - Xóa `getHeaderWidgetsColumns()` method (lines 54-57)
  - Thêm method `getSummaryData()` trả về `array` với cấu trúc:
    ```php
    protected function getSummaryData(): array
    {
        [$from, $to] = $this->dateRange();
        $drivers = User::role('driver')->where('is_active', true)->get();
        
        $stations = [];
        $grandTotal = 0; $grandWorking = 0; $grandFull = 0; $grandHalf = 0;
        
        foreach (OnDutyLocation::cases() as $station) {
            $stationDrivers = $drivers->filter(fn ($d) => $d->station === $station);
            $total = $stationDrivers->count();
            if ($total === 0) continue;
            
            $working = 0; $full = 0; $half = 0;
            foreach ($stationDrivers as $driver) {
                $shift = DriverShift::where('driver_id', $driver->id)
                    ->where(function ($q) use ($from, $to) {
                        $q->whereBetween('start_time', [$from, $to])
                          ->orWhereNull('end_time');
                    })->first();
                if ($shift) {
                    $working++;
                    if ($shift->shift_type === ShiftType::Full) $full++;
                    else $half++;
                }
            }
            
            $grandTotal += $total; $grandWorking += $working;
            $grandFull += $full; $grandHalf += $half;
            
            $stations[] = [
                'label' => $station->getLabel(),
                'color' => $station->getColor(),
                'total' => $total,
                'working' => $working,
                'full' => $full,
                'half' => $half,
                'off' => $total - $working,
            ];
        }
        
        return [
            'stations' => $stations,
            'grand' => [
                'total' => $grandTotal,
                'working' => $grandWorking,
                'full' => $grandFull,
                'half' => $grandHalf,
                'off' => $grandTotal - $grandWorking,
            ],
        ];
    }
    ```
  - Truyền `summaryData` vào view qua `protected function getViewData(): array`
- **QA:** Mở trang → không còn widget stats cards. `dump($this->getSummaryData())` cho ra array đúng format.
- **Commit:** `feat: replace DriverDutySummaryWidget with inline summary table`

#### 2. Update blade view with grid layout + summary table
- **References:** `resources/views/filament/pages/driver-duty-report.blade.php`
- **Acceptance:**
  - Bọc nội dung trong grid container:
    ```blade
    <div class="grid grid-cols-3 gap-4">
        {{-- Left: summary table (col-span-2) --}}
        <div class="col-span-2">
            @php $data = $this->getSummaryData(); @endphp
            <table class="w-full text-sm border">
                <thead>
                    <tr class="bg-gray-100">
                        <th class="p-2 text-left">Điểm trực</th>
                        <th class="p-2 text-center" colspan="3">Đi làm</th>
                        <th class="p-2 text-center">Nghỉ</th>
                        <th class="p-2 text-center">TTL</th>
                    </tr>
                    <tr class="bg-gray-50 text-xs text-gray-500">
                        <th></th>
                        <th class="p-1 text-center">TTL</th>
                        <th class="p-1 text-center">X</th>
                        <th class="p-1 text-center">Y/2</th>
                        <th class="p-1 text-center">X</th>
                        <th class="p-1 text-center"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($data['stations'] as $s)
                    <tr class="border-t hover:bg-gray-50">
                        <td class="p-2 font-medium">{{ $s['label'] }}</td>
                        <td class="p-2 text-center">{{ $s['working'] }}</td>
                        <td class="p-2 text-center">{{ $s['full'] }}</td>
                        <td class="p-2 text-center">{{ $s['half'] }}</td>
                        <td class="p-2 text-center">{{ $s['off'] }}</td>
                        <td class="p-2 text-center font-bold">{{ $s['total'] }}</td>
                    </tr>
                    @endforeach
                    <tr class="border-t font-bold bg-gray-100">
                        <td class="p-2">Tổng lái xe</td>
                        <td class="p-2 text-center">{{ $data['grand']['working'] }}</td>
                        <td class="p-2 text-center">{{ $data['grand']['full'] }}</td>
                        <td class="p-2 text-center">{{ $data['grand']['half'] }}</td>
                        <td class="p-2 text-center">{{ $data['grand']['off'] }}</td>
                        <td class="p-2 text-center">{{ $data['grand']['total'] }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        
        {{-- Right: main table (col-span-1) --}}
        <div class="col-span-1">
            {{ $this->table }}
        </div>
    </div>
    ```
  - Giữ filter pills ở trên grid
  - Màu station dùng CSS inline từ `$s['color']`
- **QA:** Mở trang → layout 2 cột. Summary table bên trái (2/3), main table bên phải (1/3). Số liệu khớp.
- **Commit:** combined with todo 1

## Final verification wave

| ID | Check | Method |
|----|-------|--------|
| F1 | Widget removed | Grep `getHeaderWidgets` trong `DriverDutyReport.php` → không có |
| F2 | Layout đúng | Mở trang → grid 2 cột hiển thị đúng |
| F3 | Số liệu đúng | So sánh summary table với main table → khớp |

## Commit strategy

1 commit: `feat: replace DriverDutySummaryWidget with inline summary table`

## Success criteria

1. Widget `DriverDutySummaryWidget` không còn hiển thị trên page
2. Summary table bên trái hiển thị đúng format: Điểm trực | Đi làm (TTL, X, Y/2) | Nghỉ | TTL
3. Dòng "Tổng lái xe" ở cuối bảng với số liệu tổng
4. Layout grid 2-1 hoạt động (col-span-2 left, col-span-1 right)
5. Filter pills vẫn hoạt động bình thường
