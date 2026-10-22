<?php

namespace App\Filament\Resources\Trips\Actions;

use App\Models\Trip;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\URL;
use Livewire\Component;

class CopyTrackingLinkAction
{
    /** Số ngày link theo dõi còn hiệu lực kể từ lúc copy. */
    public const LINK_TTL_DAYS = 3;

    public static function make(): Action
    {
        return Action::make('copy_tracking_link')
            ->label('Copy link theo dõi')
            ->icon('heroicon-o-link')
            ->color('gray')
            ->action(function (Trip $record, Component $livewire): void {
                $url = self::makeUrl($record);

                $livewire->js('window.navigator.clipboard.writeText('.json_encode($url, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).')');

                Notification::make()
                    ->title('Đã copy link theo dõi')
                    ->body('Link có hiệu lực '.self::LINK_TTL_DAYS.' ngày. Gửi khách qua Zalo/SMS: '.$url)
                    ->success()
                    ->send();
            });
    }

    public static function makeUrl(Trip $trip): string
    {
        return URL::temporarySignedRoute(
            'trips.public-tracking',
            now()->addDays(self::LINK_TTL_DAYS),
            ['trip' => $trip->getKey()],
        );
    }
}
