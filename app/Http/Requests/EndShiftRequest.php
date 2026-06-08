<?php

namespace App\Http\Requests;

use App\Models\DriverShift;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

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

    public function after(): array
    {
        return [
            function (\Illuminate\Validation\Validator $validator) {
                if ($this->input('end_km') === null) {
                    return;
                }

                $shift = DriverShift::query()
                    ->where('driver_id', $this->user()->id)
                    ->whereNull('end_time')
                    ->first();

                if ($shift === null) {
                    return;
                }

                $currentSegment = $shift->shiftVehicles()
                    ->whereNull('end_time')
                    ->latest('start_time')
                    ->first();

                $referenceKm = $currentSegment?->start_km ?? $shift->effective_start_km;

                if ($referenceKm === null) {
                    return;
                }

                if ((float) $this->input('end_km') <= (float) $referenceKm) {
                    $message = 'Số km kết thúc ca phải lớn hơn số km bắt đầu ca ('.number_format((float) $referenceKm, 1).' km)';
                    throw new HttpResponseException(response()->json(['message' => $message], 422));
                }
            },
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'message' => $validator->errors(),
        ], 422));
    }
}
