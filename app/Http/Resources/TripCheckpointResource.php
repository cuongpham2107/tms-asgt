<?php

namespace App\Http\Resources;

use App\Models\TripCheckpoint;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property TripCheckpoint $resource
 */
class TripCheckpointResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'driver_id' => $this->driver_id,
            'driver_name' => $this->whenLoaded('driver', fn () => $this->driver->name),
            'shift_id' => $this->shift_id,
            'delivery_point_id' => $this->delivery_point_id,
            'checkpoint_type' => $this->checkpoint_type,
            /** @var string ISO 8601 */
            'occurred_at' => $this->occurred_at?->toIso8601String(),
            /** @var float|null GPS latitude */
            'gps_lat' => $this->gps_lat,
            /** @var float|null GPS longitude */
            'gps_lng' => $this->gps_lng,
            'voice_note' => $this->voice_note,
            /** Khoảng cách từ mốc trước tới mốc này (km). */
            'distance_km' => isset($this->distance_km) ? (float) $this->distance_km : null,
            /** Có chở hàng trong chặng này hay không. */
            'is_loaded' => isset($this->is_loaded) ? (bool) $this->is_loaded : null,
            /** Nguồn tính km (phone_gps, osrm, manual). */
            'source' => $this->source ?? null,
            /** Đã được điều phối sửa km thủ công hay chưa. */
            'is_adjusted' => isset($this->is_adjusted) ? (bool) $this->is_adjusted : null,
            /** Ảnh chụp tại checkpoint này (nếu được load). */
            'photos' => $this->whenLoaded('photos', fn () => $this->photos->map(fn ($photo) => [
                'id' => $photo->id,
                'photo_path' => $photo->photo_path,
                'photo_url' => $photo->photo_url,
                'created_at' => $photo->created_at?->toIso8601String(),
            ])),
            /** @var string ISO 8601 */
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
