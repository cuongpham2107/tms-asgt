<?php

namespace App\Filament\Resources\Orders\Actions;

use App\Models\Order;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

class CopyTransportInfoAction
{
    public static function make(): Action
    {
        return Action::make('copy_transport_info')
            ->label('Copy thông tin xe+lái')
            ->icon('heroicon-o-clipboard')
            ->color('gray')
            ->hidden(fn (Order $record): bool => ! $record->trip)
            ->action(function (Order $record): void {
                $trip = $record->trip;
                $vehicle = $trip?->vehicle;
                $driver = $trip?->driver;

                $info = 'Biển số xe: '.($vehicle?->plate_number ?? '—')."\n"
                    .'Tải trọng: '.($vehicle?->load_capacity ? number_format((float) $vehicle->load_capacity, 1, ',', '.').' tấn' : '—')."\n"
                    .'Loại xe: '.($vehicle?->vehicle_type?->getLabel() ?? '—')."\n"
                    .'Họ tên lái xe: '.($driver?->name ?? '—')."\n"
                    .'SĐT lái xe: '.($driver?->phone ?? '—');

                Notification::make()
                    ->title('Đã copy thông tin')
                    ->body($info)
                    ->success()
                    ->send();
            })
            ->extraAttributes([
                'x-data' => '{}',
                'x-on:click' => 'window.navigator.clipboard.writeText(`Biển số xe: —\nHọ tên lái xe: —`)',
            ]);
    }

    /** @return array{vehicle: string, driver: string} */
    public static function getTransportInfo(Order $record): array
    {
        $trip = $record->trip;
        $vehicle = $trip?->vehicle;
        $driver = $trip?->driver;

        $vehicleInfo = 'Biển số xe: '.($vehicle?->plate_number ?? '—')."\n"
            .'Tải trọng: '.($vehicle?->load_capacity ? number_format((float) $vehicle->load_capacity, 1, ',', '.').'T' : '—')."\n"
            .'Loại xe: '.($vehicle?->vehicle_type?->getLabel() ?? '—');

        $driverInfo = 'Họ tên lái xe: '.($driver?->name ?? '—')."\n"
            .'SĐT: '.($driver?->phone ?? '—');

        return ['vehicle' => $vehicleInfo, 'driver' => $driverInfo];
    }
}
