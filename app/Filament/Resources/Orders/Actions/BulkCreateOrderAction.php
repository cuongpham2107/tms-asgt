<?php

namespace App\Filament\Resources\Orders\Actions;

use App\Enums\OrderStatus;
use App\Models\Order;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;

class BulkCreateOrderAction
{
    public static function make(): Action
    {
        return Action::make('bulk_create_order')
            ->label('Tạo nhanh nhiều đơn')
            ->icon('heroicon-o-document-duplicate')
            ->color('gray')
            ->modal()
            ->modalHeading('Tạo nhanh nhiều đơn')
            ->modalDescription('Sao chép đơn hiện tại thành nhiều bản.')
            ->modalWidth('sm')

            ->form([
                TextInput::make('bulk_count')
                    ->label('Số lượng')
                    ->numeric()
                    ->required()
                    ->minValue(1)
                    ->maxValue(20)
                    ->default(5),
            ])
            ->action(function (Order $record, array $data): void {
                $count = (int) ($data['bulk_count'] ?? 1);

                for ($i = 1; $i <= $count; $i++) {
                    $newOrder = $record->replicate();
                    $newOrder->order_code = $record->order_code.'-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT);
                    $newOrder->status = OrderStatus::Draft;
                    $newOrder->vehicle_id = null;
                    $newOrder->driver_id = null;
                    $newOrder->sent_at = null;
                    $newOrder->created_by = auth()->id();
                    $newOrder->created_at = now();
                    $newOrder->updated_at = now();
                    $newOrder->save();

                    // Copy delivery points
                    foreach ($record->deliveryPoints as $dp) {
                        $newOrder->deliveryPoints()->create([
                            'location_id' => $dp->location_id,
                            'sequence' => $dp->sequence,
                            'address' => $dp->address,
                            'contact_person' => $dp->contact_person,
                            'contact_phone' => $dp->contact_phone,
                            'status' => 'pending',
                        ]);
                    }
                }

                Notification::make()
                    ->title('Đã tạo '.$count.' đơn')
                    ->success()
                    ->send();
            });
    }
}
