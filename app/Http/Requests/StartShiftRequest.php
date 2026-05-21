<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StartShiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'vehicle_id' => 'required|exists:vehicles,id',
            'shift_type' => 'required|string',
            'start_time' => 'nullable|date',
            'start_km' => 'nullable|numeric',
            'start_gps_lat' => 'nullable|numeric',
            'start_gps_lng' => 'nullable|numeric',
        ];
    }
}
