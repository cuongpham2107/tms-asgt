<?php

namespace App\Http\Requests;

use App\Enums\CheckpointType;
use App\Http\Requests\Concerns\NormalizesDecimalInput;
use App\Models\Order;
use App\Models\Trip;
use App\Models\TripCheckpoint;
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

        $orderIdRules = ['nullable', Rule::exists('orders', 'id')->whereNull('deleted_at')];
        $deliveryPointIdRules = ['nullable', 'exists:order_delivery_points,id'];

        if (in_array($type, ['arrived_delivery', 'completed'], true)) {
            $orderIdRules = ['required', Rule::exists('orders', 'id')->whereNull('deleted_at')];
        }

        return [
            'checkpoint_type' => ['required', 'string', Rule::in(array_map(fn ($case) => $case->value, CheckpointType::cases()))],
            'order_id' => $orderIdRules,
            'delivery_point_id' => $deliveryPointIdRules,
            'new_delivery_location_id' => 'nullable|exists:locations,id',
            'occurred_at' => 'nullable|date',
            'km_reading' => [
                'nullable',
                'numeric',
                'min:0',
                Rule::when(in_array($type, ['arrived_pickup', 'arrived_delivery'], true), 'required'),
            ],
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

            function (\Illuminate\Validation\Validator $validator) {
                if ($this->input('km_reading') === null || $this->input('order_id') === null) {
                    return;
                }

                $lastOrderKm = TripCheckpoint::where('order_id', $this->input('order_id'))
                    ->whereNotNull('km_reading')
                    ->orderByDesc('occurred_at')
                    ->orderByDesc('id')
                    ->value('km_reading');

                if ($lastOrderKm !== null && (float) $this->input('km_reading') < (float) $lastOrderKm) {
                    $validator->errors()->add('km_reading', 'Số km phải lớn hơn hoặc bằng km gần nhất của đơn hàng này ('.number_format((float) $lastOrderKm, 1).' km)');
                }

                $trip = $this->route('trip');
                if ($trip instanceof Trip && $trip->start_km !== null) {
                    if ((float) $this->input('km_reading') < (float) $trip->start_km) {
                        $validator->errors()->add('km_reading', 'Số km không được nhỏ hơn km bắt đầu chuyến ('.number_format((float) $trip->start_km, 1).' km)');
                    }
                }
            },

            function (\Illuminate\Validation\Validator $validator) {
                if (! in_array($this->input('checkpoint_type'), ['arrived_delivery', 'completed'], true) || $this->input('km_reading') === null || $this->input('order_id') === null) {
                    return;
                }

                $leftPickupKm = TripCheckpoint::where('order_id', $this->input('order_id'))
                    ->where('checkpoint_type', 'left_pickup')
                    ->value('km_reading');

                if ($leftPickupKm !== null && (float) $this->input('km_reading') <= (float) $leftPickupKm) {
                    $validator->errors()->add('km_reading', sprintf(
                        'Số km phải lớn hơn km lúc rời điểm nhận (%.1f km)',
                        $leftPickupKm
                    ));
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
