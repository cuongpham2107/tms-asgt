<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CheckpointRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'order_id' => 'required|exists:orders,id',
            'shift_id' => 'nullable|exists:driver_shifts,id',
            'delivery_point_id' => 'nullable|exists:order_delivery_points,id',
            'checkpoint_type' => 'required|string',
            'occurred_at' => 'nullable|date',
            'km_reading' => 'nullable|numeric',
            'gps_lat' => 'nullable|numeric',
            'gps_lng' => 'nullable|numeric',
            'voice_note' => 'nullable|string',
            'photos.*' => 'nullable|image|max:10240',
        ];
    }
}
