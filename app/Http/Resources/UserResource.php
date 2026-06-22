<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property User $resource
 */
class UserResource extends JsonResource
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
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            /** @var string|null CCCD/CMND number */
            'cccd' => $this->cccd,
            /** @var string|null Driver license number */
            'license_number' => $this->license_number,
            /** @var string|null Driver license class (e.g. B2, C, D) */
            'license_class' => $this->license_class,
            /** @var string|null License expiry date */
            'license_expiry_date' => $this->license_expiry_date,
            /** @var string[] */
            'roles' => $this->whenLoaded('roles', fn () => $this->roles->pluck('name')),
            'current_vehicle' => $this->vehiclesAsDriver->first() ? [
                'id' => $this->vehiclesAsDriver->first()->id,
                'plate_number' => $this->vehiclesAsDriver->first()->plate_number,
                'vehicle_type' => $this->vehiclesAsDriver->first()->vehicle_type?->value,
                'owner' => $this->vehiclesAsDriver->first()->owner,
                'status' => $this->vehiclesAsDriver->first()->status?->value,
                'current_km' => $this->vehiclesAsDriver->first()->current_mileage,
            ] : null,
        ];
    }
}
