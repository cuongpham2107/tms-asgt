# Kế hoạch loại bỏ hoàn toàn module OrderTemplate (Mẫu đơn định kỳ)

## 1. Tổng quan & Khảo sát hiện trạng

- **Mục tiêu**: Loại bỏ triệt để và an toàn module `OrderTemplate` (Mẫu đơn định kỳ) khỏi codebase, giao diện quản trị Filament, cơ sở dữ liệu và phân quyền Shield.
- **Dữ liệu hiện tại**: Bảng `order_templates` hiện có **0 bản ghi** (`count = 0`), chưa từng phát sinh dữ liệu trong vận hành.
- **Phụ thuộc ngoại (Foreign Keys)**: Không có bảng nào trỏ FK đến `order_templates`. Bảng chỉ tham chiếu tới `users.id` (người tạo).
- **Hệ thống ngầm / Scheduler**: Không có Console Command, Job hay Scheduler nào đang chạy `cron_expression` hoặc `daily_run_at`.
- **API & Mobile**: Không có route hoặc controller API nào liên quan đến `order_templates`.
- **Test suite**: Không có test nào phụ thuộc vào `OrderTemplate`.
- **Phân quyền**: Có 12 permissions Filament Shield liên quan đến `OrderTemplate` trong database.

---

## 2. Danh sách các file & thành phần cần loại bỏ / điều chỉnh

### A. Tầng Giao diện Quản trị (Filament UI)
Xóa toàn bộ thư mục `app/Filament/Resources/OrderTemplates/`:
- `OrderTemplateResource.php`
- `Tables/OrderTemplatesTable.php`
- `Schemas/OrderTemplateForm.php`
- `Schemas/OrderTemplateInfolist.php`
- `Pages/CreateOrderTemplate.php`
- `Pages/EditOrderTemplate.php`
- `Pages/ListOrderTemplates.php`

### B. Tầng Phân quyền & Chính sách (Policy)
- Xóa file `app/Policies/OrderTemplatePolicy.php`.
- Xóa 12 permissions dạng `*:OrderTemplate` khỏi bảng `permissions` và đồng bộ lại Shield.

### C. Tầng Model & Quan hệ (Eloquent)
- Xóa file `app/Models/OrderTemplate.php`.
- Trong `app/Models/User.php`: Xóa quan hệ `orderTemplates(): HasMany`.

### D. Tầng Cơ sở dữ liệu (Database Schema)
- Tạo migration mới: `drop_order_templates_table` để drop bảng `order_templates` an toàn (giữ tính toàn vẹn lịch sử migration cho môi trường production/staging).

### E. Tầng Tài liệu (Documentation)
- Cập nhật `database/SCHEMA_REFERENCE.md`:
  - Xóa mục `15. order_templates — Mẫu đơn định kỳ`.
  - Xóa liên kết `order_templates.created_by` trong sơ đồ quan hệ Entity-Relationship.

---

## 3. Thứ tự triển khai chi tiết (Implementation Steps)

### Bước 1: Database Migration
1. Tạo migration:
   ```bash
   php artisan make:migration drop_order_templates_table
   ```
2. Nội dung migration:
   ```php
   public function up(): void
   {
       Schema::dropIfExists('order_templates');
   }

   public function down(): void
   {
       // Re-create schema nếu rollback
   }
   ```
3. Chạy migration:
   ```bash
   php artisan migrate
   ```

### Bước 2: Dọn dẹp Permissions & Shield
1. Xóa các permissions không còn sử dụng:
   ```bash
   php artisan tinker --execute '\Spatie\Permission\Models\Permission::where("name", "like", "%OrderTemplate%")->delete();'
   ```
2. Đồng bộ lại Shield policies & permissions:
   ```bash
   php artisan shield:generate --all --ignore-config-changes
   ```

### Bước 3: Xóa Model & cập nhật Model User
1. Xóa `app/Models/OrderTemplate.php`.
2. Sửa `app/Models/User.php`, xóa method:
   ```php
   public function orderTemplates(): HasMany
   {
       return $this->hasMany(OrderTemplate::class, 'created_by');
   }
   ```

### Bước 4: Xóa Resource Filament & Policy
1. Xóa thư mục `app/Filament/Resources/OrderTemplates/`.
2. Xóa `app/Policies/OrderTemplatePolicy.php`.

### Bước 5: Cập nhật tài liệu
1. Cập nhật `database/SCHEMA_REFERENCE.md` bỏ schema và quan hệ của `order_templates`.

### Bước 6: Kiểm tra & Nghiệm thu (Verification)
1. Format code chuẩn Laravel:
   ```bash
   vendor/bin/pint --format agent
   ```
2. Chạy test suite:
   ```bash
   php artisan test --compact
   ```
3. Xóa cache tối ưu hóa của Laravel:
   ```bash
   php artisan optimize:clear
   ```
4. Kiểm tra danh sách route:
   ```bash
   php artisan route:list
   ```

---

## 4. Đánh giá rủi ro & Biện pháp xử lý (Risks & Mitigations)

| Rủi ro | Mức độ | Biện pháp giảm thiểu |
|---|---|---|
| Mất mát dữ liệu | Không có (0%) | Bảng `order_templates` hiện có 0 bản ghi. |
| Lỗi route binding / menu Filament | Thấp | Xóa trọn gói Resource, Pages, Policy và chạy `optimize:clear`. |
| Rác permission trong DB | Thấp | Đã có bước xóa trực tiếp trong Spatie table và chạy lại `shield:generate`. |
| Xung đột migration môi trường khác | Rất thấp | Dùng migration drop bảng mới thay vì sửa/xóa migration cũ. |
