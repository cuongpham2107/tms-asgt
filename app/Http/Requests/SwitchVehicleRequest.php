<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesDecimalInput;
use Illuminate\Foundation\Http\FormRequest;

class SwitchVehicleRequest extends FormRequest
{
    use NormalizesDecimalInput;

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

    protected function prepareForValidation(): void
    {
        $this->merge([
            'handover_km' => $this->normalizeDecimal($this->input('handover_km')),
            'handover_gps_lat' => $this->normalizeDecimal($this->input('handover_gps_lat')),
            'handover_gps_lng' => $this->normalizeDecimal($this->input('handover_gps_lng')),
        ]);
    }
}
