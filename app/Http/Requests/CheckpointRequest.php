<?php

namespace App\Http\Requests;

use App\Enums\CheckpointType;
use App\Http\Requests\Concerns\NormalizesDecimalInput;
use App\Models\Order;
use App\Models\TripCheckpoint;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class CheckpointRequest extends FormRequest
{
    use NormalizesDecimalInput;

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
            'new_delivery_location_id' => 'nullable|exists:locations,id',
            'checkpoint_type' => ['required', 'string', Rule::in(array_map(fn ($case) => $case->value, CheckpointType::cases()))],
            'occurred_at' => 'nullable|date',
            'km_reading' => [
                'nullable',
                'numeric',
            ],
            'gps_lat' => 'nullable|numeric',
            'gps_lng' => 'nullable|numeric',
            'voice_note' => 'nullable|string',
            'photos' => 'nullable|array',
            'photos.*' => 'nullable|image|max:10240',
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

                // ---- KM bắt buộc ----
                if (in_array($type, [CheckpointType::ArrivedPickup->value, CheckpointType::Completed->value], true)) {
                    if ($this->input('km_reading') === null) {
                        $label = $type === CheckpointType::ArrivedPickup->value ? 'Đến lấy hàng' : 'Hoàn thành';
                        throw new HttpResponseException(response()->json([
                            'message' => "Số km đồng hồ là bắt buộc tại mốc {$label}.",
                        ], 422));
                    }
                }

                // ---- Started không được nhập km ----
                if ($type === CheckpointType::Started->value && $this->input('km_reading') !== null) {
                    throw new HttpResponseException(response()->json([
                        'message' => 'Không được nhập km tại mốc Bắt đầu chuyến. Km sẽ tự động lấy từ đồng hồ xe.',
                    ], 422));
                }

                // ---- Completed: kiểm tra delivery_point_id ----
                if ($type === CheckpointType::Completed->value) {
                    $order = Order::find($this->input('order_id'));
                    if ($order !== null) {
                        $hasDeliveryPoints = $order->deliveryPoints()->count() > 0;

                        if ($hasDeliveryPoints && empty($this->input('delivery_point_id'))) {
                            throw new HttpResponseException(response()->json([
                                'message' => 'Vui lòng chọn điểm giao hàng cụ thể để hoàn thành.',
                            ], 422));
                        }

                        if (! $hasDeliveryPoints
                            && empty($this->input('delivery_point_id'))
                            && empty($this->input('new_delivery_location_id'))) {
                            throw new HttpResponseException(response()->json([
                                'message' => 'Đơn hàng chưa có điểm đến. Vui lòng chọn điểm giao hàng.',
                            ], 422));
                        }
                    }
                }
            },

            // ---- KM validation: so sánh với checkpoint GẦN NHẤT của CHÍNH order đó (per-order, không cross-order) ----
            function (\Illuminate\Validation\Validator $validator) {
                if ($this->input('km_reading') === null) {
                    return;
                }

                $order = Order::find($this->input('order_id'));
                if ($order === null) {
                    return;
                }

                $lastOrderKm = TripCheckpoint::where('order_id', $order->id)
                    ->whereNotNull('km_reading')
                    ->orderBy('occurred_at', 'desc')
                    ->value('km_reading');

                if ($lastOrderKm !== null && (float) $this->input('km_reading') < (float) $lastOrderKm) {
                    $message = 'Số km phải lớn hơn hoặc bằng km gần nhất của đơn hàng này ('.number_format((float) $lastOrderKm, 1).' km)';
                    throw new HttpResponseException(response()->json(['message' => $message], 422));
                }
            },

            // ---- Completed: km phải > km lúc rời điểm nhận của chính order đó ----
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
