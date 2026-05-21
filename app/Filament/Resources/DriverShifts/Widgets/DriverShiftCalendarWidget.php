<?php

namespace App\Filament\Resources\DriverShifts\Widgets;

use App\Enums\ShiftType;
use App\Models\DriverShift;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Illuminate\Database\Eloquent\Model;
use Saade\FilamentFullCalendar\Data\EventData;
use Saade\FilamentFullCalendar\Widgets\FullCalendarWidget;

class DriverShiftCalendarWidget extends FullCalendarWidget
{
    public Model|string|null $model = DriverShift::class;

    protected int|string|array $columnSpan = 'full';

    /**
     * @param  array{start: string, end: string, timezone: string}  $info
     */
    public function fetchEvents(array $info): array
    {
        return DriverShift::query()
            ->with(['driver', 'vehicle'])
            ->where('start_time', '>=', $info['start'])
            ->where('start_time', '<=', $info['end'])
            ->get()
            ->map(function (DriverShift $shift) {
                $driverName = $shift->driver?->name ?? 'Không rõ';
                $vehiclePlate = $shift->vehicle?->plate_number ?? '';
                $shiftLabel = $shift->shift_type?->getLabel() ?? '';
                $isActive = $shift->end_time === null;

                $title = $driverName;
                if ($vehiclePlate) {
                    $title .= " — {$vehiclePlate}";
                }
                if ($shiftLabel) {
                    $title .= " ({$shiftLabel})";
                }

                return EventData::make()
                    ->id($shift->id)
                    ->title($title)
                    ->start($shift->start_time)
                    ->end($shift->end_time ?? $shift->start_time)
                    ->backgroundColor($isActive ? '#eab308' : '#22c55e')
                    ->borderColor($isActive ? '#eab308' : '#22c55e')
                    ->extendedProps([
                        'status' => $isActive ? 'active' : 'completed',
                        'start_km' => $shift->start_km,
                        'end_km' => $shift->end_km,
                        'total_km' => $shift->total_km,
                    ])
                    ->toArray();
            })
            ->all();
    }

    public function getFormSchema(): array
    {
        return [
            Select::make('driver_id')
                ->label('Lái xe')
                ->relationship('driver', 'name')
                ->required(),
            Select::make('vehicle_id')
                ->label('Xe')
                ->relationship('vehicle', 'plate_number'),
            Select::make('shift_type')
                ->label('Loại ca')
                ->options(ShiftType::class)
                ->required(),
            DateTimePicker::make('start_time')
                ->label('Giờ bắt đầu')
                ->required(),
            DateTimePicker::make('end_time')
                ->label('Giờ kết thúc'),
        ];
    }
}
