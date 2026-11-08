<?php

namespace App\Filament\Widgets;

use App\Enums\OnDutyLocation;
use App\Enums\TripStatus;
use App\Filament\Pages\DriverMonthlyKmReport;
use App\Services\DriverMonthlyKmReportService;
use App\Services\Export\TripExcelExportService;
use Carbon\Carbon;
use Filament\Widgets\Concerns\HasJsRenderer;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Vite;
use Livewire\Attributes\Renderless;

/**
 * Thân trang "Báo cáo tài xế" do React vẽ. Lần đầu widget gửi số liệu của kỳ trên URL; sau đó React tự
 * đổi kỳ qua loadReport (không vẽ lại page), tự lo tab, tìm kiếm, chọn lái xe, gập nhóm và phân trang.
 * Bảng kê chuyến và đánh giá nặng hơn nên tải sau, ngầm, trong một lần (loadDetails).
 */
class DriverMonthlyKmTable extends Widget
{
    use HasJsRenderer;

    /** Chiều cao khung chờ trong lúc widget tải sau trang (defer), tránh giật bố cục. */
    protected ?string $placeholderHeight = '28rem';

    protected int|string|array $columnSpan = 'full';

    public string $period = 'month';

    public string $date = '';

    public function getRenderer(): string
    {
        return Vite::asset('resources/js/filament/widgets/driver-monthly-km-table.tsx');
    }

    /**
     * @return array<string, mixed>
     */
    public function getRendererConfiguration(): array
    {
        return $this->report(DriverMonthlyKmReport::normalizePeriod($this->period), DriverMonthlyKmReport::normalizeDate($this->date));
    }

    /**
     * Số liệu tổng hợp của một kỳ khác; React giữ bảng cũ (mờ) trong lúc chờ.
     *
     * @return array<string, mixed>
     */
    #[Renderless]
    public function loadReport(string $period, string $date): array
    {
        return $this->report(DriverMonthlyKmReport::normalizePeriod($period), DriverMonthlyKmReport::normalizeDate($date));
    }

    /**
     * @return array<string, mixed>
     */
    private function report(string $period, string $date): array
    {
        [$from, $to] = DriverMonthlyKmReport::rangeFor($period, $date);
        $service = app(DriverMonthlyKmReportService::class);
        $lines = $service->tripLines($from, $to);
        $stationOrder = array_flip(array_map(fn (OnDutyLocation $station): string => $station->value, OnDutyLocation::cases()));

        $drivers = $service->summarize($lines)
            ->sortBy(fn (array $row): int => $stationOrder[$row['station']?->value] ?? PHP_INT_MAX)
            ->map(fn (array $row): array => [
                'id' => $row['driver_id'],
                'name' => $row['driver_name'],
                'code' => $row['employee_code'],
                'station' => $row['station']?->value ?? 'none',
                'stationLabel' => $row['station']?->getLabel() ?? 'Chưa gán',
                'trips' => $row['trip_count'],
                'emptyTrips' => $row['empty_trip_count'],
                'kmTotal' => $row['km_total'],
                'kmLoaded' => $row['km_loaded'],
                'kmEmpty' => $row['km_empty'],
            ])
            ->values()
            ->all();

        return [
            'period' => $period,
            'date' => $date,
            'periodLabel' => DriverMonthlyKmReport::labelFor($period, $date),
            'isCurrent' => now()->between($from, $to),
            'hasNext' => ! $to->isFuture(),
            'drivers' => $drivers,
            'trips' => $lines
                ->map(fn (array $line): array => [
                    'id' => $line['trip_id'],
                    'driverId' => $line['driver_id'],
                    'code' => $line['trip_code'],
                    'plate' => $line['plate'],
                    'day' => $line['started_at']?->toDateString(),
                    'time' => $line['started_at']?->format('H:i'),
                    'status' => match ($line['status']) {
                        TripStatus::Cancelled => 'cancelled',
                        TripStatus::DriverSwap => 'swap',
                        default => null,
                    },
                    'orderCodes' => $line['order_codes'],
                    'isEmpty' => $line['is_empty'],
                    'kmTotal' => $line['km_total'],
                    'kmLoaded' => $line['km_loaded'],
                    'kmEmpty' => $line['km_empty'],
                ])
                ->values()
                ->all(),
            'totals' => [
                'trips' => $lines->pluck('trip_id')->unique()->count(),
                'emptyTrips' => $lines->where('is_empty', true)->pluck('trip_id')->unique()->count(),
                'kmTotal' => round($lines->sum('km_total'), 1),
                'kmLoaded' => round($lines->sum('km_loaded'), 1),
                'kmEmpty' => round($lines->sum('km_empty'), 1),
            ],
        ];
    }

    /**
     * Bảng kê chuyến và đánh giá tài xế của kỳ trong một lần tải (cùng nguồn statementRows, chỉ tính một lần).
     * React tải trước ngầm ngay sau khi có số liệu tổng hợp nên mở tab thường đã có sẵn dữ liệu.
     *
     * @return array{statement: list<array<string, mixed>>, evaluation: array{drivers: list<array<string, mixed>>, trips: list<array<string, mixed>>}}
     */
    #[Renderless]
    public function loadDetails(string $period, string $date): array
    {
        $service = app(DriverMonthlyKmReportService::class);
        $rows = $service->statementRows(...DriverMonthlyKmReport::rangeFor(DriverMonthlyKmReport::normalizePeriod($period), DriverMonthlyKmReport::normalizeDate($date)));

        return [
            'statement' => $this->statementPayload($rows),
            'evaluation' => $this->evaluationPayload($service->evaluate($rows)),
        ];
    }

    /**
     * Đánh giá tài xế trong kỳ: thời gian hoàn thành chuyến và thời gian chờ (đóng + hạ), so với chuẩn tuyến.
     *
     * @param  array{trips: list<array<string, mixed>>, drivers: list<array<string, mixed>>}  $evaluation
     * @return array{drivers: list<array<string, mixed>>, trips: list<array<string, mixed>>}
     */
    private function evaluationPayload(array $evaluation): array
    {
        $round = fn (?float $minutes): ?int => $minutes === null ? null : (int) round($minutes);

        return [
            'drivers' => collect($evaluation['drivers'])->map(fn (array $driver): array => [
                'id' => $driver['driver_id'],
                'name' => $driver['driver_name'],
                'trips' => $driver['trip_count'],
                'flagged' => $driver['flagged_count'],
                'completion' => $driver['avg_completion'],
                'completionDiff' => $driver['completion_diff'],
                'completionDiffPercent' => $driver['completion_diff_percent'],
                'completionCompared' => $driver['completion_compared'],
                'wait' => $driver['avg_wait'],
                'loading' => $driver['avg_loading'],
                'unloading' => $driver['avg_unloading'],
                'waitDiff' => $driver['wait_diff'],
                'waitDiffPercent' => $driver['wait_diff_percent'],
                'waitCompared' => $driver['wait_compared'],
            ])->all(),
            'trips' => collect($evaluation['trips'])->map(fn (array $trip): array => [
                'id' => $trip['trip_id'],
                'driverId' => $trip['driver_id'],
                'day' => $trip['started_at']?->toDateString(),
                'time' => $trip['started_at']?->format('H:i'),
                'codes' => $trip['codes'],
                'isEmpty' => $trip['is_empty'],
                'route' => $trip['route'],
                'completion' => $trip['completion'],
                'routeCompletion' => $round($trip['route_completion']),
                'wait' => $trip['wait'],
                'loading' => $trip['loading'],
                'unloading' => $trip['unloading'],
                'routeWait' => $round($trip['route_wait']),
                'routeSamples' => $trip['route_samples'],
                'flag' => $trip['flag'],
            ])->all(),
        ];
    }

    /**
     * Bảng kê chuyến chỉ xem (không có cước/phí) của cả kỳ; React lọc theo lái xe và phân trang.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function statementPayload(Collection $rows): array
    {
        $export = app(TripExcelExportService::class);
        $time = fn (mixed $value): ?string => $value ? Carbon::parse($value)->format('H:i d/m') : null;
        $duration = fn (?int $minutes): string => $minutes === null ? '—' : $export->formatDuration($minutes);

        return $rows
            ->map(fn (array $row): array => [
                'key' => $row['trip_id'].'-'.($row['order_id'] ?? 'trip'),
                'tripId' => $row['trip_id'],
                'driverId' => $row['driver_id'],
                'driver' => $row['driver_name'],
                'date' => filled($row['date']) ? Carbon::parse($row['date'])->format('d/m/Y') : null,
                'code' => $row['code'],
                'type' => $row['service_type'],
                'plate' => $row['plate_number'],
                'started' => $time($row['trip_started_at']),
                'ended' => $time($row['trip_ended_at']),
                'journey' => $row['journey'],
                'times' => [$time($row['time_a']), $time($row['time_b']), $time($row['time_c']), $time($row['time_d'])],
                'durations' => [$duration($row['time_loading']), $duration($row['time_travel']), $duration($row['time_unloading']), $duration($row['time_total_trip'])],
                'customer' => $row['customer'],
                'warehouse' => $row['warehouse'],
                'vehicle' => trim("{$row['vehicle_owner']} · {$row['vehicle_type']}", ' ·'),
                'kmLoaded' => (float) $row['km_loaded'],
                'kmEmpty' => (float) $row['km_empty'],
                'pcs' => (float) $row['pcs'],
                'gw' => (float) $row['gw'],
                'cargoType' => $row['cargo_type'],
                'note' => $row['note'],
            ])
            ->values()
            ->all();
    }
}
