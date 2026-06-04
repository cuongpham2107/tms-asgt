<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SwitchVehicleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'new_vehicle_id' => 'required|exists:vehicles,id',
            'order_id' => 'nullable|exists:orders,id',
            'handover_km' => 'required|numeric',
            'handover_gps_lat' => 'nullable|numeric',
            'handover_gps_lng' => 'nullable|numeric',
        ];
    }
}
