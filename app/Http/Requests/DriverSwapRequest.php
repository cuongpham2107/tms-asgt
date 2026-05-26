<?php

namespace App\Http\Requests;

use App\Enums\DriverSwapReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class DriverSwapRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'order_id' => 'required|exists:orders,id',
            'reason' => ['required', 'string', new Enum(DriverSwapReason::class)],
            'handover_km' => 'nullable|numeric',
            'note' => 'nullable|string|max:500',
            'from_shift_id' => 'nullable|exists:driver_shifts,id',
            'gps_lat' => 'nullable|numeric',
            'gps_lng' => 'nullable|numeric',
            'photos' => 'nullable|image|max:10240',
        ];
    }
}
