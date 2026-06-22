<?php

namespace App\Http\Requests;

use App\Enums\CheckpointType;
use App\Http\Requests\Concerns\NormalizesDecimalInput;
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

        $orderIdRules = ['nullable', 'exists:orders,id'];
        $deliveryPointIdRules = ['nullable', 'exists:order_delivery_points,id'];

        if (in_array($type, ['arrived_delivery', 'completed'], true)) {
            $orderIdRules = ['required', 'exists:orders,id'];
            $deliveryPointIdRules = ['required', 'exists:order_delivery_points,id'];
        }

        return [
            'checkpoint_type' => ['required', 'string', Rule::in(array_map(fn ($case) => $case->value, CheckpointType::cases()))],
            'order_id' => $orderIdRules,
            'delivery_point_id' => $deliveryPointIdRules,
            'occurred_at' => 'nullable|date',
            'km_reading' => [
                'nullable',
                'numeric',
                Rule::when($type === 'started', 'prohibited'),
                Rule::when(in_array($type, ['arrived_pickup', 'completed'], true), 'required'),
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
                if ($this->input('km_reading') === null || $this->input('order_id') === null) {
                    return;
                }

                $lastOrderKm = TripCheckpoint::where('order_id', $this->input('order_id'))
                    ->whereNotNull('km_reading')
                    ->orderBy('occurred_at', 'desc')
                    ->value('km_reading');

                if ($lastOrderKm !== null && (float) $this->input('km_reading') < (float) $lastOrderKm) {
                    $message = 'Số km phải lớn hơn hoặc bằng km gần nhất của đơn hàng này ('.number_format((float) $lastOrderKm, 1).' km)';
                    throw new HttpResponseException(response()->json(['message' => $message], 422));
                }
            },

            function (\Illuminate\Validation\Validator $validator) {
                if ($this->input('checkpoint_type') !== 'completed' || $this->input('km_reading') === null || $this->input('order_id') === null) {
                    return;
                }

                $leftPickupKm = TripCheckpoint::where('order_id', $this->input('order_id'))
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
