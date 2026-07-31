<?php

namespace App\Http\Requests;

use App\Enums\TripStatus;
use App\Http\Requests\Concerns\NormalizesDecimalInput;
use App\Models\DriverShift;
use App\Models\Trip;
use App\Models\TripCheckpoint;
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

                if ($kmReading < (float) $vehicle->current_mileage) {
                    $validator->errors()->add('km_reading', 'Số km không được nhỏ hơn số km hiện tại của xe ('.number_format((float) $vehicle->current_mileage, 1).' km)');
                }

                $activeTrip = Trip::where('vehicle_id', $vehicle->id)
                    ->where('shift_id', $shift->id)
                    ->whereNotIn('status', [TripStatus::Completed, TripStatus::DriverSwap])
                    ->first();

                if ($activeTrip === null) {
                    return;
                }

                $lastTripKm = TripCheckpoint::where('trip_id', $activeTrip->id)
                    ->whereNotNull('km_reading')
                    ->orderByDesc('occurred_at')
                    ->orderByDesc('id')
                    ->value('km_reading');

                if ($lastTripKm !== null && $kmReading < (float) $lastTripKm) {
                    $validator->errors()->add('km_reading', 'Số km không được nhỏ hơn km gần nhất của chuyến ('.number_format((float) $lastTripKm, 1).' km)');
                }
            },
        ];
    }
}
