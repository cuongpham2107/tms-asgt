<?php

namespace App\Http\Requests;

use App\Enums\DriverSwapReason;
use App\Http\Requests\Concerns\NormalizesDecimalInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class DriverSwapRequest extends FormRequest
{
    use NormalizesDecimalInput;

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

    protected function prepareForValidation(): void
    {
        $this->merge([
            'handover_km' => $this->normalizeDecimal($this->input('handover_km')),
            'gps_lat' => $this->normalizeDecimal($this->input('gps_lat')),
            'gps_lng' => $this->normalizeDecimal($this->input('gps_lng')),
        ]);
    }
}
