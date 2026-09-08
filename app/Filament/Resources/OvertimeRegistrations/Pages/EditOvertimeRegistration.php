<?php

namespace App\Filament\Resources\OvertimeRegistrations\Pages;

use App\Enums\OvertimeStatus;
use App\Filament\Resources\OvertimeRegistrations\OvertimeRegistrationResource;
use App\Models\OvertimeRegistration;
use App\Services\Notification\DriverNotificationService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class EditOvertimeRegistration extends EditRecord
{
    protected static string $resource = OvertimeRegistrationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('confirm')
                ->label('Xác nhận')
                ->icon(Heroicon::OutlinedCheck)
                ->color('primary')
                ->requiresConfirmation()
                ->modalHeading('Xác nhận ca tăng cường')
                ->modalDescription(
                    function (OvertimeRegistration $record): string {
                        $date = Carbon::parse($record->overtime_date)->format('d/m/Y');

                        return "Bạn có chắc chắn muốn xác nhận ca tăng cường cho tài xế {$record->driver?->name} vào ngày {$date}?";
                    }
                )
                ->visible(
                    fn (OvertimeRegistration $record): bool => $record->status === OvertimeStatus::Pending
                )
                ->action(function (OvertimeRegistration $record) {
                    $record->update([
                        'status' => OvertimeStatus::Confirmed,
                        'confirmed_at' => now(),
                        'confirmed_by' => Auth::id(),
                    ]);

                    try {
                        app(DriverNotificationService::class)->sendOvertimeConfirmed($record);
                    } catch (\Throwable $e) {
                        // Notification error shouldn't block action
                    }

                    Notification::make()
                        ->title('Đã xác nhận đăng ký tăng cường')
                        ->success()
                        ->send();

                    $this->refreshFormData(['status', 'confirmed_at', 'confirmed_by']);
                }),

            Action::make('reject')
                ->label('Từ chối')
                ->icon(Heroicon::OutlinedXMark)
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Từ chối đăng ký tăng cường')
                ->modalDescription(
                    function (OvertimeRegistration $record): string {
                        $date = Carbon::parse($record->overtime_date)->format('d/m/Y');

                        return "Bạn có chắc chắn muốn từ chối đăng ký tăng cường của tài xế {$record->driver?->name} vào ngày {$date}?";
                    }
                )
                ->visible(
                    fn (OvertimeRegistration $record): bool => $record->status === OvertimeStatus::Pending
                )
                ->action(function (OvertimeRegistration $record) {
                    $record->update([
                        'status' => OvertimeStatus::Rejected,
                        'confirmed_at' => now(),
                        'confirmed_by' => Auth::id(),
                    ]);

                    try {
                        app(DriverNotificationService::class)->sendOvertimeRejected($record);
                    } catch (\Throwable $e) {
                        // Notification error shouldn't block action
                    }

                    Notification::make()
                        ->title('Đã từ chối đăng ký tăng cường')
                        ->warning()
                        ->send();

                    $this->refreshFormData(['status', 'confirmed_at', 'confirmed_by']);
                }),

            DeleteAction::make(),
        ];
    }
}
