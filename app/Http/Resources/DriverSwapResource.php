<?php

namespace App\Http\Resources;

use App\Models\DriverSwap;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property DriverSwap $resource
 */
class DriverSwapResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'trip_id' => $this->trip_id,
            'from_driver_id' => $this->from_driver_id,
            'to_driver_id' => $this->to_driver_id,
            'from_shift_id' => $this->from_shift_id,
            'handover_km' => $this->handover_km,
            'reason' => $this->reason,
            'note' => $this->note,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
