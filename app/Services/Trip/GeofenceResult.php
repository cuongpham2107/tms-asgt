<?php

namespace App\Services\Trip;

use App\Enums\GeoCheckStatus;
use Carbon\CarbonInterface;

/**
 * Kết quả kiểm tra vị trí của một mốc: lớp A (tài xế ↔ xe) và lớp B (xe ↔ location).
 */
final readonly class GeofenceResult
{
    public function __construct(
        public ?float $eupLat,
        public ?float $eupLng,
        public ?CarbonInterface $eupRecordedAt,
        public ?float $eupSpeed,
        public GeoCheckStatus $vehicleStatus,
        public ?int $vehicleDistanceM,
        public GeoCheckStatus $locationStatus,
        public ?int $locationDistanceM,
        public ?string $locationCode,
    ) {}

    /**
     * @return array{eup_lat: ?float, eup_lng: ?float, eup_recorded_at: ?CarbonInterface, eup_speed: ?float, vehicle_distance_m: ?int, vehicle_geo_status: string, location_distance_m: ?int, location_geo_status: string}
     */
    public function toCheckpointAttributes(): array
    {
        return [
            'eup_lat' => $this->eupLat,
            'eup_lng' => $this->eupLng,
            'eup_recorded_at' => $this->eupRecordedAt,
            'eup_speed' => $this->eupSpeed,
            'vehicle_distance_m' => $this->vehicleDistanceM,
            'vehicle_geo_status' => $this->vehicleStatus->value,
            'location_distance_m' => $this->locationDistanceM,
            'location_geo_status' => $this->locationStatus->value,
        ];
    }

    /**
     * Lý do từ chối mốc theo các lớp đang bật chặn, null nếu được phép gửi.
     */
    public function rejectionMessage(bool $enforceVehicle, bool $enforceLocation): ?string
    {
        return $this->rejection($enforceVehicle, $enforceLocation)['message'] ?? null;
    }

    /**
     * @return array{message: string, layer: string, distance_m: ?int, target_code: ?string}|null
     */
    public function rejection(bool $enforceVehicle, bool $enforceLocation): ?array
    {
        if ($enforceLocation && $this->locationStatus === GeoCheckStatus::OutOfRange) {
            return [
                'message' => sprintf(
                    'Xe đang cách %s %s. Vui lòng gửi khi xe đã đến nơi. Nếu xe đã ở đúng điểm, vui lòng thử lại sau 1 phút hoặc liên hệ Điều hành.',
                    $this->locationCode ?? 'điểm đến',
                    $this->formatDistance($this->locationDistanceM),
                ),
                'layer' => 'location',
                'distance_m' => $this->locationDistanceM,
                'target_code' => $this->locationCode,
            ];
        }

        if ($enforceVehicle && $this->vehicleStatus === GeoCheckStatus::OutOfRange) {
            return [
                'message' => sprintf(
                    'Vị trí điện thoại đang cách xe %s. Vui lòng gửi khi đang ở cạnh xe. Nếu bạn đang ở cạnh xe, vui lòng thử lại sau 1 phút hoặc liên hệ Điều hành.',
                    $this->formatDistance($this->vehicleDistanceM),
                ),
                'layer' => 'vehicle',
                'distance_m' => $this->vehicleDistanceM,
                'target_code' => null,
            ];
        }

        return null;
    }

    private function formatDistance(?int $meters): string
    {
        if ($meters === null) {
            return '';
        }

        return $meters >= 1000
            ? number_format($meters / 1000, 1, ',', '.').' km'
            : $meters.' m';
    }
}
