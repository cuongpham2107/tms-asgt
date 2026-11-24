<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Enums\OrderDeliveryPointStatus;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Location;
use App\Models\OrderDeliveryPoint;
use App\Services\Notification\DriverNotificationService;
use App\Services\Trip\DeliveryProgressSyncService;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditOrder extends EditRecord
{
    protected static string $resource = OrderResource::class;

    /**
     * Chữ ký điểm giao trước khi lưu, để biết điều hành có thêm / sửa / xoá điểm giao không.
     */
    private ?string $deliveryPointsSignatureBeforeSave = null;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if ($this->record->charge?->isLocked()) {
            unset($data['type']);
        }

        foreach ($data['tripCheckpoints'] ?? [] as $index => $checkpoint) {
            if (empty($checkpoint['delivery_point_id'])) {
                continue;
            }

            $dpId = $checkpoint['delivery_point_id'];

            if (OrderDeliveryPoint::find($dpId)) {
                continue;
            }

            $location = Location::find($dpId);
            if ($location === null) {
                continue;
            }

            $orderId = $this->record->id;

            $maxSequence = OrderDeliveryPoint::where('order_id', $orderId)->max('sequence') ?? 0;

            $deliveryPoint = OrderDeliveryPoint::create([
                'order_id' => $orderId,
                'location_id' => $location->id,
                'sequence' => $maxSequence + 1,
                'address' => $location->address ?? $location->name,
                'status' => OrderDeliveryPointStatus::Pending,
            ]);

            $data['tripCheckpoints'][$index]['delivery_point_id'] = $deliveryPoint->id;
        }

        return $data;
    }

    protected function afterSave(): void
    {
        $record = $this->record->refresh();
        $signatureBefore = $this->deliveryPointsSignatureBeforeSave;
        $this->deliveryPointsSignatureBeforeSave = null;

        app(DeliveryProgressSyncService::class)->syncOrderProgress($record);

        if ($signatureBefore === null || $signatureBefore === $record->refresh()->deliveryPointsSignature()) {
            return;
        }

        try {
            app(DriverNotificationService::class)->sendOrderDeliveryPointsUpdated($record);
        } catch (\Throwable) {
        }
    }

    /**
     * Chụp trạng thái điểm giao trước khi form lưu relationship (beforeValidate chạy trước saveRelationships).
     */
    protected function beforeValidate(): void
    {
        $this->deliveryPointsSignatureBeforeSave = $this->record->deliveryPointsSignature();
    }
}
