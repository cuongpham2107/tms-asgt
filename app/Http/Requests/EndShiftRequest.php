<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesDecimalInput;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class EndShiftRequest extends FormRequest
{
    use NormalizesDecimalInput;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'end_time' => 'nullable|date',
            'end_km' => 'nullable',
            'end_gps_lat' => 'nullable|numeric',
            'end_gps_lng' => 'nullable|numeric',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'end_km' => $this->normalizeDecimal($this->input('end_km')),
            'end_gps_lat' => $this->normalizeDecimal($this->input('end_gps_lat')),
            'end_gps_lng' => $this->normalizeDecimal($this->input('end_gps_lng')),
        ]);
    }

    public function after(): array
    {
        return [];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'message' => $validator->errors(),
        ], 422));
    }
}
