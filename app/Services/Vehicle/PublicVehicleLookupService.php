<?php

namespace App\Services\Vehicle;

use App\Enums\OrderStatus;
use App\Enums\TripStatus;
use App\Enums\VehicleOwnerType;
use App\Models\DriverShift;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Shift\ShiftScheduleService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\URL;

/**
 * Tra cứu thông tin xe + lái xe + GPS cho hệ thống ngoài (VD: Kakao bot).
 *
 * Quy tắc ưu tiên:
 * 1. Nếu xe có đơn hàng đang hoạt động (gán qua chuyến chưa hoàn thành/huỷ) -> lấy lái xe của chuyến/đơn gần nhất.
 * 2. Nếu xe chưa gán đơn -> lấy lái xe theo ca chẵn/lẻ đã khai báo trên phương tiện đúng lịch hôm nay.
 */
class PublicVehicleLookupService
{
    public const STALE_MINUTES = 15;

    /**
     * Chuẩn hoá chuỗi biển số / từ khoá tìm kiếm về ký tự chữ + số viết hoa.
     */
    public function normalizeSearch(string $raw): string
    {
        $upper = mb_strtoupper(trim($raw), 'UTF-8');

        return (string) preg_replace('/[^A-Z0-9]/', '', $upper);
    }

    /**
     * Tìm các xe đang hoạt động khớp biển số đã chuẩn hoá.
     *
     * @return Collection<int, Vehicle>
     */
    public function search(string $rawSearch, int $limit = 10): Collection
    {
        $normalized = $this->normalizeSearch($rawSearch);

        if (strlen($normalized) < 2) {
            return new Collection;
        }

        $normalizedColumn = "REPLACE(REPLACE(REPLACE(UPPER(plate_number), '-', ''), '.', ''), ' ', '')";

        return Vehicle::query()
            ->where('is_active', true)
            ->where('type', VehicleOwnerType::Company->value)
            ->whereRaw("{$normalizedColumn} LIKE ?", ["%{$normalized}%"])
            ->with([
                'driver.driverShifts' => fn ($q) => $q->whereNull('end_time')->latest('start_time'),
                'evenDriver.driverShifts' => fn ($q) => $q->whereNull('end_time')->latest('start_time'),
                'oddDriver.driverShifts' => fn ($q) => $q->whereNull('end_time')->latest('start_time'),
                'drivers.driverShifts' => fn ($q) => $q->whereNull('end_time')->latest('start_time'),
                'trips' => function ($q): void {
                    $q->whereNotIn('status', [
                        TripStatus::Cancelled->value,
                        TripStatus::Completed->value,
                    ])->with([
                        'driver.driverShifts' => fn ($sq) => $sq->whereNull('end_time')->latest('start_time'),
                        'orders' => fn ($oq) => $oq->whereNotIn('status', [
                            OrderStatus::Cancelled->value,
                            OrderStatus::Completed->value,
                            OrderStatus::Draft->value,
                        ])->orderBy('planned_loading_at')->orderBy('id'),
                    ])->orderBy('id', 'desc');
                },
            ])
            ->orderByRaw(
                "CASE WHEN {$normalizedColumn} = ? THEN 0 WHEN {$normalizedColumn} LIKE ? THEN 1 ELSE 2 END",
                [$normalized, "%{$normalized}"]
            )
            ->orderBy('plate_number')
            ->limit(max(1, min($limit, 25)))
            ->get();
    }

    /**
     * Chuyển đổi Vehicle thành payload JSON + copy_text chuẩn Kakao/bot (`x5` mặc định hoặc `x1`).
     *
     * @return array<string, mixed>
     */
    public function formatVehiclePayload(Vehicle $vehicle, string $tobot = 'x5'): array
    {
        $todayShift = ShiftScheduleService::determineShift();
        $activeOrderTrip = $this->resolveNearestActiveOrderTrip($vehicle);
        $activeOrder = $activeOrderTrip?->orders?->first();

        /** @var ?User $driver */
        $driver = null;
        $driverName = null;
        $source = 'scheduled_shift';

        if ($activeOrderTrip !== null) {
            $source = 'active_order';
            $driver = $activeOrderTrip->driver;

            if ($vehicle->isRent() && filled($activeOrderTrip->external_driver_name)) {
                $driverName = trim((string) $activeOrderTrip->external_driver_name);
            } else {
                $driverName = $driver?->name;
            }
        } else {
            $driver = $vehicle->getDriverForShift($todayShift);

            if ($driver === null && $vehicle->driver && (bool) $vehicle->driver->is_active) {
                $driver = $vehicle->driver;
                $source = 'current_driver';
            }

            if ($driver !== null) {
                $driverName = $driver->name;
            } elseif ($vehicle->isRent() && filled($vehicle->external_driver_name)) {
                $driverName = trim((string) $vehicle->external_driver_name);
            } else {
                $source = 'none';
            }
        }

        $openShift = $this->resolveOpenShift($driver);
        $shiftElapsedMinutes = $openShift?->start_time ? (int) $openShift->start_time->diffInMinutes(now()) : null;

        $relativeGpsUrl = URL::temporarySignedRoute(
            'vehicles.public-tracking',
            now()->addHours(24),
            ['vehicle' => $vehicle],
            absolute: false,
        );
        $gpsUrl = rtrim((string) config('app.url'), '/').$relativeGpsUrl;

        $loadCapacityTons = $vehicle->load_capacity !== null ? (float) $vehicle->load_capacity : null;
        $loadCapacityKg = $loadCapacityTons !== null ? (int) round($loadCapacityTons * 1000) : null;
        $doorCount = $vehicle->door_count !== null ? (int) $vehicle->door_count : null;
        $formattedPlate = $this->formatPlateNumber((string) ($vehicle->plate_number ?? ''));
        $shiftElapsedLabel = $shiftElapsedMinutes !== null ? $this->formatShiftElapsed($shiftElapsedMinutes) : null;
        $normalizedTobot = strtolower(trim($tobot)) === 'x1' ? 'x1' : 'x5';
        $copyText = $this->buildCopyText(
            tobot: $normalizedTobot,
            plate: $formattedPlate !== '' ? $formattedPlate : '—',
            phone: $driver?->phone ?: '—',
            driverName: $driverName ?: '—',
            loadCapacityKg: $loadCapacityKg,
            doorCount: $doorCount,
            keyTag: isset($vehicle->key_tag) && filled($vehicle->key_tag) ? (string) $vehicle->key_tag : null,
            shiftElapsedMinutes: $shiftElapsedMinutes,
            shiftElapsedLabel: $shiftElapsedLabel,
            gpsUrl: $gpsUrl,
        );

        $hasPosition = $vehicle->gps_lat !== null && $vehicle->gps_lng !== null;
        $lastUpdate = $vehicle->last_gps_update;

        return [
            'id' => $vehicle->id,
            'plate_number' => $vehicle->plate_number,
            'formatted_plate' => $formattedPlate !== '' ? $formattedPlate : null,
            'registration_number' => $vehicle->registration_number,
            'vehicle_type' => $vehicle->vehicle_type?->value,
            'vehicle_type_label' => $vehicle->getVehicleTypeLabel(),
            'load_capacity_tons' => $loadCapacityTons,
            'load_capacity_kg' => $loadCapacityKg,
            'door_count' => $doorCount,
            'source' => $source,
            'shift_schedule' => $todayShift->value,
            'active_order' => $activeOrder ? [
                'id' => $activeOrder->id,
                'order_code' => $activeOrder->order_code,
                'status' => $activeOrder->status?->value,
                'status_label' => $activeOrder->status?->getLabel(),
                'trip_id' => $activeOrderTrip?->id,
                'trip_code' => $activeOrderTrip?->trip_code,
                'trip_status' => $activeOrderTrip?->status?->value,
                'planned_loading_at' => $activeOrder->planned_loading_at?->toIso8601String(),
            ] : null,
            'driver' => $driver !== null || $driverName !== null ? [
                'id' => $driver?->id,
                'name' => $driverName,
                'phone' => $driver?->phone,
                'cccd' => $driver?->cccd,
                'cccd_issue_date' => $driver?->cccd_issue_date instanceof CarbonInterface ? $driver->cccd_issue_date->format('d/m/Y') : null,
                'date_of_birth' => $driver?->date_of_birth instanceof CarbonInterface ? $driver->date_of_birth->format('d/m/Y') : null,
                'address' => $driver?->address,
                'is_on_shift' => $openShift !== null,
                'shift_started_at' => $openShift?->start_time?->toIso8601String(),
                'shift_elapsed_minutes' => $shiftElapsedMinutes,
                'shift_elapsed_label' => $shiftElapsedMinutes !== null ? $this->formatShiftElapsed($shiftElapsedMinutes) : null,
            ] : null,
            'gps' => [
                'has_position' => $hasPosition,
                'lat' => $hasPosition ? (float) $vehicle->gps_lat : null,
                'lng' => $hasPosition ? (float) $vehicle->gps_lng : null,
                'speed_kmh' => $vehicle->gps_speed !== null ? (float) $vehicle->gps_speed : null,
                'address' => $vehicle->gps_address,
                'updated_at' => $lastUpdate?->toIso8601String(),
                'updated_at_human' => $lastUpdate?->diffForHumans(),
                'is_stale' => $this->isStale($lastUpdate),
                'url' => $gpsUrl,
            ],
            'copy_text' => $copyText,
        ];
    }

    /**
     * Payload vị trí trực tiếp của xe cho trang web `/vi-tri-xe/{vehicle}`.
     *
     * @return array{
     *     vehicle_status: string|null,
     *     vehicle_status_label: string,
     *     vehicle_status_color: string,
     *     has_position: bool,
     *     position: array{latitude: float, longitude: float, speed_kmh: float|null, address: string|null, updated_at: string|null, updated_at_human: string|null, is_stale: bool}|null,
     *     polled_at: string
     * }
     */
    public function positionPayload(Vehicle $vehicle): array
    {
        $hasPosition = $vehicle->gps_lat !== null && $vehicle->gps_lng !== null;
        $lastUpdate = $vehicle->last_gps_update;

        return [
            'vehicle_status' => $vehicle->status?->value,
            'vehicle_status_label' => $vehicle->getStatusLabel(),
            'vehicle_status_color' => $vehicle->getStatusColor(),
            'has_position' => $hasPosition,
            'position' => $hasPosition ? [
                'latitude' => (float) $vehicle->gps_lat,
                'longitude' => (float) $vehicle->gps_lng,
                'speed_kmh' => $vehicle->gps_speed !== null ? (float) $vehicle->gps_speed : null,
                'address' => $vehicle->gps_address,
                'updated_at' => $lastUpdate?->toIso8601String(),
                'updated_at_human' => $lastUpdate?->diffForHumans(),
                'is_stale' => $this->isStale($lastUpdate),
            ] : null,
            'polled_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Chọn chuyến có đơn hàng đang hoạt động gần nhất:
     * ưu tiên chuyến đang chạy trước, sau đó đến chuyến pending có planned_loading_at gần nhất.
     */
    private function resolveNearestActiveOrderTrip(Vehicle $vehicle): ?Trip
    {
        $tripsWithActiveOrders = $vehicle->trips
            ->filter(fn (Trip $trip): bool => $trip->orders->isNotEmpty())
            ->values();

        if ($tripsWithActiveOrders->isEmpty()) {
            return null;
        }

        $runningStatuses = [
            TripStatus::Started,
            TripStatus::ArrivedPickup,
            TripStatus::Delivering,
            TripStatus::ArrivedDelivery,
            TripStatus::Delivered,
            TripStatus::DriverSwap,
            TripStatus::ReturnTrip,
        ];

        $running = $tripsWithActiveOrders
            ->filter(fn (Trip $trip): bool => in_array($trip->status, $runningStatuses, true))
            ->sortByDesc('id')
            ->first();

        if ($running !== null) {
            return $running;
        }

        return $tripsWithActiveOrders
            ->sortBy(function (Trip $trip): array {
                $firstOrder = $trip->orders->first();

                return [
                    $firstOrder?->planned_loading_at?->getTimestamp() ?? PHP_INT_MAX,
                    $trip->id,
                ];
            })
            ->first();
    }

    private function resolveOpenShift(?User $driver): ?DriverShift
    {
        if ($driver === null) {
            return null;
        }

        if ($driver->relationLoaded('driverShifts')) {
            return $driver->driverShifts->firstWhere('end_time', null);
        }

        return $driver->driverShifts()
            ->whereNull('end_time')
            ->latest('start_time')
            ->first();
    }

    private function buildCopyText(
        string $tobot,
        string $plate,
        string $phone,
        string $driverName,
        ?int $loadCapacityKg,
        ?int $doorCount,
        ?string $keyTag,
        ?int $shiftElapsedMinutes,
        ?string $shiftElapsedLabel,
        string $gpsUrl,
    ): string {
        if ($tobot === 'x1') {
            $payloadText = $loadCapacityKg !== null ? number_format($loadCapacityKg, 0, '.', ',').' kg' : '—';
            $elapsedText = $shiftElapsedMinutes !== null ? $this->formatShiftElapsedShort($shiftElapsedMinutes) : 'Not in shift';

            return $plate."\n"
                .'Mobile: '.$phone."\n"
                .'Driver: '.$driverName."\n"
                .'Payload: '.$payloadText."\n"
                .'Num. of doors: '.($doorCount !== null ? (string) $doorCount : '—')."\n"
                .'Key tag: '.($keyTag ?: '—')."\n"
                .'('.$elapsedText.")\n"
                .$gpsUrl;
        }

        return $plate."\n"
            .'SĐT: '.$phone."\n"
            .'Lái xe: '.$driverName."\n"
            .'Trọng tải cho phép: '.($loadCapacityKg !== null ? "{$loadCapacityKg} kg" : '—')."\n"
            .'Số cửa: '.($doorCount !== null ? (string) $doorCount : '—')."\n"
            .'('.($shiftElapsedLabel ?? 'Chưa vào ca').")\n"
            .$gpsUrl;
    }

    private function formatShiftElapsedShort(int $minutes): string
    {
        $days = intdiv($minutes, 1440);
        $hours = intdiv($minutes % 1440, 60);
        $mins = $minutes % 60;

        if ($days > 0) {
            return "{$days}d{$hours}h{$mins}m ago";
        }

        if ($hours > 0) {
            return "{$hours}h{$mins}m ago";
        }

        return "{$mins}m ago";
    }

    private function formatPlateNumber(string $plate): string
    {
        $clean = (string) preg_replace('/[^A-Za-z0-9]/', '', strtoupper(trim($plate)));

        if (preg_match('/^(\d{2}[A-Z]{1,2})(\d{3})(\d{2})$/', $clean, $matches) === 1) {
            return "{$matches[1]}-{$matches[2]}.{$matches[3]}";
        }

        if (preg_match('/^(\d{2}[A-Z]{1,2})(\d{4})$/', $clean, $matches) === 1) {
            return "{$matches[1]}-{$matches[2]}";
        }

        return trim($plate);
    }

    private function formatShiftElapsed(int $minutes): string
    {
        if ($minutes < 60) {
            return "Giao ca {$minutes} phút trước";
        }

        $hours = intdiv($minutes, 60);
        $mins = $minutes % 60;

        return $mins > 0
            ? "Giao ca {$hours} giờ {$mins} phút trước"
            : "Giao ca {$hours} giờ trước";
    }

    private function isStale(?CarbonInterface $lastUpdate): bool
    {
        return $lastUpdate === null || $lastUpdate->lt(now()->subMinutes(self::STALE_MINUTES));
    }
}
