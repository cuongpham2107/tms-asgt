<?php

namespace App\Filament\Pages;

use App\Models\Vehicle;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class RealTimeTracking extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMap;

    protected static ?string $navigationLabel = 'Theo dõi thực tế';

    protected static string|UnitEnum|null $navigationGroup = 'Tổng quan';

    protected static ?int $navigationSort = 2;

    protected static ?string $title = 'Theo dõi thực tế';

    protected string $view = 'filament.pages.real-time-tracking';

    public function getMapboxToken(): string
    {
        return config('services.mapbox.token', '');
    }

    /** @return array<int, array<string, mixed>> */
    public function getVehicles(): array
    {
        $activeStatuses = ['started', 'arrived_pickup', 'delivering', 'arrived_delivery'];

        $vehicles = Vehicle::query()
            ->with([
                'driver',
                'orders.deliveryPoints',
                'orders.customer',
            ])
            ->where('is_active', true)
            ->get();

        return $vehicles->map(function (Vehicle $v) use ($activeStatuses) {
            $latestShift = $v->driver?->driverShifts()
                ->whereNull('end_time')
                ->latest('start_time')
                ->first();

            // Select up to 3 relevant orders: prefer active ones, otherwise most recent
            $allOrders = $v->orders ?? collect();
            $activeOrders = $allOrders->filter(fn ($o) => in_array($o->status?->value ?? $o->status, $activeStatuses, true))
                ->sortByDesc('planned_loading_at');

            $selected = $activeOrders->count() ? $activeOrders->take(3) : $allOrders->sortByDesc('planned_loading_at')->take(3);

            $orders = $selected->map(function ($o) {
                $firstDelivery = $o->deliveryPoints?->sortBy('sequence')->first();

                return [
                    'id' => $o->id,
                    'order_code' => $o->order_code,
                    'status' => $o->status?->value ?? $o->status,
                    'status_label' => $o->status?->getLabel() ?? ($o->status?->value ?? ''),
                    'pickup' => $o->pickup_address ?? $o->pickupLocation?->name ?? null,
                    'delivery' => $firstDelivery?->address ?? $firstDelivery?->location?->name ?? null,
                    'customer' => $o->customer?->name ?? null,
                    'total_packages' => $o->total_packages ?? null,
                    'total_weight' => $o->total_weight ?? null,
                ];
            })->values()->toArray();

            return [
                'id' => $v->id,
                'plate' => $v->plate_number,
                'status' => $v->status,
                'driver' => $v->driver?->name ?? 'Không lái',
                'type' => $v->vehicle_type?->getLabel() ?? 'Xe thường',
                'lat' => (float) ($latestShift?->start_gps_lat ?? 10.8231 + (mt_rand(-500, 500) / 10000)),
                'lng' => (float) ($latestShift?->start_gps_lng ?? 106.6297 + (mt_rand(-500, 500) / 10000)),
                'heading' => mt_rand(0, 360),
                'orders' => $orders,
            ];
        })->toArray();
    }

    /** @return array<string, int> */
    public function getStats(): array
    {
        return [
            'total' => Vehicle::where('is_active', true)->count(),
            'running' => Vehicle::where('is_active', true)->where('status', 'running')->count(),
            'on' => Vehicle::where('is_active', true)->where('status', 'on')->count(),
            'bdsc' => Vehicle::where('is_active', true)->where('status', 'bdsc')->count(),
        ];
    }
}
