# Bảng kê đối chiếu vận chuyển theo khách hàng

Cập nhật: 05/11/2026. Nguồn mẫu: 17 file Excel trong thư mục "MẪU BẢNG KÊ THEO KHÁCH" (sếp gửi).

## 1. Dùng thế nào

1. Vào **Bảng kê** và mở tab **Hàng ngoài**.
2. Lọc **đúng 1 khách hàng** và chọn khoảng ngày (kỳ). Lọc thêm nếu cần, vì file xuất ra đúng các dòng đang hiện trên bảng.
3. Bấm **Xuất bảng kê**, rồi điền:
   - **Mẫu**: một khách có thể có nhiều mẫu. Ví dụ CLC có hàng thường, hàng lẻ và SDV NBA (kỳ).
   - **Tiêu đề**: tự điền "… THÁNG mm/yyyy", sửa được.
   - **Ngày ký**.
   - **Ký hiệu / số / ngày hoá đơn GTGT** (không bắt buộc). Nếu điền thì file có thêm dòng "Kèm theo hóa đơn GTGT…".
4. Sau khi xuất, các dòng **đã có giá** bị khoá (`exported_at`). Dòng chưa có giá thì không bị khoá. Muốn sửa dòng đã khoá thì mở khoá trong modal cước.

Nút **Xuất Excel (.xlsx)** cũ vẫn giữ nguyên, không thay đổi.

## 2. Nội dung file bảng kê

| Phần | Nội dung |
|---|---|
| Đầu trang | Tên, địa chỉ, MST của Công ty TNHH Vận tải ASG |
| Tiêu đề | Lấy theo ô Tiêu đề, dòng hoá đơn GTGT (nếu có) |
| Khách hàng | Tên, địa chỉ, **MST** (lấy từ form Khách hàng) |
| Bảng | Header 2 tầng, nền xanh lá `92D050`, cột đúng thứ tự và tên của mẫu khách. Cột không có dữ liệu để trống |
| Dòng Tổng | Công thức `SUM` cho các cột tiền |
| Cuối trang | Tổng số tiền bằng chữ, "Thái Nguyên, ngày …", chữ ký 2 bên |

- Số tiền ghi đúng **số đã chốt** trong `order_charges`, file không tự tính lại.
- Phí khác không có cột riêng trong mẫu của khách được ghi vào cột Ghi chú, nhưng vẫn cộng vào Tổng. Khi gặp trường hợp này web sẽ báo.
- Ghi chú của từng phí cũng được ghi vào cột Ghi chú.
- Điểm lấy và điểm trả in **địa chỉ đầy đủ**. Đơn nhiều điểm trả ghi "Điểm 1: … / Điểm 2: …".

## 3. Danh sách mẫu

| Khoá mẫu | Mã khách | Ghi chú |
|---|---|---|
| ADVANT | ADVANT | Có Pallet, Phí lưu đêm/ngày |
| ALSB | ALSB | Có Phí VCHNH |
| ALSE | ALSE | Tạm thời 1 đơn 1 dòng (xem mục 6) |
| ASGL | ASGL | Đại Đồng / SEVT: có lưu ca đêm |
| ASGL_HANG_LE | ASGL | Có Chi phí vé (vé kho và vé cao tốc) |
| ASGS | ASGS | Núi Pháo |
| CLC | CLC | Hàng thường, có cột Thuê xe ngoài |
| CLC_HANG_LE | CLC | Có cột Bill |
| CLC_KY_02 | CLC | SDV NBA theo kỳ: lọc ngày theo kỳ trước khi xuất |
| KIOWAY | KIOWAY | |
| NHẬT MINH | NHẬT MINH | Có cột Tên quận (khu vực tính cước của điểm trả) |
| SDS HN | SDS HN | Hàng rượu, có cột Số lượng thùng |
| SDS HCM | SDS HCM | Có Pallet, Ngày lễ, Kéo hàng, Xe nâng |
| SPA | SPA | |
| UNILO | UNILO | |
| VIL | VIL | |
| SAFO_XUAT | ASGS (SAFO) | **Mỗi điểm trả 1 dòng**, các dòng của 1 đơn dùng chung STT, tiền chỉ ở dòng đầu |
| SAFO_NHAP | ASGS (SAFO) | Trả 1 điểm, có Thời gian yêu cầu |
| VFC_THUONG | ASGS (VFC) | Số phiếu giao hàng nối vào 1 ô, trọng lượng cộng dồn |
| VFC_LANH | ASGS (VFC) | Như trên, có thêm Địa chỉ cũ và Pallet |

Mẫu được viết trong code: `app/Services/Export/BillingStatement/BillingStatementTemplates.php`. Mỗi cột ghi theo dạng `[khoá dữ liệu, tiêu đề nhóm, tiêu đề con]`. Khách đổi mẫu thì sửa file này.

## 4. Thay đổi về dữ liệu và cách tính cước

Migration: `2026_11_05_204212_add_billing_statement_fields`.

| Bảng | Trường mới | Nhập ở đâu |
|---|---|---|
| customers | `tax_code` (MST), `fuel_adjustment_percent` (% dầu), `cancel_trip_percent` (% huỷ chuyến) | Form Khách hàng |
| orders | `pallet_count`, `bill_number` (Bill/AWB) | Form Đơn hàng (chỉ đơn hàng ngoài) |
| locations | `old_address` (địa chỉ cũ) | Form Địa điểm |
| order_charges | `is_cancelled_trip`, `cancel_trip_percent` | Modal sửa cước |
| fee_types | Thêm: lưu đêm/ngày, ngày lễ tết, vé cao tốc, phí vào phố | Danh mục loại phí |

Quy tắc tính cước:

- **Đơn giá sau điều chỉnh** = đơn giá gốc × (1 + % dầu), **luôn làm tròn lên đến nghìn** (như hàm `ROUNDUP(x,-3)` của Excel).
- **% dầu** và **% huỷ chuyến** lấy từ khách hàng **lúc tạo cước**. Đổi % ở khách sau đó thì cước đã tính vẫn giữ nguyên.
- **Huỷ chuyến**: bật trong modal cước, đơn giá gốc được nhân với % huỷ. Quay đầu và huỷ chuyến có thể nhân chồng lên nhau.
- **VAT**: kế toán điền trong modal sửa cước (mặc định 0%).
- **Rớt điểm và các phí khác**: nhập tay số tiền trong mục "Phí khác & ghi chú", mỗi dòng phí có thêm ô ghi chú.

## 4b. Phí riêng của khách

Menu **Danh mục phí**:
- Phí **không chọn khách** là phí dùng chung, kế toán nhập tay số tiền như trước.
- Phí **có chọn khách** là phí riêng, có **cách tính** và **đơn giá**.

| Cách tính | Số tiền = | Số lượng gợi ý |
|---|---|---|
| Cố định / đơn | Đơn giá × số lượng | 1 |
| Theo số pallet | Đơn giá × số pallet | Số pallet của đơn |
| Theo số thùng/kiện | Đơn giá × số thùng | Số kiện của đơn |
| Theo mỗi điểm rớt | Đơn giá × số điểm | Số điểm trả từ điểm thứ 2 |
| % đơn giá chính | Đơn giá gốc × % | 1 |

Trong **modal Cước đơn**, mục "Phí khác & ghi chú" chỉ liệt kê phí dùng chung và phí riêng của khách đang mở:
- Khi chọn một phí riêng hoặc nhập **Số lượng**, hệ thống tự tính số tiền theo đơn giá.
- Sửa số lượng thì số tiền tự tính lại.
- Số tiền vẫn sửa tay được.
- Ví dụ SAFO trả UPS → ALSC → ALSE: nhập số lượng 1 ở "Rớt điểm trong NBA" và 1 ở "Rớt điểm ALSE".

Phí bật **"Tự cộng cho mọi đơn của khách"** (ví dụ bốc xếp SDS HN theo thùng) thì hệ thống tự thêm khi đơn hoàn thành hoặc khi bấm "Tính lại". Khi tính lại, chỉ các phí tự cộng này được thay. Phí kế toán đã chọn hoặc nhập tay thì giữ nguyên, không cộng trùng.

**Mã phí** là nhóm cột trong bảng kê, nên nhiều mức phí dùng chung một mã. Ví dụ 4 mức rớt điểm của SAFO đều mang mã `extra_drop` và cùng vào cột "Chi phí rớt điểm".

Tạo dữ liệu mẫu theo bảng phụ phí của sếp. Có thể chạy lại nhiều lần mà không tạo trùng:

```bash
php artisan fee-types:seed-samples --dry-run
php artisan fee-types:seed-samples
```

## 5. Mã nguồn chính

- `app/Services/Export/BillingStatement/BillingStatementExporter.php`: dựng file bảng kê.
- `app/Services/Export/BillingStatement/BillingStatementTemplates.php`: danh sách mẫu.
- `app/Services/Export/BillingStatement/VietnameseMoneyWords.php`: đọc số tiền thành chữ.
- `app/Filament/Pages/TripStatisticsReport.php`: nút "Xuất bảng kê" (`exportBillingStatementAction`) và modal cước.
- `app/Models/OrderCharge.php`: `roundUpThousand`, `priceMultiplier`.
- Test:
  - `tests/Feature/BillingStatementExportTest.php`
  - `tests/Unit/VietnameseMoneyWordsTest.php`
  - Bổ sung trong `OrderChargeCalculatorTest` và `TripStatisticsChargeTest`.

## 6. Còn chờ chốt với sếp

1. **ALSE 2 chặng** (Quế Võ → VSIP → NBA trên 1 dòng): trên dữ liệu thật, mỗi đơn ALSE chỉ có 1 điểm trả, chuyến 2 chặng đang được tạo thành 2 đơn. Cần chọn một trong hai cách: tạo 1 đơn có 2 điểm trả, hoặc thêm quy tắc ghép 2 đơn.
2. **SAFO "Chi phí rớt điểm ct"** theo từng loại điểm (NBA 50k, ALSE 400k…): hiện kế toán nhập tổng tiền rớt điểm cho cả đơn.
3. **VFC**: bảng kê mới ghi nhiều phiếu trên 1 dòng theo mẫu của sếp. Trong khi đó, file Excel cũ của hệ thống tách mỗi phiếu thành 1 dòng.
4. Mẫu CLC hàng thường có tiêu đề cột nền vàng `FFE699`. Hiện hệ thống dùng nền xanh lá chung cho mọi mẫu.

## 7. Triển khai

```bash
php artisan migrate
pm2 restart tms-queue
```

Sau khi migrate, cần nhập vào form Khách hàng cho từng khách: **MST, % dầu, % huỷ chuyến**.
