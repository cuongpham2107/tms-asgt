<?php

namespace App\Http\Requests;

use App\Models\DriverShift;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

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
            function (Validator $validator) {
                if ($this->input('end_km') === null) {
                    return;
                }

                $shift = DriverShift::query()
                    ->where('driver_id', $this->user()->id)
                    ->whereNull('end_time')
                    ->first();

                if ($shift === null || $shift->start_km === null) {
                    return;
                }

                if ((float) $this->input('end_km') <= (float) $shift->start_km) {
                    $validator->errors()->add(
                        'end_km',
                        'Số km kết thúc ca phải lớn hơn số km bắt đầu ca ('.number_format((float) $shift->start_km, 1).' km)',
                    );
                }
            },
        ];
    }
}
