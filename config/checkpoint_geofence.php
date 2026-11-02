<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Bán kính kiểm tra (mét)
    |--------------------------------------------------------------------------
    |
    | Dùng chung cho cả hai lớp: GPS điện thoại ↔ xe (EUP) và xe ↔ location.
    |
    */

    'radius_m' => (int) env('CHECKPOINT_GEOFENCE_RADIUS_M', 500),

    /*
    |--------------------------------------------------------------------------
    | Bù tốc độ khi xe đang di chuyển (Lớp A)
    |--------------------------------------------------------------------------
    |
    | Khi tốc độ xe trên EUP vượt ngưỡng moving_speed_threshold_kmh, bán kính
    | Lớp A được cộng thêm quãng đường xe đi được trong thời gian trễ của bản
    | tin EUP, nhưng không vượt quá max_vehicle_radius_m.
    |
    */

    'moving_speed_threshold_kmh' => (float) env('CHECKPOINT_GEOFENCE_MOVING_SPEED_KMH', 15),

    'max_vehicle_radius_m' => (int) env('CHECKPOINT_GEOFENCE_MAX_VEHICLE_RADIUS_M', 1500),

    /*
    |--------------------------------------------------------------------------
    | Độ mới của vị trí EUP (giây)
    |--------------------------------------------------------------------------
    |
    | Vị trí EUP cũ hơn ngưỡng này coi như không có, trừ khi xe đang dừng
    | (vị trí cũ vẫn đúng vì xe không di chuyển).
    |
    */

    'eup_fresh_seconds' => (int) env('CHECKPOINT_GEOFENCE_EUP_FRESH', 300),

    /*
    |--------------------------------------------------------------------------
    | Dùng lộ trình EUP (giây)
    |--------------------------------------------------------------------------
    |
    | Lớp B xét mọi điểm lộ trình trong khoảng trước lúc gửi: mốc đến (đến lấy
    | hàng, đến điểm giao) 15 phút, mốc rời (rời lấy hàng, hoàn thành) 30 phút.
    | Lớp A dùng điểm lộ trình mới nhất trong 2 phút trước lúc gửi.
    | Không có điểm nào trong khoảng thì dùng vị trí hiện tại như cũ.
    |
    */

    'track_window_arrive_seconds' => (int) env('CHECKPOINT_GEOFENCE_TRACK_ARRIVE', 900),

    'track_window_leave_seconds' => (int) env('CHECKPOINT_GEOFENCE_TRACK_LEAVE', 1800),

    'track_match_seconds' => (int) env('CHECKPOINT_GEOFENCE_TRACK_MATCH', 120),

    /*
    |--------------------------------------------------------------------------
    | Bật chặn
    |--------------------------------------------------------------------------
    |
    | Tắt: chỉ ghi nhận kết quả vào checkpoint. Bật: từ chối mốc sai vị trí.
    |
    */

    'enforce_vehicle' => (bool) env('CHECKPOINT_GEOFENCE_ENFORCE_VEHICLE', false),

    'enforce_location' => (bool) env('CHECKPOINT_GEOFENCE_ENFORCE_LOCATION', false),

];
