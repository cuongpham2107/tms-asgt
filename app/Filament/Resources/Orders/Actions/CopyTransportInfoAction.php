<?php

namespace App\Filament\Resources\Orders\Actions;

use App\Models\Order;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Livewire\Component;

class CopyTransportInfoAction
{
    public static function make(): Action
    {
        return Action::make('copy_transport_info')
            ->label('Copy thông tin xe+lái')
            ->icon('heroicon-o-clipboard')
            ->color('gray')
            ->hidden(fn (Order $record): bool => ! $record->trip)
            ->action(function (Order $record, Component $livewire): void {
                $trip = $record->trip;
                $vehicle = $trip?->vehicle;
                $driver = $trip?->driver;

                $info = 'Biển số xe: '.($vehicle?->plate_number ?? '—')."\n"
                    .'Tải trọng: '.($vehicle?->load_capacity ? number_format((float) $vehicle->load_capacity, 1, ',', '.').' tấn' : '—')."\n"
                    .'Loại xe: '.($vehicle?->vehicle_type?->getLabel() ?? '—')."\n"
                    .'Số đăng ký: '.($vehicle?->registration_number ?? '—')."\n"
                    .'Họ tên lái xe: '.($driver?->name ?? '—')."\n"
                    .'SĐT: '.($driver?->phone ?? '—')."\n"
                    .'CCCD: '.($driver?->cccd ?? '—')."\n"
                    .'Cấp ngày: '.($driver?->cccd_issue_date ?? '—')."\n"
                    .'Sinh ngày: '.($driver?->date_of_birth ?? '—')."\n"
                    .'Nơi thường trú: '.($driver?->address ?? '—');

                $escapedInfo = json_encode($info, JSON_UNESCAPED_UNICODE);
                $livewire->js("window.navigator.clipboard.writeText({$escapedInfo})");

                Notification::make()
                    ->title('Đã copy thông tin')
                    ->body($info)
                    ->success()
                    ->send();
            });
    }

    /** @return array{vehicle: string, driver: string} */
    public static function getTransportInfo(Order $record): array
    {
        $trip = $record->trip;
        $vehicle = $trip?->vehicle;
        $driver = $trip?->driver;

        $vehicleInfo = 'Biển số xe: '.($vehicle?->plate_number ?? '—')."\n"
            .'Tải trọng: '.($vehicle?->load_capacity ? number_format((float) $vehicle->load_capacity, 1, ',', '.').'T' : '—')."\n"
            .'Loại xe: '.($vehicle?->vehicle_type?->getLabel() ?? '—')."\n"
            .'Số đăng ký: '.($vehicle?->registration_number ?? '—');

        $driverInfo = 'Họ tên lái xe: '.($driver?->name ?? '—')."\n"
            .'SĐT: '.($driver?->phone ?? '—')."\n"
            .'CCCD: '.($driver?->cccd ?? '—')."\n"
            .'Cấp ngày: '.($driver?->cccd_issue_date ?? '—')."\n"
            .'Sinh ngày: '.($driver?->date_of_birth ?? '—')."\n"
            .'Nơi thường trú: '.($driver?->address ?? '—');

        return ['vehicle' => $vehicleInfo, 'driver' => $driverInfo];
    }
}
