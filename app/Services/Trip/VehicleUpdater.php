<?php

namespace App\Services\Trip;

use App\Models\Trip;

class VehicleUpdater
{
    /**
     * Cập nhật km của vehicle từ payload checkpoint, và GPS nếu xe không có thiết bị EUP.
     *
     * @param  array<string, mixed>  $payload
     */
    public function updateFromPayload(Trip $trip, array $payload): void
    {
        $vehicle = $trip->vehicle;
        if ($vehicle === null) {
            return;
        }

        $dirty = false;

        if (isset($payload['km_reading'])) {
            $vehicle->current_mileage = $payload['km_reading'];
            $dirty = true;
        }

        // Xe có thiết bị EUP: vị trí xe do EUP quản lý, không ghi đè bằng GPS điện thoại
        // (kiểm tra vị trí mốc dựa vào vị trí EUP này). Chỉ xe không có EUP mới lấy GPS điện thoại.
        if ($vehicle->last_gps_update === null) {
            if (isset($payload['gps_lat'])) {
                $vehicle->gps_lat = $payload['gps_lat'];
                $dirty = true;
            }

            if (isset($payload['gps_lng'])) {
                $vehicle->gps_lng = $payload['gps_lng'];
                $dirty = true;
            }
        }

        if ($dirty) {
            $vehicle->save();
        }
    }
}
