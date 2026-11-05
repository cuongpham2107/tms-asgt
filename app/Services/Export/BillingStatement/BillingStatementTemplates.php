<?php

namespace App\Services\Export\BillingStatement;

/**
 * Mẫu bảng kê theo từng khách hàng — chép đúng thứ tự và tên cột của file Excel mẫu
 * (thư mục "MẪU BẢNG KÊ THEO KHÁCH"). ALSE đang xuất 1 đơn 1 dòng (prod mỗi đơn 1 điểm trả, chưa ghép 2 chặng).
 *
 * Mỗi cột: [khoá dữ liệu, tiêu đề nhóm, tiêu đề con].
 *  - Tiêu đề con null → ô tiêu đề gộp dọc 2 dòng.
 *  - Tiêu đề nhóm '' → cột nối tiếp nhóm của cột trước (gộp ngang tiêu đề nhóm).
 * Khoá "fee:a,b" cộng các loại phí theo mã fee_types; "fee_rest" nhận mọi phí chưa có cột riêng
 * (mẫu không có cột "Chi phí khác" thì phí đó ghi vào cột Ghi chú, vẫn cộng trong Tổng).
 * "{vat}" trong tiêu đề được thay bằng % VAT của các dòng.
 *
 * ponytail: mẫu viết trong code, khách đổi mẫu thì sửa ở đây; chuyển thành bảng cấu hình khi admin cần tự sửa.
 */
class BillingStatementTemplates
{
    /**
     * @return array<string, array{customer_code: string, label: string, title: string, customer_label: string, columns: list<array{0: string, 1: string, 2: ?string}>, layout: string}>
     */
    public static function all(): array
    {
        return [
            'ADVANT' => self::make('ADVANT', 'ADVANT', 'BẢNG ĐỐI CHIẾU VẬN CHUYỂN HÀNG ADVANT', [
                ['stt', 'STT', null],
                ['date', 'Ngày', null],
                ['plate', 'Biển số xe', null],
                ['pickup', 'Lộ trình', 'Điểm đóng hàng'],
                ['drop', '', 'Điểm trả hàng'],
                ['drop_old_address', '', 'Nơi nhận địa chỉ cũ'],
                ['tonnage', 'Loại xe (tấn)', null],
                ['pallet', 'Pallet', null],
                ...self::times('TG chi tiết', 'Thời gian đóng hàng', 'Thời gian xe chạy', 'Thời gian đến', 'Thời gian hạ hàng'),
                ['unload_date', 'Ngày hạ hàng', null],
                ...self::durations('TG đóng/ trả hàng', 'TG xe đóng hang', 'TG xe di chuyển', 'TG xe hạ hàng'),
                ...self::waits('TG lưu xe', 'TG chờ đóng hàng', 'TG chờ hạ hàng', 'Tổng TG chờ'),
                ['base_price', 'Đơn giá trước điều chỉnh cước (VND)', null],
                ['adjusted_price', 'Đơn giá (VND)', null],
                ['waiting_fee', 'Phí giờ chờ', null],
                ['fee:overnight_storage', 'Phí lưu đêm/ngày', null],
                ['fee_rest', 'Phí khác', null],
                ...self::totals('Tổng', 'Trị giá (VND)'),
                ['note', 'Ghi chú', null],
            ], customerLabel: 'Bên thuê vận chuyển'),

            'ALSB' => self::make('ALSB', 'ALSB', 'BẢNG KÊ ĐỐI CHIẾU VẬN CHUYỂN HÀNG ALSB', [
                ...self::head('Biển số xe', 'Điểm đóng hàng', 'Điểm trả hàng'),
                ['tonnage', 'Loại xe (tấn)', null],
                ...self::times('TG chi tiết', 'Thời gian đóng hàng', 'Thời gian xe chạy', 'Thời gian đến', 'Thời gian hạ hàng'),
                ['unload_date', 'Ngày hạ hàng', null],
                ...self::durations('TG đóng/ trả hàng', 'TG xe đóng hang', 'TG xe di chuyển', 'TG xe hạ hàng'),
                ...self::waits('TG lưu xe', 'TG chờ đóng hàng', 'TG chờ hạ hàng', 'Tổng TG chờ'),
                ['base_price', 'Đơn giá trước điều chỉnh cước (VND)', null],
                ['adjusted_price', 'Đơn giá (VND)', null],
                ['waiting_fee', 'Phí giờ chờ', null],
                ['fee:dgr_permit', 'Phí VCHNH', null],
                ['fee_rest', 'Phí khác', null],
                ...self::totals('Tổng', 'Trị giá (VND)'),
                ['note', 'Ghi chú', null],
            ], customerLabel: 'Bên thuê vận chuyển'),

            'ASGL' => self::asgl('ASGL', 'ASGL – Đại Đồng / SEVT', 'BẢNG KÊ ĐỐI CHIẾU VẬN CHUYỂN HÀNG LẺ - ASGL', [
                ['fee:shift_storage,overnight_storage', 'Chi phí lưu ca đêm', null],
                ['fee_rest', 'Chi phí khác', null],
            ]),
            'ASGL_HANG_LE' => self::asgl('ASGL', 'ASGL – Hàng lẻ', 'BẢNG KÊ ĐỐI CHIẾU VẬN CHUYỂN HÀNG LẺ- ASGL', [
                ['fee:warehouse_ticket,highway_toll', 'Chi phí vé', null],
                ['fee_rest', 'Chi phí khác', null],
            ]),

            'ASGS' => self::make('ASGS', 'ASGS – Núi Pháo', 'BẢNG KÊ ĐỐI CHIẾU VẬN CHUYỂN HÀNG NÚI PHÁO ASGT-ASGS', [
                ...self::head('Biển kiểm soát', 'Điểm đóng hàng', 'Điểm trả hàng', dateLabel: 'Ngày xuất hàng'),
                ['tonnage', 'Tải trọng tính cước (tấn)', null],
                ...self::times('TG chi tiết', 'Thời gian đóng hàng', 'Thời gian xe chạy', 'Thời gian đến', 'Thời gian hạ hàng'),
                ['unload_date', 'Ngày hạ hàng', null],
                ...self::durations('TG đóng/ trả hàng', 'TG đóng hàng', 'TG xe di chuyển', 'TG hạ hàng hàng'),
                ...self::waits('TG lưu xe', 'TG chờ đóng hàng', 'TG chờ đóng hạ hàng', 'Tổng TG chờ'),
                ...self::prices('Đơn giá trước điều chỉnh cước (VND)', 'Đơn giá trước điều chỉnh cước', 'Đơn giá (VND)', 'Đơn giá'),
                ['waiting_fee', 'Chi phí giờ chờ', null],
                ['fee:shift_storage', 'Chi lưu ca', null],
                ['fee:dgr_permit', 'Chi phí VCHNH', null],
                ['fee_rest', 'Phí khác', null],
                ...self::totals('Tổng tiền', 'Trị giá (VND)'),
                ['note', 'Ghi chú', null],
            ]),

            'CLC' => self::clc('CLC – Hàng thường', [
                ['fee:shift_storage', 'Chi phí lưu ca', null],
                ['fee_rest', 'Chi phí khác', null],
                ['fee:inspection', 'Chi phí kiểm hóa', null],
                ['fee:dunnage', 'Chi phí thuê chèn', null],
            ], extraTail: [['vehicle_owner', 'Thuê xe ngoài', null]]),
            'CLC_HANG_LE' => self::clc('CLC – Hàng lẻ', [
                ['fee:warehouse_ticket,shift_storage', 'Chi phí vé', null],
                ['fee:extra_drop', 'Chi phí rớt điểm', null],
                ['fee:inspection', 'Chi phí kiểm hóa', null],
                ['fee:dunnage', 'Chi phí thuê chèn', null],
            ], extraTail: [['bill', 'Bill', null]]),

            'KIOWAY' => self::make('KIOWAY', 'KIOWAY', 'BẢNG KÊ ĐỐI CHIẾU VẬN CHUYỂN HÀNG KIOWAY', [
                ...self::head('Biển kiểm soát', 'Điểm lấy hàng', 'Điểm trả hàng'),
                ['tonnage', 'Tải trọng tính cước', null],
                ...self::times('TG chi tiết', 'Thời gian đóng hàng', 'Thời gian đi', 'Thời gian đến', 'Thời gian hạ hàng xong'),
                ['unload_date', 'Ngày hạ hàng', null],
                ...self::durations('TG đóng/ trả hàng', 'TG xe đóng hàng', 'TG xe di chuyển', 'TG xe hạ hàng'),
                ...self::waits('TG lưu xe', 'TG lưu tại điểm đóng', 'TG lưu tại điểm trả', 'Tổng'),
                ...self::prices('Đơn giá trước điều chỉnh cước (VND)', 'Đơn giá trước điều chỉnh cước', 'Đơn giá (VND)', 'Đơn giá'),
                ['waiting_fee', 'Chi phí giờ chờ', null],
                ['fee:dgr_permit', 'Phí giấy phép VCHNH', null],
                ['fee_rest', 'Phí khác', null],
                ...self::totals('Tổng', 'Trị giá (VND)'),
                ['note', 'Ghi chú', null],
            ]),

            'NHẬT MINH' => self::make('NHẬT MINH', 'Nhật Minh', 'BẢNG KÊ ĐỐI CHIẾU VẬN CHUYỂN HÀNG NHẬT MINH', [
                ...self::head('Biển kiểm soát', 'Điểm lấy hàng', 'Điểm trả hàng'),
                ['drop_zone', 'Tên quận', null],
                ['tonnage', 'Tải trọng tính cước', null],
                ['fee:extra_drop', 'Rớt điểm', null],
                ...self::times('TG chi tiết', 'Thời gian đóng hàng', 'Thời gian đi', 'Thời gian đến', 'Thời gian hạ hàng xong'),
                ['unload_date', 'Ngày hạ hàng', null],
                ...self::durations('TG đóng/ trả hàng', 'TG xe đóng hàng', 'TG xe di chuyển', 'TG xe hạ hàng'),
                ...self::waits('TG Chờ', 'TG chờ tại điểm đóng', 'TG chờ tại điểm trả', 'Tổng'),
                ...self::prices('Đơn giá trước điều chỉnh cước (VND)', 'Đơn giá trước điều chỉnh cước', 'Đơn giá (VND)', 'Đơn giá'),
                ['fee:overnight_storage,shift_storage', 'Phí lưu đêm', null],
                ['waiting_fee', 'Chi phí giờ chờ', null],
                ['fee:loading_unloading', 'Chi phí bốc xếp', null],
                ...self::totals('Tổng', 'Trị giá (VND)'),
                ['note', 'Ghi chú', null],
            ]),

            'SDS HN' => self::make('SDS HN', 'SDS HN – Hàng rượu', 'BẢNG KÊ ĐỐI CHIẾU VẬN CHUYỂN HÀNG RƯỢU - SDS HN', [
                ...self::head('Biển kiểm soát', 'Điểm lấy hàng', 'Điểm trả hàng'),
                ['tonnage', 'Tải trọng tính cước', null],
                ['packages', 'Số lượng thùng', null],
                ...self::times('TG chi tiết', 'Thời gian đóng hàng', 'Thời gian đi', 'Thời gian đến', 'Thời gian hạ hàng xong'),
                ['unload_date', 'Ngày hạ hàng', null],
                ...self::durations('TG đóng/ trả hàng', 'TG xe đóng hàng', 'TG xe di chuyển', 'TG xe hạ hàng'),
                ...self::waits('TG lưu xe', 'TG chờ đóng hàng', 'TG chờ hạ hàng', 'Tổng TG chờ'),
                ...self::prices('Đơn giá trước điều chỉnh cước (VND)', 'Đơn giá trước điều chỉnh cước', 'Đơn giá (VND)', 'Đơn giá'),
                ['fee:loading_unloading', 'Phí bốc xếp', null],
                ['waiting_fee', 'Chi phí giờ chờ', null],
                ['fee:extra_drop', 'Chi phí rớt điểm', null],
                ...self::totals('Tổng', 'Trị giá (VND)'),
                ['note', 'Ghi chú', null],
            ]),

            'SDS HCM' => self::make('SDS HCM', 'SDS HCM', 'BẢNG KÊ ĐỐI CHIẾU VẬN CHUYỂN HÀNG SDS HCM', [
                ...self::head('Biển kiểm soát', 'Điểm lấy hàng', 'Điểm trả hàng'),
                ['pallet', 'Pallet', null],
                ['tonnage', 'Tải trọng tính cước', null],
                ...self::times('TG chi tiết', 'Thời gian đóng hàng', 'Thời gian đi', 'Thời gian đến', 'Thời gian hạ hàng xong'),
                ...self::durations('TG đóng/ trả hàng', 'TG xe đóng hàng', 'TG xe di chuyển', 'TG xe hạ hàng'),
                ...self::waits('TG lưu xe', 'TG lưu tại điểm đóng', 'TG lưu tại điểm trả', 'Tổng'),
                ...self::prices('Đơn giá trước điều chỉnh cước (VND)', 'Đơn giá trước điều chỉnh cước', 'Đơn giá (VND)', 'Đơn giá'),
                ['fee:holiday', 'Chi phí ngày lễ', null],
                ['waiting_fee', 'Chi phí giờ chờ', null],
                ['fee:shift_storage,overnight_storage', 'Chi phí lưu ca', null],
                ['fee:hauling', 'Chi phí kéo hàng', null],
                ['fee:forklift', 'Chi phí xe nâng', null],
                ...self::totals('Tổng', 'Trị giá (VND)'),
                ['note', 'Ghi chú', null],
            ]),

            'SPA' => self::make('SPA', 'SPA', 'BẢNG KÊ VẬN CHUYỂN HÀNG SPA', [
                ...self::head('Biển kiểm soát', 'Điểm lấy hàng', 'Điểm trả hàng', dateLabel: 'Ngày xuất hàng'),
                ['pallet', 'Số lượng Pallet', null],
                ['tonnage', 'Tải trọng tính cước (tấn)', null],
                ...self::times('TG chi tiết', 'Thời gian đóng hàng', 'Thời gian xe chạy', 'Thời gian đến', 'Thời gian hạ hàng'),
                ['unload_date', 'Ngày hạ hàng', null],
                ...self::durations('TG đóng/ trả hàng', 'TG xe đóng hang', 'TG xe di chuyển', 'TG xe hạ hàng'),
                ...self::waits('TG lưu xe', 'TG chờ đóng hàng', 'TG chờ hạ hàng', 'Tổng'),
                ...self::prices('Đơn giá trước điều chỉnh cước (VND)', 'Đơn giá trước điều chỉnh cước', 'Đơn giá (VND)', 'Đơn giá'),
                ['waiting_fee', 'Phí giờ chờ', null],
                ['fee:shift_storage,overnight_storage', 'Chi phí lưu ca', null],
                ['fee:inspection', 'Chi phí kiểm hoá', null],
                ['fee:warehouse_ticket', 'Chi phí vé phát sinh', null],
                ...self::totals('Tổng', 'Trị giá (VND)'),
                ['note', 'Ghi chú', null],
            ]),

            'UNILO' => self::make('UNILO', 'UNILO', 'BẢNG KÊ ĐỐI CHIẾU VẬN CHUYỂN HÀNG UNILO-ASGT', [
                ...self::head('Biển kiểm soát', 'Điểm lấy hàng', 'Điểm trả hàng', dateLabel: 'Ngày xuất hàng'),
                ['tonnage', 'Tải trọng tính cước (tấn)', null],
                ...self::times('TG chi tiết', 'Thời gian đóng hàng', 'Thời gian xe chạy', 'Thời gian đến', 'Thời gian hạ hàng'),
                ['unload_date', 'Ngày hạ hàng', null],
                ...self::durations('TG đóng/ trả hàng', 'TG đóng hàng', 'TG xe di chuyển', 'TG hạ hàng hàng'),
                ...self::waits('TG lưu xe', 'TG chờ đóng hàng', 'TG chờ đóng hạ hàng', 'Tổng TG chờ'),
                ['base_price', 'Đơn giá trước điều chỉnh cước', null],
                ['adjusted_price', 'Đơn giá', null],
                ['fee:warehouse_ticket', 'Vé vào kho', null],
                ['fee:loading_unloading', 'Chi phí bốc xếp', null],
                ['waiting_fee', 'Chi phí giờ chờ', null],
                ...self::totals('Tổng', 'Trị giá (VND)'),
                ['note', 'Ghi chú', null],
            ]),

            'VIL' => self::make('VIL', 'VIL', 'BẢNG KÊ ĐỐI CHIẾU VẬN CHUYỂN HÀNG VIL', [
                ...self::head('Biển kiểm soát', 'Điểm lấy hàng', 'Điểm trả hàng', dateLabel: 'Ngày xuất hàng'),
                ['tonnage', 'Tải trọng tính cước (tấn)', null],
                ...self::times('TG chi tiết', 'Thời gian đóng hàng', 'Thời gian xe chạy', 'Thời gian đến', 'Thời gian hạ hàng'),
                ['unload_date', 'Ngày hạ hàng', null],
                ...self::durations('TG đóng/ trả hàng', 'TG đóng hàng', 'TG xe di chuyển', 'TG hạ hàng hàng'),
                ...self::waits('TG lưu xe', 'TG chờ đóng hàng', 'TG chờ đóng hạ hàng', 'Tổng TG chờ'),
                ...self::prices('Đơn giá trước điều chỉnh cước (VND)', 'Đơn giá trước điều chỉnh cước', 'Đơn giá (VND)', 'Đơn giá'),
                ['waiting_fee', 'Chi phí giờ chờ', null],
                ['fee_rest', 'Chi Phí khác', null],
                ...self::totals('Tổng', 'Trị giá (VND)'),
                ['note', 'Ghi chú', null],
            ]),
            'ALSE' => self::make('ALSE', 'ALSE', 'BẢNG KÊ ĐỐI CHIẾU VẬN CHUYỂN ASGT-ALSE', [
                ...self::head('Biển kiểm soát', 'Điểm đóng hàng', 'Điểm trả hàng', dateLabel: 'Ngày xuất hàng'),
                ['tonnage', 'Tải trọng tính cước (Tấn)', null],
                ['time_required', 'TG chi tiết', 'Thời gian yêu cầu'],
                ['time_loading', '', 'Thời gian đóng hàng'],
                ['time_departed', '', 'Thời gian xe chạy'],
                ['time_arrived', '', 'Thời gian đến'],
                ['time_unloaded', '', 'Thời gian hạ hàng'],
                ['unload_date', 'Ngày hạ hàng', null],
                ...self::durations('TG đóng/ trả hàng', 'Số giờ xe đóng hàng', 'TG xe di chuyển', 'Số giờ xe hạ hàng'),
                ...self::waits('TG lưu xe', 'TG chờ tại điểm đóng hàng', 'TG chờ tại điểm trả', 'Tổng thời gian chờ'),
                ...self::prices('Đơn giá trước điều chỉnh cước (VND)', 'Đơn giá trước điều chỉnh cước', 'Đơn giá (VND)', 'Đơn giá'),
                ['waiting_fee', 'Phí giờ chờ', null],
                ['fee_rest', 'Phí khác', null],
                ...self::totals('Tổng', 'Trị giá (VND)'),
                ['note', 'Ghi chú', null],
            ]),

            'CLC_KY_02' => self::make('CLC', 'CLC – SDV NBA (kỳ)', 'BẢNG KÊ ĐỐI CHIẾU VẬN CHUYỂN HÀNG CLC - SDV NBA KỲ 02', [
                ...self::head('Biển kiểm soát', 'Điểm lấy hàng', 'Điểm trả hàng', dateLabel: 'Ngày xuất hàng'),
                ['tonnage', 'Tải trọng tính cước (tấn)', null],
                ...self::times('Thời gian chi tiết', 'Thời gian đóng hàng', 'Thời gian xe chạy', 'Thời gian đến', 'Thời gian hạ hàng'),
                ['unload_date', 'Ngày hạ hàng', null],
                ...self::durations('TG đóng/ trả hàng', 'TG xe đóng hàng', 'TG xe di chuyển', 'TG xe hạ hàng'),
                ...self::waits('TG lưu xe', 'Giờ chờ tại điểm đóng hàng SDV', 'Giờ chờ tại điểm hạ hàng NBA', 'Tổng TG chờ'),
                ...self::prices('Đơn giá trước điều chỉnh cước (VND)', 'Đơn giá trước điều chỉnh cước', 'Đơn giá (VND)', 'Đơn giá'),
                ['waiting_fee', 'Chi phí giờ chờ', null],
                ['fee:shift_storage,overnight_storage', 'Chi phí lưu ca', null],
                ['fee:warehouse_ticket', 'Chi phí vé vào kho', null],
                ['fee:holiday', 'Chi phí ngày lễ tết', null],
                ['fee:extra_drop', 'Chi phí rớt điểm', null],
                ...self::totals('Tổng', 'Trị giá (VND)'),
                ['note', 'Ghi chú', null],
            ]),

            'SAFO_XUAT' => self::make('ASGS (SAFO)', 'SAFO – có rớt điểm (xuất)', 'BẢNG KÊ ĐỐI CHIẾU VẬN CHUYỂN HÀNG SAFO - XUẤT', [
                ['stt', 'STT', null],
                ['date', 'Ngày xuất hàng', null],
                ['plate', 'Biển kiểm soát', null],
                ['pickup', 'Lộ trình', 'Địa chỉ lấy hàng'],
                ['pickup_name', '', 'Công ty lấy hàng'],
                ['drop', '', 'Điểm trả hàng'],
                ['drop_name', '', 'Địa chỉ trả hàng'],
                ['tonnage', 'Tải trọng tính cước (tấn)', null],
                ['time_required', 'Thời gian yêu cầu', null],
                ...self::times('Thời gian chi tiết', 'Thời gian đóng hàng', 'Thời gian xe chạy', 'Thời gian đến', 'Thời gian hạ hàng'),
                ['unload_date', 'Ngày hạ hàng', null],
                ...self::durations('TG đóng/ trả hàng', 'TG xe đóng hàng', 'TG xe di chuyển', 'TG xe hạ hàng'),
                ...self::waits('TG lưu xe', 'TG chờ tại điểm đóng', 'TG chờ tại điểm trả', 'Tổng'),
                ['base_price', 'Đơn giá trước điều chỉnh (VND)', null],
                ['adjusted_price', 'Đơn giá (VND)', null],
                ['waiting_fee', 'Chi phí giờ chờ', null],
                ['fee:shift_storage,overnight_storage', 'Chi phí lưu ca', null],
                ['fee:extra_drop', 'Chi phí rớt điểm', null],
                ['fee_rest', 'Chi phí khác', null],
                ['fee:warehouse_ticket', 'Chi phí vé ra vào kho', null],
                ['fee:dunnage', 'Chi phí thuê chèn', null],
                ...self::totals('Tổng', 'Trị giá (VND)'),
                ['note', 'Ghi chú', null],
            ], layout: 'per_drop'),

            'SAFO_NHAP' => self::make('ASGS (SAFO)', 'SAFO – trả 1 điểm (nhập)', 'BẢNG KÊ ĐỐI CHIẾU VẬN CHUYỂN SAFO - FUKANG NHẬP', [
                ...self::head('Biển kiểm soát', 'Điểm lấy hàng', 'Điểm trả hàng', dateLabel: 'Ngày xuất hàng'),
                ['pallet', 'Số lượng Pallet', null],
                ['tonnage', 'Tải trọng tính cước', null],
                ['time_required', 'Thời gian yêu cầu', null],
                ...self::times('Thời gian chi tiết', 'Thời gian đóng hàng', 'Thời gian xe chạy', 'Thời gian đến', 'Thời gian hạ hàng'),
                ['unload_date', 'Ngày hạ hàng', null],
                ...self::durations('TG đóng/ trả hàng', 'TG xe đóng hàng', 'TG xe di chuyển', 'TG xe hạ hàng'),
                ...self::waits('TG lưu xe', 'TG chờ tại điểm đóng', 'TG chờ tại điểm trả', 'Tổng'),
                ...self::prices('Đơn giá trước điều chỉnh cước (VND)', 'Đơn giá trước điều chỉnh cước', 'Đơn giá (VND)', 'Đơn giá'),
                ['waiting_fee', 'Chi phí giờ chờ', null],
                ['fee:shift_storage,overnight_storage', 'Chi phí lưu ca', null],
                ['fee:extra_drop', 'Chi phí rớt điểm', null],
                ['fee:warehouse_ticket', 'Chi phí vé ra vào kho', null],
                ['fee:dunnage', 'Chi phí thuê chèn', null],
                ...self::totals('Tổng', 'Trị giá (VND)'),
                ['note', 'Ghi chú', null],
            ]),

            'VFC_THUONG' => self::make('ASGS (VFC)', 'VFC – Hàng thường (Sơn)', 'BẢNG KÊ VẬN CHUYỂN HÀNG SƠN VINAFCO', [
                ['stt', 'STT', null],
                ['date', 'Ngày xuất hàng', null],
                ['delivery_note_numbers', 'Số phiếu giao hàng', null],
                ['plate', 'Biển kiểm soát', null],
                ['pickup', 'Lộ trình', 'Điểm lấy hàng'],
                ['drop_name', '', 'Điểm trả hàng'],
                ['drop', '', 'Địa chỉ trả hàng'],
                ['tonnage', 'Loại xe (tấn)', null],
                ['chargeable_weight_kg', 'Trọng lượng  tính tiền', null],
                ['actual_weight_kg', 'Trọng lượng hàng  (kg)', null],
                ...self::times('TG chi tiết', 'Thời gian đóng hàng', 'Thời gian xe chạy', 'Thời gian đến', 'Thời gian hạ hàng'),
                ['unload_date', 'Ngày hạ hàng', null],
                ...self::durations('TG đóng/ trả hàng', 'TG đóng hàng', 'TG di chuyến', 'TG hạ hàng'),
                ...self::waits('TG lưu xe', 'TG chờ đóng hàng', 'TG chờ hạ hàng', 'Tổng TG chờ'),
                ...self::prices('Đơn giá trước điều chỉnh cước (VND)', 'Đơn giá trước điều chỉnh cước', 'Đơn giá (VND)', 'Đơn giá'),
                ['waiting_fee', 'Chi phí giờ chờ', null],
                ['fee:extra_drop', 'Rớt điểm', null],
                ['fee_rest', 'Chi phí khác', null],
                ...self::totals('Tổng', 'Trị giá (VND)'),
                ['note', 'Ghi chú', null],
            ]),

            'VFC_LANH' => self::make('ASGS (VFC)', 'VFC – Hàng lạnh', 'BẢNG KÊ VẬN CHUYỂN HÀNG LẠNH - VINAFCO', [
                ['stt', 'STT', null],
                ['date', 'Ngày xuất hàng', null],
                ['delivery_note_numbers', 'Số phiếu giao hàng', null],
                ['plate', 'Biển kiểm soát', null],
                ['pickup', 'Địa điểm đóng hàng', null],
                ['drop_name', 'Địa điểm giao hàng', 'Đơn vị nhận'],
                ['drop', '', 'Địa chỉ'],
                ['drop_old_address', 'Địa chỉ cũ', null],
                ['tonnage', 'Loại xe (tấn)', null],
                ['chargeable_weight_kg', 'Trọng lượng  tính tiền', null],
                ['actual_weight_kg', 'Trọng lượng hàng  (kg)', null],
                ['pallet', 'Pallet', null],
                ...self::times('Thời gian chi tiết', 'Thời gian đóng hàng', 'Thời gian xe chạy', 'Thời gian đến', 'Thời gian hạ hàng'),
                ['duration_loading', 'TG đóng hàng', null],
                ['duration_unloading', 'TG hạ hàng', null],
                ['wait_loading', 'Thời gian xe chờ', 'TG chờ đóng hàng'],
                ['wait_unloading', '', 'TG chờ hạ hàng'],
                ['base_price', 'Đơn giá trước ĐC', null],
                ['adjusted_price', 'Đơn giá', null],
                ['waiting_fee', 'Chi phí giờ chờ', null],
                ['fee_rest', 'Chi phí khác', null],
                ['subtotal', 'Tổng', null],
                ['vat', 'VAT ({vat})', null],
                ['total', 'Thanh toán', null],
                ['note', 'Ghi chú', null],
            ]),
        ];
    }

    /**
     * Các mẫu dùng được cho một mã khách hàng.
     *
     * @return array<string, string> [khoá mẫu => tên hiển thị]
     */
    public static function optionsForCustomerCode(?string $customerCode): array
    {
        return collect(self::all())
            ->filter(fn (array $template): bool => $template['customer_code'] === $customerCode)
            ->map(fn (array $template): string => $template['label'])
            ->all();
    }

    /**
     * @param  list<array{0: string, 1: string, 2: ?string}>  $columns
     * @return array{customer_code: string, label: string, title: string, customer_label: string, columns: list<array{0: string, 1: string, 2: ?string}>, layout: string}
     */
    private static function make(string $customerCode, string $label, string $title, array $columns, string $customerLabel = 'Tên khách hàng', string $layout = 'single'): array
    {
        return [
            'customer_code' => $customerCode,
            'label' => $label,
            'title' => $title,
            'customer_label' => $customerLabel,
            'columns' => $columns,
            // single: 1 đơn 1 dòng; per_drop: mỗi điểm trả 1 dòng (cùng STT).
            'layout' => $layout,
        ];
    }

    /**
     * @param  list<array{0: string, 1: string, 2: ?string}>  $feeColumns
     */
    private static function asgl(string $customerCode, string $label, string $title, array $feeColumns): array
    {
        return self::make($customerCode, $label, $title, [
            ...self::head('Biển kiểm soát', 'Điểm lấy hàng', 'Điểm trả hàng'),
            ['tonnage', 'Tải trọng tính cước (Tấn)', null],
            ...self::times('TG chi tiết', 'Thời gian đóng hàng', 'Thời gian đi', 'Thời gian đến', 'Thời gian hạ hàng xong'),
            ['unload_date', 'Ngày hạ hàng', null],
            ...self::durations('TG đóng/ trả hàng', 'TG xe đóng hàng', 'TG xe di chuyển', 'TG xe hạ hàng'),
            ...self::waits('TG lưu xe', 'TG lưu tại điểm đóng', 'TG lưu tại điểm trả', 'Tổng'),
            ...self::prices('Đơn giá trước điều chỉnh cước (VND)', 'Đơn giá trước điều chỉnh cước', 'Đơn giá (VND)', 'Đơn giá'),
            ['waiting_fee', 'Chi phí giờ chờ', null],
            ...$feeColumns,
            ...self::totals('Tổng', 'Trị giá (VND)'),
            ['note', 'Ghi chú', null],
        ]);
    }

    /**
     * @param  list<array{0: string, 1: string, 2: ?string}>  $feeColumns
     * @param  list<array{0: string, 1: string, 2: ?string}>  $extraTail
     */
    private static function clc(string $label, array $feeColumns, array $extraTail): array
    {
        return self::make('CLC', $label, 'BẢNG KÊ ĐỐI CHIẾU VẬN CHUYỂN ASGT-CLC', [
            ...self::head('Biển kiểm soát', 'Điểm lấy hàng', 'Điểm trả hàng', dateLabel: 'Ngày xuất hàng'),
            ['tonnage', 'Tải trọng tính cước (tấn)', null],
            ...self::times('Thời gian chi tiết', 'Thời gian đóng hàng', 'Thời gian xe chạy', 'Thời gian đến', 'Thời gian hạ hàng'),
            ['unload_date', 'Ngày hạ hàng', null],
            ...self::durations('TG đóng/ trả hàng', 'TG xe đóng hàng', 'TG xe di chuyển', 'TG xe hạ hàng'),
            ...self::waits('TG lưu xe', 'TG chờ tại điểm đóng', 'TG chờ tại điểm hạ', 'Tổng'),
            ...self::prices('Đơn giá trước điều chỉnh cước (VND)', 'Đơn giá trước điều chỉnh cước', 'Đơn giá (VND)', 'Đơn giá'),
            ['waiting_fee', 'Chi phí giờ chờ', null],
            ...$feeColumns,
            ...self::totals('Tổng', 'Thanh toán'),
            ['note', 'Ghi chú', null],
            ...$extraTail,
        ]);
    }

    /**
     * STT, ngày, biển số, lộ trình (điểm lấy/điểm trả).
     *
     * @return list<array{0: string, 1: string, 2: ?string}>
     */
    private static function head(string $plateLabel, string $pickupLabel, string $dropLabel, string $dateLabel = 'Ngày'): array
    {
        return [
            ['stt', 'STT', null],
            ['date', $dateLabel, null],
            ['plate', $plateLabel, null],
            ['pickup', 'Lộ trình', $pickupLabel],
            ['drop', '', $dropLabel],
        ];
    }

    /**
     * @return list<array{0: string, 1: string, 2: ?string}>
     */
    private static function times(string $group, string $loading, string $departed, string $arrived, string $unloaded): array
    {
        return [
            ['time_loading', $group, $loading],
            ['time_departed', '', $departed],
            ['time_arrived', '', $arrived],
            ['time_unloaded', '', $unloaded],
        ];
    }

    /**
     * @return list<array{0: string, 1: string, 2: ?string}>
     */
    private static function durations(string $group, string $loading, string $travel, string $unloading): array
    {
        return [
            ['duration_loading', $group, $loading],
            ['duration_travel', '', $travel],
            ['duration_unloading', '', $unloading],
        ];
    }

    /**
     * @return list<array{0: string, 1: string, 2: ?string}>
     */
    private static function waits(string $group, string $loading, string $unloading, string $total): array
    {
        return [
            ['wait_loading', $group, $loading],
            ['wait_unloading', '', $unloading],
            ['wait_total', '', $total],
        ];
    }

    /**
     * @return list<array{0: string, 1: string, 2: ?string}>
     */
    private static function prices(string $baseGroup, string $baseSub, string $adjustedGroup, string $adjustedSub): array
    {
        return [
            ['base_price', $baseGroup, $baseSub],
            ['adjusted_price', $adjustedGroup, $adjustedSub],
        ];
    }

    /**
     * @return list<array{0: string, 1: string, 2: ?string}>
     */
    private static function totals(string $subtotalLabel, string $totalLabel): array
    {
        return [
            ['subtotal', $subtotalLabel, null],
            ['vat', 'VAT {vat} (VND)', null],
            ['total', $totalLabel, null],
        ];
    }
}
