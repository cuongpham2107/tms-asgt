<?php

namespace App\Http\Requests;

use App\Models\DriverShift;
use App\Models\Vehicle;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StartShiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'vehicle_id' => 'required|exists:vehicles,id',
            'shift_type' => 'required|string',
            'start_time' => 'nullable|date',
            'start_km' => 'nullable|numeric',
            'start_gps_lat' => 'nullable|numeric',
            'start_gps_lng' => 'nullable|numeric',
        ];
    }

    public function after(): array
    {
        return [
            function (\Illuminate\Validation\Validator $validator) {
                if ($this->input('start_km') === null) {
                    return;
                }

                $vehicle = Vehicle::find($this->input('vehicle_id'));
                if ($vehicle === null) {
                    return;
                }

                $lastShiftKm = DriverShift::where('vehicle_id', $this->input('vehicle_id'))
                    ->whereNotNull('end_km')
                    ->orderByDesc('end_time')
                    ->value('end_km');

                $referenceKm = $lastShiftKm ?? $vehicle->current_mileage;

                if ((float) $this->input('start_km') < (float) $referenceKm) {
                    $message = sprintf(
                        'Số km bắt đầu ca phải lớn hơn hoặc bằng km gần nhất của xe (%.1f km)',
                        $referenceKm
                    );
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
