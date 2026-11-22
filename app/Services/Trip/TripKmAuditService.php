<?php

namespace App\Services\Trip;

use App\Enums\TripStatus;
use App\Models\Trip;
use App\Models\TripCheckpoint;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;

/**
 * Rà km chuyến trong một khoảng ngày và tách lỗi theo người nhập: điều phối sửa trên web hay tài xế nhập trên app.
 *
 * Nhật ký sửa chuyến chỉ có từ khi bật activity log, nên chuyến cũ hơn sẽ được tính cho tài xế
 * trừ khi mốc km được tạo trên web (không có GPS / geofence / EUP).
 */
class TripKmAuditService
{
    public const SOURCE_STAFF = 'staff';

    public const SOURCE_DRIVER = 'driver';

    /** Một chuyến vượt mức này gần như chắc chắn là gõ sai km. */
    public const MAX_TRIP_KM = 600;

    /** Chuyến chạy quá mức này là hiếm (tháng 11/2026: 99% chuyến dưới 160 km), cần xem lại. */
    public const HIGH_TRIP_KM = 200;

    /** Km giữa 2 chuyến liên tiếp của một xe lớn hơn mức này thì coi là hở km. */
    public const MAX_GAP_KM = 100;

    /** Chỉ xét các bước nhảy km giữa 2 mốc lớn hơn mức này. */
    public const MIN_JUMP_KM = 30;

    /** 2 mốc bấm cách nhau dưới số phút này mà km nhảy lớn = bấm dồn. */
    public const BATCH_MINUTES = 5;

    /** Tốc độ trung bình giữa 2 mốc vượt mức này là vô lý. */
    public const MAX_SPEED_KMH = 110;

    /**
     * @return list<array{trip: Trip, source: string, issue: string, detail: string, editor: ?string}>
     */
    public function audit(CarbonInterface $from, CarbonInterface $to, ?string $search = null): array
    {
        $trips = $this->trips($from, $to, $search);
        $staffEditors = $this->staffKmEditors($trips->modelKeys());

        $issues = [];

        foreach ($trips as $trip) {
            array_push($issues, ...$this->tripIssues($trip, $staffEditors[$trip->id] ?? null));
            array_push($issues, ...$this->checkpointIssues($trip));
        }

        array_push($issues, ...$this->sequenceIssues($trips, $staffEditors));

        return $issues;
    }

    /**
     * @return Collection<int, Trip>
     */
    private function trips(CarbonInterface $from, CarbonInterface $to, ?string $search): Collection
    {
        return Trip::query()
            ->where('status', '!=', TripStatus::Cancelled)
            ->whereNotNull('started_at')
            ->whereBetween('started_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->when(filled($search), fn (Builder $query) => $query->where(function (Builder $sub) use ($search): void {
                $sub->where('trip_code', 'like', "%{$search}%")
                    ->orWhereHas('vehicle', fn (Builder $v) => $v->where('plate_number', 'like', "%{$search}%"))
                    ->orWhereHas('driver', fn (Builder $d) => $d->where('name', 'like', "%{$search}%"));
            }))
            ->with([
                'vehicle:id,plate_number',
                'driver:id,name',
                'checkpoints' => fn ($query) => $query->chronological()->whereNotNull('km_reading'),
            ])
            ->orderBy('started_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * Tên những người không phải tài xế đã sửa km đầu/cuối của từng chuyến.
     *
     * @param  list<int>  $tripIds
     * @return array<int, string>
     */
    private function staffKmEditors(array $tripIds): array
    {
        if ($tripIds === []) {
            return [];
        }

        return Activity::query()
            ->where('subject_type', (new Trip)->getMorphClass())
            ->whereIn('subject_id', $tripIds)
            ->where('event', 'updated')
            ->where('causer_type', (new User)->getMorphClass())
            ->with('causer')
            ->get()
            ->filter(fn (Activity $activity): bool => $activity->causer instanceof User
                && ! $activity->causer->hasRole('driver')
                && collect(['start_km', 'end_km'])->contains(fn (string $field): bool => array_key_exists($field, $activity->attribute_changes?->get('attributes', []) ?? [])))
            ->groupBy('subject_id')
            ->map(fn (Collection $activities): string => $activities->pluck('causer.name')->unique()->implode(', '))
            ->all();
    }

    /**
     * @return list<array{trip: Trip, source: string, issue: string, detail: string, editor: ?string}>
     */
    private function tripIssues(Trip $trip, ?string $staffEditor): array
    {
        if ($trip->start_km === null || $trip->end_km === null || $trip->status !== TripStatus::Completed) {
            return [];
        }

        $startKm = (float) $trip->start_km;
        $endKm = (float) $trip->end_km;
        $issue = match (true) {
            $endKm < $startKm => 'Km cuối nhỏ hơn km đầu',
            $endKm - $startKm > self::MAX_TRIP_KM => 'Km chuyến quá lớn',
            $endKm - $startKm > self::HIGH_TRIP_KM => 'Km chuyến tăng quá nhiều',
            default => null,
        };

        if ($issue === null) {
            return [];
        }

        $duration = $trip->completed_at !== null
            ? ' trong '.number_format($trip->started_at->diffInMinutes($trip->completed_at) / 60, 1, ',', '.').' giờ'
            : '';

        return [$this->issue($trip, $issue, 'Đầu '.$this->km($startKm).' → cuối '.$this->km($endKm).' = '.$this->signedKm($endKm - $startKm).$duration, $staffEditor)];
    }

    /**
     * @return list<array{trip: Trip, source: string, issue: string, detail: string, editor: ?string}>
     */
    private function checkpointIssues(Trip $trip): array
    {
        // Chuyến không hàng tài xế thường bấm dồn sau khi chạy xong; chỉ soát km đầu/cuối và nối chuyến.
        if ($trip->is_empty_run) {
            return [];
        }

        $issues = [];

        foreach ($trip->checkpoints->sliding(2) as $pair) {
            [$previous, $current] = $pair->values()->all();
            $deltaKm = (float) $current->km_reading - (float) $previous->km_reading;
            $minutes = max(0, $previous->occurred_at->diffInMinutes($current->occurred_at));

            $issue = match (true) {
                $deltaKm < 0 => 'Km giảm giữa 2 mốc',
                $deltaKm <= self::MIN_JUMP_KM => null,
                $minutes < self::BATCH_MINUTES => 'Bấm dồn mốc',
                $deltaKm / ($minutes / 60) > self::MAX_SPEED_KMH => 'Km tăng quá nhanh',
                default => null,
            };

            if ($issue === null) {
                continue;
            }

            $detail = $this->checkpointLabel($previous).' '.$this->km((float) $previous->km_reading)
                .' → '.$this->checkpointLabel($current).' '.$this->km((float) $current->km_reading)
                .': '.$this->signedKm($deltaKm).' trong '.(int) round($minutes).' phút';

            $issues[] = $this->issue($trip, $issue, $detail, $this->isEnteredOnWeb($current) ? 'nhập trên web' : null);
        }

        return $issues;
    }

    /**
     * So km đầu chuyến với km cuối của chuyến liền trước trên cùng xe.
     *
     * @param  Collection<int, Trip>  $trips
     * @param  array<int, string>  $staffEditors
     * @return list<array{trip: Trip, source: string, issue: string, detail: string, editor: ?string}>
     */
    private function sequenceIssues(Collection $trips, array $staffEditors): array
    {
        $issues = [];

        foreach ($trips->whereNotNull('vehicle_id')->groupBy('vehicle_id') as $vehicleTrips) {
            foreach ($vehicleTrips->sliding(2) as $pair) {
                [$previous, $current] = $pair->values()->all();

                if ($previous->end_km === null || $current->start_km === null) {
                    continue;
                }

                $gapKm = (float) $current->start_km - (float) $previous->end_km;
                $issue = match (true) {
                    $gapKm < -0.5 => 'Bắt đầu thấp hơn chuyến trước (tính trùng km)',
                    $gapKm > self::MAX_GAP_KM => 'Hở km với chuyến trước',
                    default => null,
                };

                if ($issue === null) {
                    continue;
                }

                $editor = collect([$staffEditors[$previous->id] ?? null, $staffEditors[$current->id] ?? null])->filter()->unique()->implode(', ');
                $detail = 'Chuyến trước '.$previous->trip_code.' kết thúc '.$this->km((float) $previous->end_km)
                    .', chuyến này bắt đầu '.$this->km((float) $current->start_km).' = '.$this->signedKm($gapKm);

                $issues[] = $this->issue($current, $issue, $detail, $editor !== '' ? $editor : null);
            }
        }

        return $issues;
    }

    /**
     * Mốc tạo trên web (form chuyến / tạo đơn) không có GPS điện thoại, geofence hay vị trí EUP.
     */
    private function isEnteredOnWeb(TripCheckpoint $checkpoint): bool
    {
        return $checkpoint->gps_lat === null && $checkpoint->vehicle_geo_status === null && $checkpoint->eup_recorded_at === null;
    }

    /**
     * @return array{trip: Trip, source: string, issue: string, detail: string, editor: ?string}
     */
    private function issue(Trip $trip, string $issue, string $detail, ?string $staffEditor): array
    {
        return [
            'trip' => $trip,
            'source' => $staffEditor !== null ? self::SOURCE_STAFF : self::SOURCE_DRIVER,
            'issue' => $issue,
            'detail' => $detail,
            'editor' => $staffEditor,
        ];
    }

    private function checkpointLabel(TripCheckpoint $checkpoint): string
    {
        return $checkpoint->checkpoint_type?->getLabel() ?? 'Mốc';
    }

    private function km(float $value): string
    {
        return number_format($value, 0, ',', '.');
    }

    private function signedKm(float $value): string
    {
        return ($value > 0 ? '+' : '').number_format($value, 0, ',', '.').' km';
    }
}
