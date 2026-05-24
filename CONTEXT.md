TMS-ASGT — Context tổng quan

Dự án này là một hệ thống quản lý vận tải (Transport Management System) dành cho điều hành, quản lý lái xe, đơn hàng và tuyến đường. Ứng dụng quản lý ca, checkpoint, lộ trình, phương tiện, bảo dưỡng, và phân loại hàng hoá (cargo types).

Thuật ngữ quan trọng
- Shipment / Delivery: đơn hàng hoặc lô hàng cần vận chuyển.
- Driver: lái xe chịu trách nhiệm vận chuyển.
- Checkpoint: điểm kiểm tra/ghi nhận trạng thái trên tuyến.
- Route / Trip: tuyến/đoạn hành trình của một shipment.
- Maintenance: công việc bảo dưỡng phương tiện.

Quy ước đọc ghi
- Đặt ADR trong `docs/adr/` (ví dụ: `docs/adr/0001-use-events.md`).
- Các skills sẽ đọc `CONTEXT.md` để nắm domain language và `docs/adr/` để hiểu các quyết định kiến trúc.
