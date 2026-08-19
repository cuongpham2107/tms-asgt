<?php

namespace App\Http\Requests;

use App\Enums\CheckpointType;
use App\Enums\TripStatus;
use App\Http\Requests\Concerns\NormalizesDecimalInput;
use App\Models\DriverShift;
use App\Models\Trip;
use App\Services\Trip\TripKmLimitService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class EndVehicleRequest extends FormRequest
{
    use NormalizesDecimalInput;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'km_reading' => 'required|numeric|min:0',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'km_reading' => $this->normalizeDecimal($this->input('km_reading')),
        ]);
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                $shift = $this->route('shift');
                if (! $shift instanceof DriverShift) {
                    return;
                }

                $vehicle = $shift->trips()
                    ->latest('started_at')
                    ->first()?->vehicle;

                if ($vehicle === null) {
                    return;
                }

                $kmReading = (float) $this->input('km_reading');

                $activeTrip = Trip::where('vehicle_id', $vehicle->id)
                    ->where('shift_id', $shift->id)
                    ->whereNotIn('status', [TripStatus::Completed, TripStatus::DriverSwap, TripStatus::Cancelled])
                    ->first();

                if ($activeTrip !== null) {
                    $validationResult = app(TripKmLimitService::class)->validate(
                        $activeTrip,
                        $kmReading,
                        CheckpointType::DriverSwap->value,
                    );

                    if (! $validationResult['is_valid']) {
                        $validator->errors()->add('km_reading', $validationResult['message']);
                    }

                    return;
                }

                if ($kmReading < (float) $vehicle->current_mileage) {
                    $validator->errors()->add('km_reading', 'Số km không được nhỏ hơn số km hiện tại của xe ('.number_format((float) $vehicle->current_mileage, 1).' km)');
                }

                $maxAllowed = (float) $vehicle->current_mileage + TripKmLimitService::DELTA_LONG;
                if ($kmReading > $maxAllowed) {
                    $validator->errors()->add('km_reading', sprintf(
                        'Số km nhập vào (%.1f km) vượt quá giới hạn cho phép (+%.0f km) so với km hiện tại của xe (%.1f km). Tối đa cho phép: %.1f km.',
                        $kmReading,
                        TripKmLimitService::DELTA_LONG,
                        (float) $vehicle->current_mileage,
                        $maxAllowed
                    ));
                }
            },
        ];
    }
}
