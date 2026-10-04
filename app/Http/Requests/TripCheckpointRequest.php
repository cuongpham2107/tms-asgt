<?php

namespace App\Http\Requests;

use App\Enums\CheckpointType;
use App\Http\Requests\Concerns\NormalizesDecimalInput;
use App\Models\Order;
use App\Models\Trip;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class TripCheckpointRequest extends FormRequest
{
    use NormalizesDecimalInput;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $type = $this->input('checkpoint_type');

        $orderIdRules = ['nullable', Rule::exists('orders', 'id')];
        $deliveryPointIdRules = ['nullable', 'exists:order_delivery_points,id'];

        if (in_array($type, ['arrived_delivery', 'completed'], true)) {
            $trip = $this->route('trip');
            // Chỉ bắt buộc order_id nếu trip có orders (return trip không có)
            if ($trip instanceof Trip && $trip->orders()->exists()) {
                $orderIdRules = ['required', Rule::exists('orders', 'id')];
            }
        }

        return [
            'checkpoint_type' => ['required', 'string', Rule::in(array_map(fn ($case) => $case->value, CheckpointType::cases()))],
            'order_id' => $orderIdRules,
            'delivery_point_id' => $deliveryPointIdRules,
            'new_delivery_location_id' => 'nullable|exists:locations,id',
            'occurred_at' => 'nullable|date',
            'km_reading' => 'nullable',
            'gps_lat' => 'nullable|numeric',
            'gps_lng' => 'nullable|numeric',
            'voice_note' => 'nullable|string',
            'photos' => 'nullable',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'km_reading' => $this->normalizeDecimal($this->input('km_reading')),
            'gps_lat' => $this->normalizeDecimal($this->input('gps_lat')),
            'gps_lng' => $this->normalizeDecimal($this->input('gps_lng')),
        ]);
    }

    public function after(): array
    {
        return [
            function (\Illuminate\Validation\Validator $validator) {
                $type = $this->input('checkpoint_type');

                if (in_array($type, ['arrived_delivery', 'completed'], true)) {
                    $order = Order::find($this->input('order_id'));
                    if ($order === null) {
                        return;
                    }

                    $hasDeliveryPoints = $order->deliveryPoints()->count() > 0;
                    $hasDeliveryPointId = ! empty($this->input('delivery_point_id'));
                    $hasNewLocationId = ! empty($this->input('new_delivery_location_id'));

                    if ($hasDeliveryPoints && ! $hasDeliveryPointId) {
                        $validator->errors()->add('delivery_point_id', 'Vui lòng chọn điểm giao hàng cụ thể để hoàn thành.');
                    }

                    if (! $hasDeliveryPoints && ! $hasDeliveryPointId && ! $hasNewLocationId) {
                        $validator->errors()->add('delivery_point_id', 'Đơn hàng chưa có điểm đến. Vui lòng chọn điểm giao hàng.');
                    }
                }
            },
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        $firstError = $validator->errors()->first();
        throw new HttpResponseException(response()->json([
            'message' => $firstError,
            'errors' => $validator->errors(),
        ], 422));
    }
}
