{{-- Style highlight khi rê chuột vào từng dòng --}}
<style>
    .dispatch-row {
        transition: background-color 0.15s ease, border-color 0.15s ease;
    }
    .dispatch-row:hover td:not(.status-cell):not(.km-over-cell):not(.alert-cell) {
        background-color: rgb(239 246 255 / 0.85);
    }
    .dispatch-row:hover td {
        border-top-color: rgb(147 197 253 / 0.9) !important;
        border-bottom-color: rgb(147 197 253 / 0.9) !important;
    }
    .dark .dispatch-row:hover td:not(.status-cell):not(.km-over-cell):not(.alert-cell) {
        background-color: rgb(30 58 138 / 0.35);
    }
    .dark .dispatch-row:hover td {
        border-top-color: rgb(59 130 246 / 0.6) !important;
        border-bottom-color: rgb(59 130 246 / 0.6) !important;
    }
    .dispatch-row:hover td:first-child {
        box-shadow: inset 4px 0 0 rgb(37 99 235);
    }
    /* Border phân biệt rõ ràng ở cuối mỗi nhóm địa điểm */
    .dispatch-row.dispatch-group-end td,
    .dispatch-row.dispatch-group-end:hover td {
        border-bottom: 2px solid #94a3b8 !important;
    }
    .dark .dispatch-row.dispatch-group-end td,
    .dark .dispatch-row.dispatch-group-end:hover td {
        border-bottom: 2px solid #475569 !important;
    }
    /* Sticky header cho bảng kiểm soát xe */
    .dispatch-table-container {
        max-height: calc(100vh - 9rem);
        overflow: auto;
    }
    .dispatch-table thead {
        position: sticky;
        top: 0;
        z-index: 20;
    }
    .dispatch-table thead tr,
    .dispatch-table thead th {
        background-color: rgb(30 58 95) !important;
    }
    .dark .dispatch-table thead tr,
    .dark .dispatch-table thead th {
        background-color: rgb(2 6 23) !important;
    }
    /* Safari (WebKit) không vẽ border của ô sticky trong bảng border-collapse → kẻ ô header bằng box-shadow */
    .dispatch-table thead th {
        box-shadow: inset -1px -1px 0 rgb(255 255 255 / 0.25);
    }
    /* Chiều cao dòng đồng nhất giữa các bảng/tab: 1 dòng chữ, không xuống dòng */
    .dispatch-table tbody td {
        height: 36px;
        padding-top: 0.375rem;
        padding-bottom: 0.375rem;
        line-height: 1rem;
        white-space: nowrap;
    }
    /* Header cao đúng bằng dòng dữ liệu (36px) cho mọi bảng custom */
    .dispatch-table thead th {
        height: 36px;
        padding-top: 0;
        padding-bottom: 0;
        vertical-align: middle;
    }
</style>
