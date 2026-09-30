<?php

namespace App\Observers;

use App\Enums\TripStatus;
use App\Filament\Resources\Trips\TripResource;
use App\Models\Trip;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Throwable;

class TripObserver
{
    /**
     * Handle the Trip "created" event.
     */
    public function created(Trip $trip): void
    {
        if ($trip->status === TripStatus::Delivered) {
            $this->notifyUsersTripDelivered($trip);
        }
    }

    /**
     * Handle the Trip "updated" event.
     */
    public function updated(Trip $trip): void
    {
        if ($trip->wasChanged('status') && $trip->status === TripStatus::Delivered) {
            $this->notifyUsersTripDelivered($trip);
        }
    }

    /**
     * Gửi thông báo đến toàn bộ user khi chuyến chuyển sang trạng thái đã giao.
     */
    protected function notifyUsersTripDelivered(Trip $trip): void
    {
        DB::afterCommit(function () use ($trip): void {
            $trip->loadMissing(['driver', 'vehicle']);

            $driverName = $trip->driver?->name ?? 'Chưa gán tài xế';
            $plateNumber = $trip->vehicle?->plate_number;

            $users = User::all();

            if ($users->isEmpty()) {
                return;
            }

            $actions = [];

            try {
                $timelineUrl = TripResource::getUrl('timeline', ['record' => $trip]);
                if ($timelineUrl) {
                    $actions[] = Action::make('view')
                        ->label('Xem chi tiết')
                        ->url($timelineUrl)
                        ->markAsRead();
                }
            } catch (Throwable) {
                // Route may not be resolvable in non-HTTP/isolated test contexts
            }

            $title = filled($plateNumber)
                ? "Chuyến xe {$plateNumber} đã giao hàng"
                : 'Chuyến xe đã giao hàng';

            $notification = Notification::make()
                ->title($title)
                ->body("Tài xế {$driverName} đã hoàn thành giao hàng.")
                ->icon('heroicon-o-check-circle')
                ->status('success');

            if (! empty($actions)) {
                $notification->actions($actions);
            }

            $notification->sendToDatabase($users, isEventDispatched: true);

            try {
                $notification->broadcast($users);
            } catch (Throwable) {
                // Continue gracefully if broadcasting server is not reachable
            }
        });
    }
}
