<?php

namespace App\Http\Requests;

use App\Models\Order;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CheckpointRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'order_id' => 'required|exists:orders,id',
            'shift_id' => 'nullable|exists:driver_shifts,id',
            'delivery_point_id' => 'nullable|exists:order_delivery_points,id',
            'checkpoint_type' => 'required|string',
            'occurred_at' => 'nullable|date',
            'km_reading' => 'nullable|numeric',
            'gps_lat' => 'nullable|numeric',
            'gps_lng' => 'nullable|numeric',
            'voice_note' => 'nullable|string',
            'photos' => 'nullable|image|max:10240',
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($this->input('km_reading') === null) {
                    return;
                }

                $order = Order::find($this->input('order_id'));
                if ($order === null || $order->vehicle_id === null) {
                    return;
                }

                $vehicle = $order->vehicle;
                if ($vehicle !== null && (float) $this->input('km_reading') <= (float) $vehicle->current_mileage) {
                    $validator->errors()->add(
                        'km_reading',
                        'Số km đồng hồ phải lớn hơn số km hiện tại của xe ('.number_format((float) $vehicle->current_mileage, 1).' km)',
                    );
                }
            },
        ];
    }
}
