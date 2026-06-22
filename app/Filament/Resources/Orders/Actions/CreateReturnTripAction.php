<?php

namespace App\Filament\Resources\Orders\Actions;

use App\Enums\OrderStatus;
use App\Models\Order;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Throwable;

class CreateReturnTripAction
{
    public static function make(): Action
    {
        return Action::make('create_return_trip')
            ->label('Tạo quay đầu')
            ->icon('heroicon-o-arrow-path')
            ->color('purple')
            ->hidden(fn (Order $record): bool => ! $record->status->canCreateReturn())
            ->requiresConfirmation()
            ->modalHeading('Xác nhận tạo chuyến quay đầu')
            ->modalDescription('Tạo đơn mới với điểm đi/đến đảo ngược, đánh dấu là chuyến quay đầu.')
            ->modalSubmitActionLabel('Tạo')
            ->modalCancelActionLabel('Hủy')
            ->action(function (Order $record): void {
                try {
                    if (! $record->status->canCreateReturn()) {
                        Notification::make()
                            ->title('Không thể tạo quay đầu')
                            ->body('Không thể tạo chuyến quay đầu cho đơn hàng ở trạng thái này.')
                            ->warning()
                            ->send();

                        return;
                    }

                    $firstDelivery = $record->deliveryPoints()->orderBy('sequence')->first();

                    $returnOrder = Order::create([
                        'order_code' => $record->order_code.'-RT',
                        'type' => $record->type,
                        'area_id' => $record->area_id,
                        'customer_id' => $record->customer_id,
                        'cargo_name' => 'Quay đầu: '.($record->cargo_name ?? ''),
                        'cargo_type' => $record->cargo_type,
                        'total_packages' => $record->total_packages,
                        'total_weight' => $record->total_weight,
                        'pickup_location_id' => $firstDelivery?->location_id,
                        'pickup_address' => $firstDelivery?->address,
                        'pickup_contact' => $firstDelivery?->contact_person,
                        'pickup_phone' => $firstDelivery?->contact_phone,
                        'status' => OrderStatus::Draft->value,
                        'is_return_trip' => true,
                        'parent_order_id' => $record->id,
                        'created_by' => auth()->id(),
                        'notes' => 'Chuyến quay đầu từ '.$record->order_code,
                    ]);

                    if ($firstDelivery && $record->pickup_location_id) {
                        $returnOrder->deliveryPoints()->create([
                            'location_id' => $record->pickup_location_id,
                            'address' => $record->pickup_address,
                            'contact_person' => $record->pickup_contact,
                            'contact_phone' => $record->pickup_phone,
                            'sequence' => 1,
                            'status' => 'pending',
                        ]);
                    }

                    Notification::make()
                        ->title('Tạo quay đầu thành công')
                        ->body('Đơn quay đầu '.$returnOrder->order_code.' đã được tạo.')
                        ->success()
                        ->send();
                } catch (Throwable $e) {
                    Notification::make()
                        ->title('Lỗi')
                        ->body('Không thể tạo quay đầu: '.$e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }
}
