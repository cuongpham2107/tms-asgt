<?php

namespace App\Http\Requests;

use App\Models\Order;
use App\Models\TripCheckpoint;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

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
            'km_reading' => [
                'nullable',
                'numeric',
                Rule::requiredIf(fn () => $this->input('checkpoint_type') === 'left_pickup'),
            ],
            'gps_lat' => 'nullable|numeric',
            'gps_lng' => 'nullable|numeric',
            'voice_note' => 'nullable|string',
            'photos' => 'nullable|image|max:10240',
        ];
    }

    public function after(): array
    {
        return [
            function (\Illuminate\Validation\Validator $validator) {
                if ($this->input('km_reading') === null) {
                    return;
                }

                $order = Order::find($this->input('order_id'));
                if ($order === null || $order->vehicle_id === null) {
                    return;
                }

                $vehicle = $order->vehicle;
                if ($vehicle !== null && (float) $this->input('km_reading') <= (float) $vehicle->current_mileage) {
                    $message = 'Số km đồng hồ phải lớn hơn số km hiện tại của xe ('.number_format((float) $vehicle->current_mileage, 1).' km)';
                    throw new HttpResponseException(response()->json(['message' => $message], 422));
                }
            },

            function (\Illuminate\Validation\Validator $validator) {
                if ($this->input('checkpoint_type') !== 'completed' || $this->input('km_reading') === null) {
                    return;
                }

                $order = Order::find($this->input('order_id'));
                if ($order === null) {
                    return;
                }

                $leftPickupKm = TripCheckpoint::where('order_id', $order->id)
                    ->where('checkpoint_type', 'left_pickup')
                    ->value('km_reading');

                if ($leftPickupKm !== null && (float) $this->input('km_reading') <= (float) $leftPickupKm) {
                    $message = sprintf(
                        'Số km kết thúc phải lớn hơn km lúc rời điểm nhận (%.1f km)',
                        $leftPickupKm
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
