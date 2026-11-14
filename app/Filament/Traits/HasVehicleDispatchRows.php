<?php

namespace App\Filament\Traits;

use App\Enums\DriverWorkShift;
use App\Enums\TripStatus;
use App\Enums\VehicleOwnerType;
use App\Filament\Pages\VehicleDispatchBoard;
use App\Models\Vehicle;
use App\Services\ShiftScheduleService;

/**
 * Dữ liệu bảng "Kiểm soát tình trạng xe": dùng chung cho page (bảng Filament/action) và widget React.
 * Lớp dùng trait cần các thuộc tính: searchQuery, sortBy, sortDirection, activeOwnerType, activeStation, activeMovementStatus.
 */
trait HasVehicleDispatchRows
{
    public function getGroupedVehicles(): array
    {
        [$shiftStart, $shiftEnd] = VehicleDispatchBoard::getShiftWindow();
        $isEvenShift = ShiftScheduleService::determineShift() === DriverWorkShift::Even;
        $runningValues = array_map(fn (TripStatus $s) => $s->value, VehicleDispatchBoard::runningTripStatuses());

        $vehicles = Vehicle::query()
            ->select('vehicles.*')
            ->selectSub(function ($q) use ($isEvenShift) {
                $shiftCoalesce = $isEvenShift
                    ? "COALESCE(u2.station, u3.station, u1.station, 'OTHER')"
                    : "COALESCE(u3.station, u2.station, u1.station, 'OTHER')";

                $q->selectRaw($shiftCoalesce)
                    ->from('vehicles as v_sub')
                    ->leftJoin('users as u1', 'u1.id', '=', 'v_sub.current_driver_id')
                    ->leftJoin('users as u2', 'u2.id', '=', 'v_sub.even_driver_id')
                    ->leftJoin('users as u3', 'u3.id', '=', 'v_sub.odd_driver_id')
                    ->whereColumn('v_sub.id', 'vehicles.id')
                    ->limit(1);
            }, 'driver_station')
            ->selectSub(function ($q) {
                $q->selectRaw('MAX(COALESCE(completed_at, started_at, updated_at))')
                    ->from('trips as t_last')
                    ->whereColumn('t_last.vehicle_id', 'vehicles.id')
                    ->where('t_last.status', TripStatus::Completed->value);
            }, 'last_completed_trip_at')
            ->where('vehicles.is_active', true)
            ->whereIn('vehicles.type', [VehicleOwnerType::Company->value, 'company'])
            ->with([
                'driver.driverShifts',
                'evenDriver.driverShifts',
                'oddDriver.driverShifts',
                'trips' => function ($q) use ($shiftStart, $shiftEnd, $runningValues) {
                    $q->where(function ($sub) use ($shiftStart, $shiftEnd, $runningValues) {
                        $sub->whereBetween('started_at', [$shiftStart, $shiftEnd])
                            ->orWhereBetween('completed_at', [$shiftStart, $shiftEnd])
                            ->orWhere(function ($pendingQ) use ($shiftStart, $shiftEnd) {
                                $pendingQ->where('status', TripStatus::Pending->value)
                                    ->where(function ($dateQ) use ($shiftStart, $shiftEnd) {
                                        $dateQ->whereBetween('started_at', [$shiftStart, $shiftEnd])
                                            ->orWhereBetween('created_at', [$shiftStart, $shiftEnd]);
                                    });
                            })
                            ->orWhereIn('status', $runningValues)
                            ->orWhere('is_paused', true)
                            ->orWhereHas('kmReports', fn ($kr) => $kr->where('status', 'pending'));
                    })->with([
                        'checkpoints',
                        'driver.driverShifts',
                        'startLocation',
                        'endLocation.area',
                        'orders.pickupLocation',
                        'orders.deliveryPoints.location.area',
                        'latestPendingKmReport',
                    ]);
                },
            ])
            ->get();

        if (filled($this->searchQuery)) {
            $term = mb_strtolower(trim($this->searchQuery));

            $vehicles = $vehicles->filter(fn (Vehicle $vehicle): bool => str_contains(mb_strtolower((string) $vehicle->plate_number), $term)
                || str_contains(mb_strtolower(VehicleDispatchBoard::getDriverName($vehicle)), $term));
        }

        $stationOrder = [
            'NB' => 'Nội Bài (NB)',
            'BN' => 'Bắc Ninh (BN)',
            'TN' => 'Thái Nguyên (TN)',
            'Province' => 'Ngoại tỉnh (Province)',
            'OTHER' => 'Khác',
        ];

        $grouped = [];
        foreach ($stationOrder as $code => $title) {
            $grouped[$code] = [
                'code' => $code,
                'title' => $title,
                'vehicles' => collect(),
            ];
        }

        foreach ($vehicles as $vehicle) {
            $code = VehicleDispatchBoard::resolveVehicleLocationGroup($vehicle);

            if (! isset($grouped[$code])) {
                $code = 'OTHER';
            }

            $grouped[$code]['vehicles']->push($vehicle);
        }

        foreach ($grouped as $code => &$group) {
            $group['vehicles'] = $group['vehicles']
                ->sort(function (Vehicle $a, Vehicle $b) {
                    if ($this->sortBy === 'plate_number') {
                        $cmp = strnatcasecmp($a->plate_number, $b->plate_number);

                        return $this->sortDirection === 'desc' ? -$cmp : $cmp;
                    }

                    // Mặc định hoặc khi sắp xếp theo status: gom theo nhóm trạng thái trước, cùng trạng thái thì theo BSX
                    $prioA = VehicleDispatchBoard::getTripStatusSortPriority($a);
                    $prioB = VehicleDispatchBoard::getTripStatusSortPriority($b);

                    if ($prioA !== $prioB) {
                        return $this->sortDirection === 'desc'
                            ? ($prioB <=> $prioA)
                            : ($prioA <=> $prioB);
                    }

                    return strnatcasecmp($a->plate_number, $b->plate_number);
                })
                ->values();
        }

        return array_filter($grouped, fn ($g) => $g['vehicles']->isNotEmpty());
    }
}
