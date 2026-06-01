<?php

namespace App\Http\Requests;

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

                if ((float) $this->input('start_km') < (float) $vehicle->current_mileage) {
                    $message = 'Số km bắt đầu ca phải lớn hơn hoặc bằng số km hiện tại của xe ('.number_format((float) $vehicle->current_mileage, 1).' km)';
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
