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
            'km_reading' => 'nullable|numeric|min:0',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('km_reading') && $this->input('km_reading') !== null) {
            $this->merge([
                'km_reading' => $this->normalizeDecimal($this->input('km_reading')),
            ]);
        }
    }

    public function after(): array
    {
        return [];
    }
}
