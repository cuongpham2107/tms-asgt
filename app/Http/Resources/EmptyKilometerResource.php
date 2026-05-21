<?php

namespace App\Http\Resources;

use App\Models\EmptyKilometer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property EmptyKilometer $resource
 */
class EmptyKilometerResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /** ID bản ghi km không hàng. */
            'id' => $this->id,
            /** ID lái xe ghi nhận. */
            'driver_id' => $this->driver_id,
            /** Thông tin lái xe (nếu được load). */
            'driver' => $this->whenLoaded('driver', fn () => [
                'id' => $this->driver->id,
                'name' => $this->driver->name,
            ]),
            /** ID xe đang chạy. */
            'vehicle_id' => $this->vehicle_id,
            /** Thông tin xe (nếu được load). */
            'vehicle' => $this->whenLoaded('vehicle', fn () => [
                'id' => $this->vehicle->id,
                'plate_number' => $this->vehicle->plate_number,
            ]),
            /** ID ca trực liên quan. */
            'shift_id' => $this->shift_id,
            /** Km đồng hồ lúc bắt đầu. */
            'start_km' => $this->start_km,
            /** Km đồng hồ lúc kết thúc. */
            'end_km' => $this->end_km,
            /** Số km không hàng (end_km - start_km). */
            'distance' => $this->distance,
            /** Vĩ độ GPS điểm bắt đầu. */
            'start_gps_lat' => $this->start_gps_lat,
            /** Kinh độ GPS điểm bắt đầu. */
            'start_gps_lng' => $this->start_gps_lng,
            /** Vĩ độ GPS điểm kết thúc. */
            'end_gps_lat' => $this->end_gps_lat,
            /** Kinh độ GPS điểm kết thúc. */
            'end_gps_lng' => $this->end_gps_lng,
            /** Thời điểm bắt đầu (ISO 8601). */
            'started_at' => $this->started_at?->toIso8601String(),
            /** Thời điểm kết thúc (ISO 8601). */
            'ended_at' => $this->ended_at?->toIso8601String(),
            /** Ghi chú. */
            'note' => $this->note,
            /** Thời điểm tạo bản ghi (ISO 8601). */
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
