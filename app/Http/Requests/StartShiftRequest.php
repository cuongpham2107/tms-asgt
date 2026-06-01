<?php

namespace App\Http\Requests;

use App\Models\Vehicle;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

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
            function (Validator $validator) {
                if ($this->input('start_km') === null) {
                    return;
                }

                $vehicle = Vehicle::find($this->input('vehicle_id'));
                if ($vehicle === null) {
                    return;
                }

                if ((float) $this->input('start_km') < (float) $vehicle->current_mileage) {
                    $validator->errors()->add(
                        'start_km',
                        'Số km bắt đầu ca phải lớn hơn hoặc bằng số km hiện tại của xe ('.number_format((float) $vehicle->current_mileage, 1).' km)',
                    );
                }
            },
        ];
    }
}
