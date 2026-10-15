<?php

namespace App\Filament\Resources\Trips\Actions;

use App\Models\Trip;
use Filament\Actions\Action;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\View\View;

class ViewTripPhotosAction
{
    public static function make(): Action
    {
        return Action::make('view_photos')
            ->label('Ảnh chuyến đi')
            ->icon('heroicon-o-camera')
            ->color('info')
            ->badge(function (Trip $record): ?string {
                $count = (int) ($record->photos_count ?? $record->photos()->count());

                return $count > 0 ? (string) $count : null;
            })
            ->badgeColor('info')
            ->modal()
            ->modalWidth(Width::SevenExtraLarge)
            ->modalHeading(fn (Trip $record): string => 'Ảnh chuyến đi — '.($record->vehicle?->plate_number ?? $record->trip_code))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Đóng')
            ->extraModalFooterActions([
                Action::make('download_all')
                    ->label('Tải tất cả ảnh (.zip)')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('primary')
                    ->url(fn (Trip $record): string => route('trips.photos.download-zip', $record))
                    ->openUrlInNewTab()
                    ->visible(fn (Trip $record): bool => (int) ($record->photos_count ?? $record->photos()->count()) > 0),
            ])
            ->modalContent(fn (Trip $record): View => view('filament.resources.trips.components.trip-photos-modal', [
                'trip' => $record,
            ]));
    }
}
