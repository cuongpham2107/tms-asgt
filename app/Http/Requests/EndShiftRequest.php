<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EndShiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'end_time' => 'nullable|date',
            'end_km' => 'nullable|numeric',
            'end_gps_lat' => 'nullable|numeric',
            'end_gps_lng' => 'nullable|numeric',
        ];
    }
}
