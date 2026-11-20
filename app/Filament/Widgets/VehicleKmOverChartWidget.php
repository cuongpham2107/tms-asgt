<?php

namespace App\Filament\Widgets;

use App\Enums\VehicleOwnerType;
use App\Filament\Pages\VehicleDispatchBoard;
use App\Models\Vehicle;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Widgets\Widget;

/**
 * Xếp hạng KM đã chạy trong chu kỳ (từ ngày 23) của toàn bộ xe công ty đang theo dõi KM over,
 * kèm tổng tiền vượt định mức và hệ số kiểm soát.
 */
class VehicleKmOverChartWidget extends Widget
{
    use HasWidgetShield;

    public const KM_LIMIT = 3000;

    public const OVER_FEE_PER_KM = 10000;

    protected string $view = 'filament.widgets.vehicle-km-over-chart';

    protected int|string|array $columnSpan = ['default' => 'full', 'lg' => 1];

    /**
     * @return array{rows: list<array{plate: string, owner: string, capacity: ?string, km: float, level: ?string}>, totalKm: float, overCost: float, coefficient: float, scale: float, limit: int}
     */
    protected function getViewData(): array
    {
        $rows = Vehicle::query()
            ->where('is_active', true)
            ->whereIn('type', [VehicleOwnerType::Company->value, 'company'])
            ->get(['id', 'type', 'owner', 'plate_number', 'load_capacity', 'current_mileage', 'monthly_start_km'])
            ->map(fn (Vehicle $vehicle): array => [
                'plate' => (string) $vehicle->plate_number,
                'owner' => (string) $vehicle->owner,
                'capacity' => $vehicle->load_capacity !== null ? rtrim(rtrim((string) $vehicle->load_capacity, '0'), '.').'T' : null,
                'km' => VehicleDispatchBoard::getKmOverData($vehicle)['delta'],
            ])
            ->filter(fn (array $row): bool => $row['km'] !== null)
            ->sortByDesc('km')
            ->map(fn (array $row): array => [
                ...$row,
                'level' => VehicleDispatchBoard::getAlertLevel($row['km'], 2600, 2800, self::KM_LIMIT),
            ])
            ->values();

        $totalKm = (float) $rows->sum('km');
        $overKm = (float) $rows->sum(fn (array $row): float => max(0, $row['km'] - self::KM_LIMIT));

        return [
            'rows' => $rows->all(),
            'totalKm' => $totalKm,
            'overCost' => $overKm * self::OVER_FEE_PER_KM,
            'coefficient' => $rows->isEmpty() ? 0.0 : $totalKm / ($rows->count() * self::KM_LIMIT),
            'scale' => max(self::KM_LIMIT, (float) $rows->max('km')),
            'limit' => self::KM_LIMIT,
        ];
    }
}
