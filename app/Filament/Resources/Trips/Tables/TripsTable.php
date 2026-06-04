<?php

namespace App\Filament\Resources\Trips\Tables;

use App\Filament\BaseTable;
use App\Models\Order;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TripsTable extends BaseTable
{
    public static function configure(Table $table): Table
    {
        return parent::applyDefaults($table)
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with([
                    'customer',
                    'deliveryPoints.location',
                    'driver.driverShifts',
                    'orderCategory',
                    'pickupLocation',
                    'tripCheckpoints.deliveryPoint.location',
                    'driverSwaps.fromDriver',
                    'driverSwaps.toDriver',
                    'vehicle',
                ])
                ->where('status', '!=', 'draft')
            )
            ->columns([
                // 1. Ngày
                TextColumn::make('planned_loading_at')
                    ->label('Ngày')
                    ->dateTime('d/m/Y')
                    ->sortable(),

                // 2. Địa điểm
                TextColumn::make('orderCategory.code')
                    ->label('Địa điểm')
                    ->badge()
                    ->color('warning')
                    ->sortable(),

                // 3. BSX
                TextColumn::make('bsx')
                    ->label('BSX')
                    ->state(fn (Order $record): string => self::renderBsx($record)),

                // 4. STT
                TextColumn::make('stt')
                    ->label('STT')
                    ->state(fn (): string => 'CH'),

                // 5. Trạng thái
                TextColumn::make('status')
                    ->label('Trạng thái')
                    ->badge()
                    ->color(fn ($state) => $state?->getColor() ?? 'gray')
                    ->formatStateUsing(fn ($state) => $state?->getLabel() ?? $state),

                // 6. Điểm đi
                TextColumn::make('pickupLocation.name')
                    ->label('Điểm đi'),

                // 7. Điểm đến
                TextColumn::make('delivery_destination')
                    ->label('Điểm đến')
                    ->state(fn (Order $record): string => self::getDeliveryDestination($record)),

                // 8. Xe chờ
                TextColumn::make('xe_cho')
                    ->label('Xe chờ')
                    ->state(fn (Order $record): string => self::getXeCho($record)),

                // 9. Vị trí GPS
                TextColumn::make('gps_location')
                    ->label('Vị trí thực tế trên GPS')
                    ->state(fn (Order $record): string => self::getGpsLocation($record)),

                // 10. Tốc độ
                TextColumn::make('speed')
                    ->label('Tốc độ')
                    ->state(fn (Order $record): string => self::getSpeed($record)),

                // 11. Tình trạng
                TextColumn::make('movement_status')
                    ->label('Tình trạng')
                    ->badge()
                    ->color(fn (Order $record): string => match ($record->status?->value) {
                        'started', 'arrived_pickup', 'delivering' => 'warning',
                        default => 'gray',
                    })
                    ->state(fn (Order $record): string => self::getMovementStatus($record)),

                // 12. Chuyến
                TextColumn::make('trip_count')
                    ->label('Chuyến')
                    ->state(fn (Order $record): string => self::countTodayTripsForVehicle($record).' chuyến'),

                // 13. KM over
                TextColumn::make('km_over')
                    ->label('KM over')
                    ->state(fn (Order $record): string => self::getKmOver($record)),

                // 14. Lái xe
                TextColumn::make('drivers')
                    ->label('Lái xe')
                    ->state(fn (Order $record): string => self::getDrivers($record))
                    ->searchable(),

                // 15. Trực
                TextColumn::make('orderCategory.code')
                    ->label('Trực'),

                // 16. Ca
                TextColumn::make('ca')
                    ->label('Ca')
                    ->badge()
                    ->color(fn (Order $record): string => $record->driver?->driverShifts
                        ->sortByDesc('start_time')->first()?->shift_type?->getColor() ?? 'gray')
                    ->state(fn (Order $record): string => self::getShiftLabel($record)),
            ])
            ->searchable(false)
            ->defaultSort(function (Builder $query): Builder {
                return $query->orderByRaw("CASE status
                    WHEN 'started' THEN 1
                    WHEN 'arrived_pickup' THEN 2
                    WHEN 'delivering' THEN 3
                    WHEN 'arrived_delivery' THEN 4
                    WHEN 'driver_swap' THEN 5
                    WHEN 'sent' THEN 6
                    WHEN 'assigned' THEN 7
                    WHEN 'delivered' THEN 8
                    WHEN 'completed' THEN 9
                    WHEN 'cancelled' THEN 10
                    WHEN 'trashed' THEN 11
                    ELSE 99 END ASC")
                    ->orderBy('planned_loading_at', 'desc');
            })
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->recordActions([
                Action::make('view_timeline')
                    ->label('Hành trình')
                    ->icon('heroicon-o-map-pin')
                    ->color('primary')
                    ->modal()
                    ->modalWidth('5xl')
                    ->modalHeading(fn (Order $record): string => 'Hành trình — '.$record->order_code)
                    ->modalContent(fn (Order $record) => view('filament.resources.trips.components.trip-timeline-popup', [
                        'order' => $record,
                    ]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Đóng'),
            ], position: RecordActionsPosition::BeforeColumns);
    }

    // ─── Column Renderers ────────────────────────────────────────────

    private static function renderBsx(Order $record): string
    {
        $vehicle = $record->vehicle;

        if ($vehicle === null) {
            return '—';
        }

        $plate = $vehicle->plate_number;
        $tonnage = $vehicle->load_capacity
            ? number_format((float) $vehicle->load_capacity, 1, ',', '.').'T'
            : '';

        return trim("{$plate} {$tonnage}");
    }

    private static function getTripStatusLabel(Order $record): string
    {
        return match ($record->status?->value) {
            'draft' => 'Chưa hạ',
            'assigned' => 'Đã phân',
            'sent' => 'Đã gửi',
            'started' => 'Đang chạy',
            'arrived_pickup' => 'Đến lấy',
            'delivering' => 'Đang giao',
            'arrived_delivery' => 'Đến nơi',
            'delivered' => 'Xong',
            'completed' => 'Hoàn thành',
            'driver_swap' => 'Đảo lái',
            'cancelled' => 'Đã hủy',
            'trashed' => 'Đã xóa',
            default => '—',
        };
    }

    private static function getDeliveryDestination(Order $record): string
    {
        $firstDelivery = $record->deliveryPoints
            ->sortBy('sequence')
            ->first();

        if ($firstDelivery === null) {
            return '—';
        }

        return $firstDelivery->address
            ?? $firstDelivery->location?->name
            ?? '—';
    }

    private static function getGpsLocation(Order $record): string
    {
        $latestCheckpoint = $record->tripCheckpoints
            ->sortByDesc('occurred_at')
            ->first();

        if ($latestCheckpoint === null) {
            return '—';
        }

        $locationName = $latestCheckpoint->deliveryPoint?->location?->name;

        if ($locationName !== null) {
            return $locationName;
        }

        $lat = $latestCheckpoint->gps_lat;
        $lng = $latestCheckpoint->gps_lng;

        if ($lat !== null && $lng !== null) {
            return number_format((float) $lat, 4, ',', '.').', '.number_format((float) $lng, 4, ',', '.');
        }

        return '—';
    }

    private static function getSpeed(Order $record): string
    {
        $activeStates = ['started', 'arrived_pickup', 'delivering'];

        if (in_array($record->status?->value, $activeStates, true)) {
            $checkpoints = $record->tripCheckpoints
                ->sortByDesc('occurred_at')
                ->take(2);

            if ($checkpoints->count() >= 2) {
                return '56';
            }

            return '0';
        }

        return '0';
    }

    private static function getMovementStatus(Order $record): string
    {
        return match ($record->status?->value) {
            'started', 'arrived_pickup', 'delivering' => 'Đang chạy',
            default => 'Xe dừng',
        };
    }

    private static function countTodayTripsForVehicle(Order $record): int
    {
        if ($record->vehicle_id === null) {
            return 0;
        }

        return Order::query()
            ->where('vehicle_id', $record->vehicle_id)
            ->whereDate('created_at', today())
            ->count();
    }

    private static function getDrivers(Order $record): string
    {
        $mainDriver = $record->driver;

        $swaps = $record->driverSwaps->sortBy('created_at');

        if ($swaps->isEmpty()) {
            return $mainDriver?->name ?? '—';
        }

        $names = [];

        $firstSwap = $swaps->first();
        $fromDriver = $firstSwap->fromDriver;
        if ($fromDriver) {
            $names[] = $fromDriver->name;
        }

        foreach ($swaps as $swap) {
            if ($swap->toDriver) {
                $names[] = $swap->toDriver->name;
            }
        }

        if (empty($names)) {
            return $mainDriver?->name ?? '—';
        }

        return implode(' → ', array_unique($names));
    }

    private static function getShiftLabel(Order $record): string
    {
        $driver = $record->driver;

        if ($driver === null) {
            return '—';
        }

        $latestShift = $driver->driverShifts
            ->sortByDesc('start_time')
            ->first();

        if ($latestShift === null) {
            return '—';
        }

        return $latestShift->shift_type?->getLabel() ?? '—';
    }

    private static function getXeCho(Order $record): string
    {
        $vehicle = $record->vehicle;

        if ($vehicle === null) {
            return '—';
        }

        // Xe đang ON nhưng không có chuyến active nào khác → đang chờ
        $otherActive = Order::query()
            ->where('vehicle_id', $vehicle->id)
            ->where('id', '!=', $record->id)
            ->whereIn('status', ['started', 'arrived_pickup', 'delivering', 'arrived_delivery'])
            ->exists();

        if ($otherActive) {
            return '—';
        }

        // Xe đang chờ nếu có đơn draft/assigned khác đang xếp hàng
        $pendingCount = Order::query()
            ->where('vehicle_id', $vehicle->id)
            ->where('id', '!=', $record->id)
            ->whereIn('status', ['draft', 'assigned', 'sent'])
            ->count();

        return $pendingCount > 0 ? $pendingCount.' đơn chờ' : '—';
    }

    private static function getKmOver(Order $record): string
    {
        $checkpoints = $record->tripCheckpoints;

        if ($checkpoints->isEmpty()) {
            return '—';
        }

        $firstKm = $checkpoints->first()->km_reading;
        $lastKm = $checkpoints->last()->km_reading;

        if ($firstKm === null || $lastKm === null) {
            return '—';
        }

        $totalKm = (float) $lastKm - (float) $firstKm;

        if ($totalKm <= 0) {
            return '—';
        }

        return number_format($totalKm, 1, ',', '.').' km';
    }
}
