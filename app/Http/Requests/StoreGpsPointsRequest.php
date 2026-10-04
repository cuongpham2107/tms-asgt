<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreGpsPointsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'device_id' => ['required', 'string', 'max:64'],
            'shift_id' => ['nullable', 'integer', 'exists:driver_shifts,id'],
            'vehicle_id' => ['nullable', 'integer', 'exists:vehicles,id'],
            'points' => ['required', 'array', 'min:1', 'max:500'],
            'points.*.seq' => ['required', 'integer', 'min:1'],
            'points.*.recorded_at' => ['required', 'date'],
            'points.*.lat' => ['required', 'numeric', 'between:-90,90'],
            'points.*.lng' => ['required', 'numeric', 'between:-180,180'],
            'points.*.speed' => ['nullable', 'numeric', 'min:0'],
            'points.*.heading' => ['nullable', 'numeric'],
            'points.*.accuracy' => ['nullable', 'numeric', 'min:0'],
            'points.*.mocked' => ['nullable', 'boolean'],
        ];
    }
}
