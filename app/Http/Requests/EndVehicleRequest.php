<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesDecimalInput;
use Illuminate\Foundation\Http\FormRequest;

class EndVehicleRequest extends FormRequest
{
    use NormalizesDecimalInput;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'km_reading' => 'required|numeric|min:0',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'km_reading' => $this->normalizeDecimal($this->input('km_reading')),
        ]);
    }
}
