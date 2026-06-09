<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesDecimalInput;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StartShiftRequest extends FormRequest
{
    use NormalizesDecimalInput;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'shift_type' => 'required|string',
            'start_time' => 'nullable|date',
            'start_gps_lat' => 'nullable|numeric',
            'start_gps_lng' => 'nullable|numeric',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'start_gps_lat' => $this->normalizeDecimal($this->input('start_gps_lat')),
            'start_gps_lng' => $this->normalizeDecimal($this->input('start_gps_lng')),
        ]);
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'message' => $validator->errors(),
        ], 422));
    }
}
