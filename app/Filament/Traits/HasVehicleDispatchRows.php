<?php

namespace App\Filament\Traits;

use App\Enums\TripStatus;
use App\Enums\VehicleOwnerType;
use App\Filament\Pages\VehicleDispatchBoard;
use App\Models\Vehicle;
use Illuminate\Support\Facades\DB;

/**
 * Dữ liệu bảng "Kiểm soát tình trạng xe": dùng chung cho page (bảng Filament/action) và widget React.
 * Lớp dùng trait cần các thuộc tính: searchQuery, sortBy, sortDirection, activeOwnerType, activeStation, activeMovementStatus.
 */
trait HasVehicleDispatchRows
{
    public function getGroupedVehicles(): array
    {
        [$shiftStart, $shiftEnd] = VehicleDispatchBoard::getShiftWindow();
        $runningValues = array_map(fn (TripStatus $s) => $s->value, VehicleDispatchBoard::runningTripStatuses());
        $isRent = (($this->activeOwnerType ?? 'company') === 'rent')
            || (($this->activeTab ?? 'vehicles') === 'rent');

        $vehicles = Vehicle::query()
            ->where('vehicles.is_active', true)
            ->whereIn(
                'vehicles.type',
                $isRent
                    ? [VehicleOwnerType::Rent->value, 'rent']
                    : [VehicleOwnerType::Company->value, 'company']
            )
            ->with([
                'driver.driverShifts' => fn ($q) => $q->whereNull('end_time'),
                'evenDriver',
                'oddDriver',
                'trips' => function ($q) use ($shiftStart, $shiftEnd, $runningValues, $isRent) {
                    $q->where(function ($sub) use ($shiftStart, $shiftEnd, $runningValues, $isRent) {
                        $sub->whereBetween('started_at', [$shiftStart, $shiftEnd])
                            ->orWhereBetween('completed_at', [$shiftStart, $shiftEnd])
                            ->orWhere(function ($pendingQ) use ($shiftStart, $shiftEnd, $isRent) {
                                $pendingQ->where('status', TripStatus::Pending->value)
                                    ->when(! $isRent, function ($dateQ) use ($shiftStart, $shiftEnd) {
                                        $dateQ->where(function ($dq) use ($shiftStart, $shiftEnd) {
                                            $dq->whereBetween('started_at', [$shiftStart, $shiftEnd])
                                                ->orWhereBetween('created_at', [$shiftStart, $shiftEnd]);
                                        });
                                    });
                            })
                            ->orWhereIn('status', $runningValues)
                            ->orWhere('is_paused', true)
                            ->orWhereHas('kmReports', fn ($kr) => $kr->where('status', 'pending'));
                    })->with([
                        'checkpoints',
                        'driver.driverShifts' => fn ($dq) => $dq->whereNull('end_time'),
                        'startLocation',
                        'endLocation.area',
                        'orders.pickupLocation',
                        'orders.deliveryPoints.location.area',
                        'latestPendingKmReport',
                    ]);
                },
            ])
            ->get();

        // Batch 1 query lấy thời điểm hoàn thành chuyến gần nhất thay cho correlated selectSub per row.
        $lastCompletedByVehicle = $vehicles->isNotEmpty()
            ? DB::table('trips')
                ->select('vehicle_id')
                ->selectRaw('MAX(COALESCE(completed_at, started_at, updated_at)) as last_at')
                ->whereIn('vehicle_id', $vehicles->modelKeys())
                ->where('status', TripStatus::Completed->value)
                ->groupBy('vehicle_id')
                ->pluck('last_at', 'vehicle_id')
            : collect();

        foreach ($vehicles as $vehicle) {
            $vehicle->setAttribute('last_completed_trip_at', $lastCompletedByVehicle->get($vehicle->id));
            $vehicle->freezeActiveTrip();
        }

        // Đối với sheet Xe thuê: chỉ lấy các xe thuê đang có chuyến chờ chạy hoặc đang chạy (active_trip !== null)
        if ($isRent) {
            $vehicles = $vehicles->filter(fn (Vehicle $vehicle): bool => $vehicle->active_trip !== null)->values();
        }

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

        $needOrderPriority = $this->sortBy === 'order_type' || ($isRent && $this->sortBy !== 'plate_number');

        foreach ($grouped as $code => &$group) {
            if ($group['vehicles']->isEmpty()) {
                continue;
            }

            // Tính trước khoá sắp xếp O(N) thay vì gọi helper lặp lại O(N log N) trong hàm so sánh.
            $sortMeta = [];
            foreach ($group['vehicles'] as $v) {
                $sortMeta[$v->id] = [
                    'order' => $needOrderPriority ? VehicleDispatchBoard::getOrderTypeSortPriority($v) : 0,
                    'status' => $this->sortBy !== 'plate_number' ? VehicleDispatchBoard::getTripStatusSortPriority($v) : 0,
                ];
            }

            $group['vehicles'] = $group['vehicles']
                ->sort(function (Vehicle $a, Vehicle $b) use ($isRent, $sortMeta) {
                    if ($this->sortBy === 'plate_number') {
                        $cmp = strnatcasecmp($a->plate_number, $b->plate_number);

                        return $this->sortDirection === 'desc' ? -$cmp : $cmp;
                    }

                    if ($this->sortBy === 'order_type') {
                        $orderPrioA = $sortMeta[$a->id]['order'];
                        $orderPrioB = $sortMeta[$b->id]['order'];

                        if ($orderPrioA !== $orderPrioB) {
                            return $this->sortDirection === 'desc'
                                ? ($orderPrioB <=> $orderPrioA)
                                : ($orderPrioA <=> $orderPrioB);
                        }
                    } elseif ($isRent) {
                        // Mặc định trên sheet Xe thuê: ưu tiên sắp xếp theo Loại đơn từ HHHK đến Hàng ngoài
                        $orderPrioA = $sortMeta[$a->id]['order'];
                        $orderPrioB = $sortMeta[$b->id]['order'];

                        if ($orderPrioA !== $orderPrioB) {
                            return $orderPrioA <=> $orderPrioB;
                        }
                    }

                    // Tiếp theo: gom theo nhóm trạng thái, cùng trạng thái thì theo BSX
                    $prioA = $sortMeta[$a->id]['status'];
                    $prioB = $sortMeta[$b->id]['status'];

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
