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

    // Chờ (phút) sau khi chuyến kết thúc rồi mới chốt km, để điện thoại kịp gửi bù điểm còn trong hàng đợi.
    'settle_after_minutes' => (int) env('GPS_SETTLE_AFTER_MINUTES', 30),

    // Chuyến có độ phủ GPS (%) dưới ngưỡng này thì cần kiểm tra km trước khi xuất hoá đơn.
    'review_coverage_percent' => (float) env('GPS_REVIEW_COVERAGE_PERCENT', 90),
];
