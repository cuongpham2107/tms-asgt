# driver-duty-summary-table — Draft

## Metadata

- **slug:** driver-duty-summary-table
- **intent:** clear
- **review_required:** false
- **status:** generating

## Components

| ID | Component | Outcome |
|----|-----------|---------|
| C1 | Remove widget | Xóa `getHeaderWidgets()` + `getHeaderWidgetsColumns()` khỏi page |
| C2 | Summary data method | Chuyển logic tính toán từ widget vào method mới `getSummaryData()` |
| C3 | Blade grid layout | Grid 2-1: left col-span-2 summary table, right col-span-1 main table |

## Open-assumptions

| Default | Rationale | Reversible? |
|---------|-----------|-------------|
| Màu station dùng màu cũ từ widget/enum | Giữ nhất quán | ✅ đổi được |
| Filter station vẫn hoạt động với summary | Summary luôn hiển thị tất cả station | ✅ đổi được |
| Main table giữ filter pills ở trên cùng | Không thay đổi logic filter | ✅ |
