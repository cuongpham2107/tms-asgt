<?php

namespace App\Services\ActivityLog;

use App\Models\DriverShift;
use App\Models\Order;
use App\Models\OrderCharge;
use App\Models\OrderChargeFee;
use App\Models\OrderDeliveryPoint;
use App\Models\Trip;
use App\Models\TripCheckpoint;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;

/**
 * Resolves and batch-preloads order codes and vehicle plate numbers for activity logs,
 * including logs whose subject or related records were already deleted.
 */
class ActivityLogContextResolver
{
    /** @var array<int, string|null> */
    private array $resolvedOrderCodes = [];

    /** @var array<int, string|null> */
    private array $resolvedPlateNumbers = [];

    /** @var array<int, string|null> */
    private array $vehiclePlates = [];

    /** @var array<int, int|null> */
    private array $tripVehicleIds = [];

    /** @var array<int, array<int, string>> */
    private array $tripOrderCodes = [];

    /** @var array<int, string|null> */
    private array $orderCodes = [];

    /** @var array<int, int|null> */
    private array $orderTripIds = [];

    /** @var array<int, int|null> */
    private array $orderChargeOrderIds = [];

    /** @var array<string, array<string, mixed>> */
    private array $subjectAttributes = [];

    public static function current(): self
    {
        return app(self::class);
    }

    /**
     * @return array<string, mixed>
     */
    public static function extractLoggedAttributes(Activity $record): array
    {
        $changes = $record->attribute_changes instanceof Collection
            ? $record->attribute_changes->toArray()
            : (is_array($record->attribute_changes) ? $record->attribute_changes : []);

        $properties = $record->properties instanceof Collection
            ? $record->properties->toArray()
            : (is_array($record->properties) ? $record->properties : []);

        return array_merge(
            is_array($properties['old'] ?? null) ? $properties['old'] : [],
            is_array($properties['attributes'] ?? null) ? $properties['attributes'] : [],
            is_array($changes['old'] ?? null) ? $changes['old'] : [],
            is_array($changes['attributes'] ?? null) ? $changes['attributes'] : [],
        );
    }

    /**
     * @param  iterable<int, Activity>  $records
     */
    public function warmForRecords(iterable $records): void
    {
        $pending = collect($records)->filter(
            fn (mixed $record): bool => $record instanceof Activity
                && (! array_key_exists((int) $record->id, $this->resolvedOrderCodes)
                    || ! array_key_exists((int) $record->id, $this->resolvedPlateNumbers))
        );

        if ($pending->isEmpty()) {
            return;
        }

        $recordProps = [];
        foreach ($pending as $record) {
            $recordProps[(int) $record->id] = self::extractLoggedAttributes($record);
        }

        $this->preloadDirectEntities($pending, $recordProps);

        foreach ($pending as $record) {
            $id = (int) $record->id;
            $props = $recordProps[$id];

            $this->resolvedOrderCodes[$id] = $this->computeOrderCode($record, $props);
            $this->resolvedPlateNumbers[$id] = $this->computePlateNumber($record, $props);
        }
    }

    public function resolveOrderCode(Activity $record): ?string
    {
        $id = (int) $record->id;
        if ($id > 0 && array_key_exists($id, $this->resolvedOrderCodes)) {
            return $this->resolvedOrderCodes[$id];
        }

        if ($id > 0) {
            $this->warmForRecords([$record]);

            return $this->resolvedOrderCodes[$id];
        }

        return $this->computeOrderCode($record, self::extractLoggedAttributes($record));
    }

    public function resolvePlateNumber(Activity $record): ?string
    {
        $id = (int) $record->id;
        if ($id > 0 && array_key_exists($id, $this->resolvedPlateNumbers)) {
            return $this->resolvedPlateNumbers[$id];
        }

        if ($id > 0) {
            $this->warmForRecords([$record]);

            return $this->resolvedPlateNumbers[$id];
        }

        return $this->computePlateNumber($record, self::extractLoggedAttributes($record));
    }

    /**
     * @return array{orderIds: array<int, int>, tripIds: array<int, int>, trimmed: string}
     */
    public static function resolveOrderAndTripIdsForOrderSearch(string $rawSearch): array
    {
        $trimmed = trim($rawSearch);
        if ($trimmed === '') {
            return ['orderIds' => [], 'tripIds' => [], 'trimmed' => ''];
        }

        $orderIds = Order::withoutGlobalScopes()
            ->whereLike('order_code', "%{$trimmed}%", caseSensitive: false)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $loggedOrderActivities = Activity::query()
            ->where('subject_type', Order::class)
            ->where(function (Builder $q) use ($trimmed): void {
                $q->whereLike('attribute_changes', "%{$trimmed}%", caseSensitive: false)
                    ->orWhereLike('properties', "%{$trimmed}%", caseSensitive: false);
            })
            ->get(['subject_id', 'attribute_changes', 'properties']);

        $tripIds = [];
        foreach ($loggedOrderActivities as $activity) {
            $attrs = self::extractLoggedAttributes($activity);
            $code = isset($attrs['order_code']) ? (string) $attrs['order_code'] : null;
            if ($code === null || mb_stripos($code, $trimmed) === false) {
                continue;
            }
            if ($activity->subject_id) {
                $orderIds[] = (int) $activity->subject_id;
            }
            foreach (self::extractTripIdsFromActivity($activity) as $tid) {
                $tripIds[] = $tid;
            }
        }

        $orderIds = array_values(array_unique(array_filter($orderIds)));

        if ($orderIds !== []) {
            $liveTripIds = Order::withoutGlobalScopes()
                ->whereIn('id', $orderIds)
                ->whereNotNull('trip_id')
                ->pluck('trip_id')
                ->map(fn ($id): int => (int) $id)
                ->all();
            $tripIds = [...$tripIds, ...$liveTripIds];

            if (count($orderIds) <= 100) {
                $orderTripLogs = Activity::query()
                    ->where('subject_type', Order::class)
                    ->whereIn('subject_id', $orderIds)
                    ->get(['attribute_changes', 'properties']);
                foreach ($orderTripLogs as $log) {
                    foreach (self::extractTripIdsFromActivity($log) as $tid) {
                        $tripIds[] = $tid;
                    }
                }
            }
        }

        return [
            'orderIds' => $orderIds,
            'tripIds' => array_values(array_unique(array_filter($tripIds))),
            'trimmed' => $trimmed,
        ];
    }

    /**
     * @return array{vehicleIds: array<int, int>, tripIds: array<int, int>, orderIds: array<int, int>, trimmed: string}
     */
    public static function resolveEntityIdsForPlateSearch(string $rawSearch): array
    {
        $trimmed = trim($rawSearch);
        if ($trimmed === '') {
            return ['vehicleIds' => [], 'tripIds' => [], 'orderIds' => [], 'trimmed' => ''];
        }

        $vehicleIds = Vehicle::withoutGlobalScopes()
            ->whereLike('plate_number', "%{$trimmed}%", caseSensitive: false)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if (mb_strlen($trimmed) >= 3) {
            $loggedVehicles = Activity::query()
                ->where('subject_type', Vehicle::class)
                ->whereLike('attribute_changes', "%{$trimmed}%", caseSensitive: false)
                ->get(['subject_id', 'attribute_changes', 'properties']);

            foreach ($loggedVehicles as $activity) {
                $attrs = self::extractLoggedAttributes($activity);
                $plate = isset($attrs['plate_number']) ? (string) $attrs['plate_number'] : null;
                if ($plate !== null && mb_stripos($plate, $trimmed) !== false && $activity->subject_id) {
                    $vehicleIds[] = (int) $activity->subject_id;
                }
            }
        }

        $vehicleIds = array_values(array_unique(array_filter($vehicleIds)));
        if ($vehicleIds === []) {
            return ['vehicleIds' => [], 'tripIds' => [], 'orderIds' => [], 'trimmed' => $trimmed];
        }

        $tripIds = Trip::withoutGlobalScopes()
            ->whereIn('vehicle_id', $vehicleIds)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if (count($vehicleIds) <= 25 && mb_strlen($trimmed) >= 3) {
            $loggedTrips = Activity::query()
                ->where('subject_type', Trip::class)
                ->where(function (Builder $q) use ($vehicleIds): void {
                    foreach ($vehicleIds as $vid) {
                        $q->orWhereLike('attribute_changes', '%"vehicle_id":'.$vid.',%')
                            ->orWhereLike('attribute_changes', '%"vehicle_id":'.$vid.'}%');
                    }
                })
                ->get(['subject_id', 'attribute_changes', 'properties']);

            foreach ($loggedTrips as $activity) {
                if ($activity->subject_id && array_intersect(self::extractVehicleIdsFromActivity($activity), $vehicleIds) !== []) {
                    $tripIds[] = (int) $activity->subject_id;
                }
            }
        }

        $tripIds = array_values(array_unique(array_filter($tripIds)));

        $orderIds = [];
        if ($tripIds !== []) {
            $orderIds = Order::withoutGlobalScopes()
                ->whereIn('trip_id', $tripIds)
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            if (count($tripIds) <= 100 && mb_strlen($trimmed) >= 3) {
                $loggedOrders = Activity::query()
                    ->where('subject_type', Order::class)
                    ->where(function (Builder $q) use ($tripIds): void {
                        foreach ($tripIds as $tid) {
                            $q->orWhereLike('attribute_changes', '%"trip_id":'.$tid.',%')
                                ->orWhereLike('attribute_changes', '%"trip_id":'.$tid.'}%');
                        }
                    })
                    ->get(['subject_id', 'attribute_changes', 'properties']);

                foreach ($loggedOrders as $activity) {
                    if ($activity->subject_id && array_intersect(self::extractTripIdsFromActivity($activity), $tripIds) !== []) {
                        $orderIds[] = (int) $activity->subject_id;
                    }
                }
            }
        }

        return [
            'vehicleIds' => $vehicleIds,
            'tripIds' => $tripIds,
            'orderIds' => array_values(array_unique(array_filter($orderIds))),
            'trimmed' => $trimmed,
        ];
    }

    public static function applyOrderCodeConditions(Builder $q, string $trimmed, array $orderIds, array $tripIds): void
    {
        if ($orderIds === [] && $tripIds === []) {
            if ($trimmed !== '') {
                $q->orWhere(function (Builder $sq) use ($trimmed): void {
                    $sq->where('subject_type', Order::class)
                        ->whereLike('attribute_changes', "%{$trimmed}%", caseSensitive: false);
                });
            }

            return;
        }

        if ($orderIds !== []) {
            $q->orWhere(function (Builder $sq) use ($orderIds): void {
                $sq->where('subject_type', Order::class)
                    ->whereIn('subject_id', $orderIds);
            })->orWhere(function (Builder $sq) use ($orderIds): void {
                $sq->where('subject_type', OrderDeliveryPoint::class)
                    ->whereIn(
                        'subject_id',
                        OrderDeliveryPoint::withoutGlobalScopes()
                            ->whereIn('order_id', $orderIds)
                            ->select('id')
                    );
            })->orWhere(function (Builder $sq) use ($orderIds): void {
                $sq->where('subject_type', OrderCharge::class)
                    ->whereIn(
                        'subject_id',
                        OrderCharge::withoutGlobalScopes()
                            ->whereIn('order_id', $orderIds)
                            ->select('id')
                    );
            })->orWhere(function (Builder $sq) use ($orderIds): void {
                $sq->where('subject_type', OrderChargeFee::class)
                    ->whereIn(
                        'subject_id',
                        OrderChargeFee::withoutGlobalScopes()
                            ->whereIn(
                                'order_charge_id',
                                OrderCharge::withoutGlobalScopes()
                                    ->whereIn('order_id', $orderIds)
                                    ->select('id')
                            )
                            ->select('id')
                    );
            });
        }

        if ($tripIds !== []) {
            $q->orWhere(function (Builder $sq) use ($tripIds): void {
                $sq->where('subject_type', Trip::class)
                    ->whereIn('subject_id', $tripIds);
            });
        }

        $q->orWhere(function (Builder $sq) use ($orderIds, $tripIds): void {
            $sq->where('subject_type', TripCheckpoint::class)
                ->whereIn(
                    'subject_id',
                    TripCheckpoint::withoutGlobalScopes()
                        ->where(function (Builder $cq) use ($orderIds, $tripIds): void {
                            if ($orderIds !== []) {
                                $cq->whereIn('order_id', $orderIds);
                            }
                            if ($tripIds !== []) {
                                $cq->orWhereIn('trip_id', $tripIds);
                            }
                        })
                        ->select('id')
                );
        });
    }

    public static function applyPlateNumberConditions(Builder $q, array $vehicleIds, array $tripIds, array $orderIds): void
    {
        if ($vehicleIds === []) {
            return;
        }

        $q->orWhere(function (Builder $sq) use ($vehicleIds): void {
            $sq->where('subject_type', Vehicle::class)
                ->whereIn('subject_id', $vehicleIds);
        })->orWhere(function (Builder $sq) use ($vehicleIds): void {
            $sq->where('subject_type', DriverShift::class)
                ->whereIn(
                    'subject_id',
                    DriverShift::withoutGlobalScopes()
                        ->whereIn('vehicle_id', $vehicleIds)
                        ->select('id')
                );
        });

        if ($tripIds !== []) {
            $q->orWhere(function (Builder $sq) use ($tripIds): void {
                $sq->where('subject_type', Trip::class)
                    ->whereIn('subject_id', $tripIds);
            });
        }

        if ($orderIds !== []) {
            $q->orWhere(function (Builder $sq) use ($orderIds): void {
                $sq->where('subject_type', Order::class)
                    ->whereIn('subject_id', $orderIds);
            })->orWhere(function (Builder $sq) use ($orderIds): void {
                $sq->where('subject_type', OrderDeliveryPoint::class)
                    ->whereIn(
                        'subject_id',
                        OrderDeliveryPoint::withoutGlobalScopes()
                            ->whereIn('order_id', $orderIds)
                            ->select('id')
                    );
            })->orWhere(function (Builder $sq) use ($orderIds): void {
                $sq->where('subject_type', OrderCharge::class)
                    ->whereIn(
                        'subject_id',
                        OrderCharge::withoutGlobalScopes()
                            ->whereIn('order_id', $orderIds)
                            ->select('id')
                    );
            })->orWhere(function (Builder $sq) use ($orderIds): void {
                $sq->where('subject_type', OrderChargeFee::class)
                    ->whereIn(
                        'subject_id',
                        OrderChargeFee::withoutGlobalScopes()
                            ->whereIn(
                                'order_charge_id',
                                OrderCharge::withoutGlobalScopes()
                                    ->whereIn('order_id', $orderIds)
                                    ->select('id')
                            )
                            ->select('id')
                    );
            });
        }

        $q->orWhere(function (Builder $sq) use ($vehicleIds, $tripIds, $orderIds): void {
            $sq->where('subject_type', TripCheckpoint::class)
                ->whereIn(
                    'subject_id',
                    TripCheckpoint::withoutGlobalScopes()
                        ->where(function (Builder $cq) use ($vehicleIds, $tripIds, $orderIds): void {
                            $cq->whereIn('vehicle_id', $vehicleIds);
                            if ($tripIds !== []) {
                                $cq->orWhereIn('trip_id', $tripIds);
                            }
                            if ($orderIds !== []) {
                                $cq->orWhereIn('order_id', $orderIds);
                            }
                        })
                        ->select('id')
                );
        });
    }

    /**
     * @param  Collection<int, Activity>  $records
     * @param  array<int, array<string, mixed>>  $recordProps
     */
    private function preloadDirectEntities(Collection $records, array $recordProps): void
    {
        $missingSubjectIds = [];
        $orderChargeIds = [];
        $orderIds = [];
        $tripIdsNeedingOrder = [];
        $tripIdsNeedingVehicle = [];
        $vehicleIds = [];

        foreach ($records as $record) {
            $id = (int) $record->id;
            $props = $recordProps[$id];
            $subject = $record->subject;
            $type = $record->subject_type;
            $subjectId = $record->subject_id ? (int) $record->subject_id : null;

            if ($subject === null && $subjectId !== null && $type !== null) {
                $needsHistoricalAttrs = match ($type) {
                    Order::class => empty($props['order_code']) || empty($props['trip_id']),
                    Trip::class => empty($props['vehicle_id']),
                    TripCheckpoint::class => empty($props['order_id']) && empty($props['trip_id']),
                    OrderDeliveryPoint::class, OrderCharge::class => empty($props['order_id']),
                    OrderChargeFee::class => empty($props['order_charge_id']),
                    DriverShift::class => empty($props['vehicle_id']),
                    Vehicle::class => empty($props['plate_number']),
                    default => false,
                };
                if ($needsHistoricalAttrs) {
                    $missingSubjectIds[$type][] = $subjectId;
                }
            }

            if ($type === Trip::class) {
                if ($subject instanceof Trip) {
                    if ($subject->orders->isEmpty()) {
                        $tripIdsNeedingOrder[] = (int) $subject->id;
                    }
                    if (! $subject->vehicle && $subject->vehicle_id) {
                        $vehicleIds[] = (int) $subject->vehicle_id;
                    }
                } elseif ($subjectId !== null) {
                    $tripIdsNeedingOrder[] = $subjectId;
                }
            }

            if ($subject instanceof TripCheckpoint && ! $subject->order && $subject->trip && $subject->trip->orders->isEmpty()) {
                $tripIdsNeedingOrder[] = (int) $subject->trip->id;
            }
        }

        $this->fetchHistoricalSubjectAttributes($missingSubjectIds);

        foreach ($records as $record) {
            $id = (int) $record->id;
            $merged = $this->mergedProps($record, $recordProps[$id]);
            $subject = $record->subject;

            if ($subject === null) {
                if (! empty($merged['order_charge_id'])) {
                    $orderChargeIds[] = (int) $merged['order_charge_id'];
                }
                if (! empty($merged['order_id'])) {
                    $orderIds[] = (int) $merged['order_id'];
                }
                if (! empty($merged['trip_id'])) {
                    $tripIdsNeedingVehicle[] = (int) $merged['trip_id'];
                    if ($record->subject_type === TripCheckpoint::class && empty($merged['order_id'])) {
                        $tripIdsNeedingOrder[] = (int) $merged['trip_id'];
                    }
                }
                if (! empty($merged['vehicle_id'])) {
                    $vehicleIds[] = (int) $merged['vehicle_id'];
                }
            } elseif ($subject instanceof Order) {
                if ($subject->trip && ! $subject->trip->vehicle && $subject->trip->vehicle_id) {
                    $vehicleIds[] = (int) $subject->trip->vehicle_id;
                } elseif (! $subject->trip && $subject->trip_id) {
                    $tripIdsNeedingVehicle[] = (int) $subject->trip_id;
                }
            } elseif ($subject instanceof OrderDeliveryPoint || $subject instanceof OrderCharge) {
                if ($subject->order && ! $subject->order->trip && $subject->order->trip_id) {
                    $tripIdsNeedingVehicle[] = (int) $subject->order->trip_id;
                }
            } elseif ($subject instanceof OrderChargeFee) {
                $order = $subject->orderCharge?->order;
                if ($order && ! $order->trip && $order->trip_id) {
                    $tripIdsNeedingVehicle[] = (int) $order->trip_id;
                }
            } elseif ($subject instanceof TripCheckpoint) {
                if ($subject->vehicle_id && (! $subject->trip || ! $subject->trip->vehicle)) {
                    $vehicleIds[] = (int) $subject->vehicle_id;
                }
                if ($subject->trip && ! $subject->trip->vehicle && $subject->trip->vehicle_id) {
                    $vehicleIds[] = (int) $subject->trip->vehicle_id;
                }
            }
        }

        $this->fetchOrderCharges($orderChargeIds, $orderIds);
        $this->fetchTripOrderCodes($tripIdsNeedingOrder, $orderIds);
        $this->fetchOrders($orderIds, $tripIdsNeedingVehicle);
        $this->fetchTrips($tripIdsNeedingVehicle, $vehicleIds);
        $this->fetchVehicles($vehicleIds);
    }

    /**
     * @param  array<string, array<int, int>>  $byType
     */
    private function fetchHistoricalSubjectAttributes(array $byType): void
    {
        $filtered = [];
        foreach ($byType as $type => $ids) {
            $missing = array_values(array_filter(
                array_unique($ids),
                fn (int $id): bool => ! array_key_exists("{$type}:{$id}", $this->subjectAttributes)
            ));
            if ($missing !== []) {
                $filtered[$type] = $missing;
            }
        }

        if ($filtered === []) {
            return;
        }

        $logs = Activity::query()
            ->where(function (Builder $q) use ($filtered): void {
                foreach ($filtered as $type => $ids) {
                    $q->orWhere(function (Builder $sq) use ($type, $ids): void {
                        $sq->where('subject_type', $type)
                            ->whereIn('subject_id', $ids);
                    });
                }
            })
            ->orderBy('id')
            ->get(['subject_type', 'subject_id', 'attribute_changes', 'properties']);

        $aggregated = [];
        foreach ($logs as $log) {
            $key = "{$log->subject_type}:{$log->subject_id}";
            $aggregated[$key] = array_merge($aggregated[$key] ?? [], self::extractLoggedAttributes($log));
        }

        foreach ($filtered as $type => $ids) {
            foreach ($ids as $id) {
                $key = "{$type}:{$id}";
                $this->subjectAttributes[$key] = $aggregated[$key] ?? [];
            }
        }
    }

    /**
     * @param  array<int, int>  $orderChargeIds
     * @param  array<int, int>  $orderIds
     */
    private function fetchOrderCharges(array $orderChargeIds, array &$orderIds): void
    {
        $missing = array_values(array_filter(
            array_unique($orderChargeIds),
            fn (int $id): bool => ! array_key_exists($id, $this->orderChargeOrderIds)
        ));

        if ($missing === []) {
            return;
        }

        $live = OrderCharge::withoutGlobalScopes()
            ->whereIn('id', $missing)
            ->pluck('order_id', 'id')
            ->all();

        $stillMissing = [];
        foreach ($missing as $id) {
            if (array_key_exists($id, $live)) {
                $oid = $live[$id] ? (int) $live[$id] : null;
                $this->orderChargeOrderIds[$id] = $oid;
                if ($oid !== null) {
                    $orderIds[] = $oid;
                }
            } else {
                $stillMissing[] = $id;
            }
        }

        if ($stillMissing !== []) {
            $this->fetchHistoricalSubjectAttributes([OrderCharge::class => $stillMissing]);
            foreach ($stillMissing as $id) {
                $attrs = $this->subjectAttributes[OrderCharge::class.':'.$id] ?? [];
                $oid = ! empty($attrs['order_id']) ? (int) $attrs['order_id'] : null;
                $this->orderChargeOrderIds[$id] = $oid;
                if ($oid !== null) {
                    $orderIds[] = $oid;
                }
            }
        }
    }

    /**
     * @param  array<int, int>  $tripIds
     * @param  array<int, int>  $orderIds
     */
    private function fetchTripOrderCodes(array $tripIds, array &$orderIds): void
    {
        $missing = array_values(array_filter(
            array_unique($tripIds),
            fn (int $id): bool => ! array_key_exists($id, $this->tripOrderCodes)
        ));

        if ($missing === []) {
            return;
        }

        $liveOrders = Order::withoutGlobalScopes()
            ->whereIn('trip_id', $missing)
            ->get(['id', 'trip_id', 'order_code']);

        foreach ($missing as $tid) {
            $this->tripOrderCodes[$tid] = [];
        }

        foreach ($liveOrders as $order) {
            $tid = (int) $order->trip_id;
            if ($order->order_code) {
                $this->tripOrderCodes[$tid][] = $order->order_code;
            }
            $this->orderCodes[(int) $order->id] = $order->order_code ?: null;
        }

        $remainingTrips = array_values(array_filter(
            $missing,
            fn (int $tid): bool => $this->tripOrderCodes[$tid] === []
        ));

        if ($remainingTrips === []) {
            return;
        }

        $orderLogs = Activity::query()
            ->where('subject_type', Order::class)
            ->where(function (Builder $q) use ($remainingTrips): void {
                foreach ($remainingTrips as $tid) {
                    $q->orWhereLike('attribute_changes', '%"trip_id":'.$tid.',%')
                        ->orWhereLike('attribute_changes', '%"trip_id":'.$tid.'}%');
                }
            })
            ->orderByDesc('id')
            ->get(['subject_id', 'attribute_changes', 'properties']);

        $tripOrderMap = [];
        foreach ($orderLogs as $log) {
            $attrs = self::extractLoggedAttributes($log);
            $matchedTripIds = array_intersect(self::extractTripIdsFromActivity($log), $remainingTrips);
            if ($matchedTripIds === []) {
                continue;
            }

            foreach ($matchedTripIds as $tid) {
                if (! empty($attrs['order_code'])) {
                    $this->tripOrderCodes[$tid][] = (string) $attrs['order_code'];
                } elseif ($log->subject_id) {
                    $oid = (int) $log->subject_id;
                    $tripOrderMap[$tid][] = $oid;
                    $orderIds[] = $oid;
                }
            }
        }

        $this->fetchOrders($orderIds);

        foreach ($tripOrderMap as $tid => $oids) {
            foreach ($oids as $oid) {
                if (! empty($this->orderCodes[$oid])) {
                    $this->tripOrderCodes[$tid][] = $this->orderCodes[$oid];
                }
            }
        }

        $stillEmptyTrips = array_values(array_filter(
            $remainingTrips,
            fn (int $tid): bool => $this->tripOrderCodes[$tid] === []
        ));

        if ($stillEmptyTrips !== []) {
            $cpOrders = TripCheckpoint::withoutGlobalScopes()
                ->whereIn('trip_id', $stillEmptyTrips)
                ->whereNotNull('order_id')
                ->get(['trip_id', 'order_id']);

            $cpOrderIds = $cpOrders->pluck('order_id')->map(fn ($id): int => (int) $id)->unique()->all();
            if ($cpOrderIds !== []) {
                $this->fetchOrders($cpOrderIds, $ignoredTrips);
                foreach ($cpOrders as $cp) {
                    $code = $this->orderCodes[(int) $cp->order_id] ?? null;
                    if ($code) {
                        $this->tripOrderCodes[(int) $cp->trip_id][] = $code;
                    }
                }
            }
        }

        foreach ($missing as $tid) {
            $this->tripOrderCodes[$tid] = array_values(array_unique(array_filter($this->tripOrderCodes[$tid])));
        }
    }

    /**
     * @param  array<int, int>  $orderIds
     * @param  array<int, int>|null  $tripIds
     */
    private function fetchOrders(array $orderIds, ?array &$tripIds = null): void
    {
        $missing = array_values(array_filter(
            array_unique($orderIds),
            fn (int $id): bool => ! array_key_exists($id, $this->orderCodes)
        ));

        if ($missing === []) {
            return;
        }

        $live = Order::withoutGlobalScopes()
            ->whereIn('id', $missing)
            ->get(['id', 'order_code', 'trip_id'])
            ->keyBy('id');

        $stillMissing = [];
        foreach ($missing as $id) {
            $order = $live->get($id);
            if ($order) {
                $this->orderCodes[$id] = $order->order_code ?: null;
                $tid = $order->trip_id ? (int) $order->trip_id : null;
                $this->orderTripIds[$id] = $tid;
                if ($tid !== null && is_array($tripIds)) {
                    $tripIds[] = $tid;
                }
            } else {
                $stillMissing[] = $id;
            }
        }

        if ($stillMissing !== []) {
            $this->fetchHistoricalSubjectAttributes([Order::class => $stillMissing]);
            foreach ($stillMissing as $id) {
                $attrs = $this->subjectAttributes[Order::class.':'.$id] ?? [];
                $this->orderCodes[$id] = ! empty($attrs['order_code']) ? (string) $attrs['order_code'] : null;
                $tid = ! empty($attrs['trip_id']) ? (int) $attrs['trip_id'] : null;
                $this->orderTripIds[$id] = $tid;
                if ($tid !== null && is_array($tripIds)) {
                    $tripIds[] = $tid;
                }
            }
        }
    }

    /**
     * @param  array<int, int>  $tripIds
     * @param  array<int, int>  $vehicleIds
     */
    private function fetchTrips(array $tripIds, array &$vehicleIds): void
    {
        $missing = array_values(array_filter(
            array_unique($tripIds),
            fn (int $id): bool => ! array_key_exists($id, $this->tripVehicleIds)
        ));

        if ($missing === []) {
            return;
        }

        $live = Trip::withoutGlobalScopes()
            ->whereIn('id', $missing)
            ->pluck('vehicle_id', 'id')
            ->all();

        $stillMissing = [];
        foreach ($missing as $id) {
            if (array_key_exists($id, $live)) {
                $vid = $live[$id] ? (int) $live[$id] : null;
                $this->tripVehicleIds[$id] = $vid;
                if ($vid !== null) {
                    $vehicleIds[] = $vid;
                }
            } else {
                $stillMissing[] = $id;
            }
        }

        if ($stillMissing !== []) {
            $this->fetchHistoricalSubjectAttributes([Trip::class => $stillMissing]);
            foreach ($stillMissing as $id) {
                $attrs = $this->subjectAttributes[Trip::class.':'.$id] ?? [];
                $vid = ! empty($attrs['vehicle_id']) ? (int) $attrs['vehicle_id'] : null;
                $this->tripVehicleIds[$id] = $vid;
                if ($vid !== null) {
                    $vehicleIds[] = $vid;
                }
            }
        }
    }

    /**
     * @param  array<int, int>  $vehicleIds
     */
    private function fetchVehicles(array $vehicleIds): void
    {
        $missing = array_values(array_filter(
            array_unique($vehicleIds),
            fn (int $id): bool => ! array_key_exists($id, $this->vehiclePlates)
        ));

        if ($missing === []) {
            return;
        }

        $live = Vehicle::withoutGlobalScopes()
            ->whereIn('id', $missing)
            ->pluck('plate_number', 'id')
            ->all();

        $stillMissing = [];
        foreach ($missing as $id) {
            if (array_key_exists($id, $live)) {
                $this->vehiclePlates[$id] = $live[$id] ?: null;
            } else {
                $stillMissing[] = $id;
            }
        }

        if ($stillMissing !== []) {
            $this->fetchHistoricalSubjectAttributes([Vehicle::class => $stillMissing]);
            foreach ($stillMissing as $id) {
                $attrs = $this->subjectAttributes[Vehicle::class.':'.$id] ?? [];
                $this->vehiclePlates[$id] = ! empty($attrs['plate_number']) ? (string) $attrs['plate_number'] : null;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $props
     * @return array<string, mixed>
     */
    private function mergedProps(Activity $record, array $props): array
    {
        if (! $record->subject_type || ! $record->subject_id) {
            return $props;
        }

        $historical = $this->subjectAttributes["{$record->subject_type}:{$record->subject_id}"] ?? [];

        foreach ($historical as $key => $value) {
            if ((! array_key_exists($key, $props) || $props[$key] === null || $props[$key] === '') && $value !== null && $value !== '') {
                $props[$key] = $value;
            }
        }

        return $props;
    }

    /**
     * @param  array<string, mixed>  $props
     */
    private function computeOrderCode(Activity $record, array $props): ?string
    {
        $subject = $record->subject;
        $merged = $this->mergedProps($record, $props);

        if ($subject instanceof Order) {
            return $subject->order_code ?: null;
        }

        if ($subject instanceof Trip) {
            $codes = $subject->orders->pluck('order_code')->filter()->unique()->values()->all();
            if ($codes !== []) {
                return implode(', ', $codes);
            }

            $cached = $this->tripOrderCodes[(int) $subject->id] ?? [];

            return $cached !== [] ? implode(', ', $cached) : null;
        }

        if ($subject instanceof TripCheckpoint) {
            if ($subject->order?->order_code) {
                return $subject->order->order_code;
            }
            $tripCodes = $subject->trip?->orders?->pluck('order_code')->filter()->unique()->values()->all() ?? [];
            if ($tripCodes !== []) {
                return implode(', ', $tripCodes);
            }
            if ($subject->trip_id) {
                $cached = $this->tripOrderCodes[(int) $subject->trip_id] ?? [];
                if ($cached !== []) {
                    return implode(', ', $cached);
                }
            }

            return null;
        }

        if ($subject instanceof OrderDeliveryPoint || $subject instanceof OrderCharge) {
            return $subject->order?->order_code ?: null;
        }

        if ($subject instanceof OrderChargeFee) {
            return $subject->orderCharge?->order?->order_code ?: null;
        }

        if (! empty($merged['order_code'])) {
            return (string) $merged['order_code'];
        }

        if (! empty($merged['order_id'])) {
            $oid = (int) $merged['order_id'];
            if (! empty($this->orderCodes[$oid])) {
                return $this->orderCodes[$oid];
            }
        }

        if ($record->subject_type === OrderChargeFee::class && ! empty($merged['order_charge_id'])) {
            $oid = $this->orderChargeOrderIds[(int) $merged['order_charge_id']] ?? null;
            if ($oid !== null && ! empty($this->orderCodes[$oid])) {
                return $this->orderCodes[$oid];
            }
        }

        if ($record->subject_type === Trip::class && $record->subject_id) {
            $cached = $this->tripOrderCodes[(int) $record->subject_id] ?? [];
            if ($cached !== []) {
                return implode(', ', $cached);
            }
        }

        if (! empty($merged['trip_id'])) {
            $cached = $this->tripOrderCodes[(int) $merged['trip_id']] ?? [];
            if ($cached !== []) {
                return implode(', ', $cached);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $props
     */
    private function computePlateNumber(Activity $record, array $props): ?string
    {
        $subject = $record->subject;
        $merged = $this->mergedProps($record, $props);

        if ($subject instanceof Vehicle) {
            return $subject->plate_number ?: null;
        }

        if ($subject instanceof Trip) {
            if ($subject->vehicle?->plate_number) {
                return $subject->vehicle->plate_number;
            }
            if ($subject->vehicle_id && ! empty($this->vehiclePlates[(int) $subject->vehicle_id])) {
                return $this->vehiclePlates[(int) $subject->vehicle_id];
            }
        }

        if ($subject instanceof Order) {
            if ($subject->trip?->vehicle?->plate_number) {
                return $subject->trip->vehicle->plate_number;
            }
            if ($subject->trip_id) {
                return $this->plateFromTripId((int) $subject->trip_id);
            }
        }

        if ($subject instanceof TripCheckpoint) {
            if ($subject->trip?->vehicle?->plate_number) {
                return $subject->trip->vehicle->plate_number;
            }
            if ($subject->order?->trip?->vehicle?->plate_number) {
                return $subject->order->trip->vehicle->plate_number;
            }
            if ($subject->vehicle_id && ! empty($this->vehiclePlates[(int) $subject->vehicle_id])) {
                return $this->vehiclePlates[(int) $subject->vehicle_id];
            }
            if ($subject->trip_id) {
                return $this->plateFromTripId((int) $subject->trip_id);
            }
        }

        if ($subject instanceof DriverShift) {
            if ($subject->vehicle?->plate_number) {
                return $subject->vehicle->plate_number;
            }
            if ($subject->vehicle_id && ! empty($this->vehiclePlates[(int) $subject->vehicle_id])) {
                return $this->vehiclePlates[(int) $subject->vehicle_id];
            }
        }

        if ($subject instanceof OrderDeliveryPoint || $subject instanceof OrderCharge) {
            if ($subject->order?->trip?->vehicle?->plate_number) {
                return $subject->order->trip->vehicle->plate_number;
            }
            if ($subject->order?->trip_id) {
                return $this->plateFromTripId((int) $subject->order->trip_id);
            }
        }

        if ($subject instanceof OrderChargeFee) {
            $order = $subject->orderCharge?->order;
            if ($order?->trip?->vehicle?->plate_number) {
                return $order->trip->vehicle->plate_number;
            }
            if ($order?->trip_id) {
                return $this->plateFromTripId((int) $order->trip_id);
            }
        }

        if (! empty($merged['plate_number'])) {
            return (string) $merged['plate_number'];
        }

        if (! empty($merged['vehicle_id'])) {
            $vid = (int) $merged['vehicle_id'];
            if (! empty($this->vehiclePlates[$vid])) {
                return $this->vehiclePlates[$vid];
            }
        }

        if (! empty($merged['trip_id'])) {
            $plate = $this->plateFromTripId((int) $merged['trip_id']);
            if ($plate !== null) {
                return $plate;
            }
        }

        if (! empty($merged['order_id'])) {
            $tid = $this->orderTripIds[(int) $merged['order_id']] ?? null;
            if ($tid !== null) {
                return $this->plateFromTripId($tid);
            }
        }

        if ($record->subject_type === OrderChargeFee::class && ! empty($merged['order_charge_id'])) {
            $oid = $this->orderChargeOrderIds[(int) $merged['order_charge_id']] ?? null;
            if ($oid !== null) {
                $tid = $this->orderTripIds[$oid] ?? null;
                if ($tid !== null) {
                    return $this->plateFromTripId($tid);
                }
            }
        }

        return null;
    }

    private function plateFromTripId(int $tripId): ?string
    {
        $vid = $this->tripVehicleIds[$tripId] ?? null;
        if ($vid === null) {
            return null;
        }

        return $this->vehiclePlates[$vid] ?? null;
    }

    /**
     * @return array<int, int>
     */
    private static function extractTripIdsFromActivity(Activity $activity): array
    {
        $ids = [];
        $changes = $activity->attribute_changes instanceof Collection
            ? $activity->attribute_changes->toArray()
            : (is_array($activity->attribute_changes) ? $activity->attribute_changes : []);
        $props = $activity->properties instanceof Collection
            ? $activity->properties->toArray()
            : (is_array($activity->properties) ? $activity->properties : []);

        foreach ([$changes['attributes'] ?? null, $changes['old'] ?? null, $props['attributes'] ?? null, $props['old'] ?? null] as $bucket) {
            if (is_array($bucket) && ! empty($bucket['trip_id'])) {
                $ids[] = (int) $bucket['trip_id'];
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return array<int, int>
     */
    private static function extractVehicleIdsFromActivity(Activity $activity): array
    {
        $ids = [];
        $changes = $activity->attribute_changes instanceof Collection
            ? $activity->attribute_changes->toArray()
            : (is_array($activity->attribute_changes) ? $activity->attribute_changes : []);
        $props = $activity->properties instanceof Collection
            ? $activity->properties->toArray()
            : (is_array($activity->properties) ? $activity->properties : []);

        foreach ([$changes['attributes'] ?? null, $changes['old'] ?? null, $props['attributes'] ?? null, $props['old'] ?? null] as $bucket) {
            if (is_array($bucket) && ! empty($bucket['vehicle_id'])) {
                $ids[] = (int) $bucket['vehicle_id'];
            }
        }

        return array_values(array_unique($ids));
    }
}
