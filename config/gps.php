<?php

return [
    /*
    | Ngưỡng lọc điểm GPS khi tính km (xem GpsDistanceService).
    */

    // Bỏ điểm có sai số lớn hơn (mét).
    'max_accuracy_m' => (float) env('GPS_MAX_ACCURACY_M', 50),

    // Bỏ điểm mà vận tốc suy ra từ điểm trước vượt quá (km/h): điểm nhảy.
    'max_speed_kmh' => (float) env('GPS_MAX_SPEED_KMH', 150),

    // Xe đứng yên (thiết bị báo tốc độ dưới stationary_speed_kmh): dịch chuyển trong bán kính này (mét) là nhiễu.
    'stationary_radius_m' => (float) env('GPS_STATIONARY_RADIUS_M', 50),
    'stationary_speed_kmh' => (float) env('GPS_STATIONARY_SPEED_KMH', 3),

    // Thiết bị không báo tốc độ: dịch chuyển nhỏ hơn (mét) là nhiễu.
    'min_move_m' => (float) env('GPS_MIN_MOVE_M', 20),

    // Khoảng trống của điện thoại dài hơn (giây) thì lấp bằng điểm EUP; khoảng ≤ ngưỡng này được coi là có phủ.
    'gap_fill_seconds' => (int) env('GPS_GAP_FILL_SECONDS', 120),

    // Khoảng trống dài hơn (giây) giữa 2 điểm thì tính theo đường bộ OSRM thay vì đường thẳng.
    'osrm_gap_seconds' => (int) env('GPS_OSRM_GAP_SECONDS', 300),

    // Khoảng trống lớn hơn (giây) thì không bắc OSRM (coi là ngắt kết nối/nghỉ ca, tránh cộng km ảo).
    'osrm_max_gap_seconds' => (int) env('GPS_OSRM_MAX_GAP_SECONDS', 900),

    // Chờ (phút) sau khi chuyến kết thúc rồi mới chốt km, để điện thoại kịp gửi bù điểm còn trong hàng đợi.
    'settle_after_minutes' => (int) env('GPS_SETTLE_AFTER_MINUTES', 30),

    // Chuyến có độ phủ GPS (%) dưới ngưỡng này thì cần kiểm tra km trước khi xuất hoá đơn.
    'review_coverage_percent' => (float) env('GPS_REVIEW_COVERAGE_PERCENT', 90),

    // Vùng hoạt động hợp lệ: điểm ĐIỆN THOẠI ngoài khung này bị coi là rác (ví dụ vị trí mặc định
    // San Francisco của iOS simulator) và bị loại khi tính km, đồng thời gắn cờ cần kiểm tra.
    // Điểm hộp đen EUP không bị lọc theo vùng. Đặt GPS_BOUNDS_ENABLED=false để tắt.
    'bounds' => env('GPS_BOUNDS_ENABLED', true) ? [
        'min_lat' => (float) env('GPS_BOUNDS_MIN_LAT', 8.0),
        'max_lat' => (float) env('GPS_BOUNDS_MAX_LAT', 24.0),
        'min_lng' => (float) env('GPS_BOUNDS_MIN_LNG', 102.0),
        'max_lng' => (float) env('GPS_BOUNDS_MAX_LNG', 110.0),
    ] : null,
];
