<?php

namespace App\Observers;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Filament\Resources\Orders\Actions\Concerns\CreatesOrderTransportCards;
use App\Jobs\CalculateOrderCharge;
use App\Models\Order;
use App\Models\OrderEditLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class OrderObserver
{
    public function created(Order $order): void
    {
        $this->invalidateTransportCardsCache();
    }

    public function updated(Order $order): void
    {
        if (! $order->wasChanged()) {
            return;
        }

        if ($order->wasChanged(['status', 'trip_id'])) {
            $this->invalidateTransportCardsCache();
        }

        if ($order->wasChanged('status')
            && $order->status === OrderStatus::Completed
            && $order->type === OrderType::External) {
            CalculateOrderCharge::dispatch($order->id);
        }

        $user = Auth::user();
        if (! $user) {
            return;
        }

        $trackedFields = [
            'trip_id',
            'trip_sequence',
            'status',
            'customer_id',
            'pickup_location_id',
            'pickup_address',
            'planned_loading_at',
            'total_packages',
            'total_weight',
            'cargo_name',
            'notes',
        ];

        foreach ($order->getChanges() as $field => $newValue) {
            if (! in_array($field, $trackedFields)) {
                continue;
            }

            $oldValue = $order->getOriginal($field);

            OrderEditLog::create([
                'order_id' => $order->id,
                'user_id' => $user->id,
                'field' => $field,
                'old_value' => $oldValue instanceof \BackedEnum ? $oldValue->value : ($oldValue !== null ? (string) $oldValue : null),
                'new_value' => $newValue instanceof \BackedEnum ? $newValue->value : ($newValue !== null ? (string) $newValue : null),
            ]);
        }
    }

    public function deleted(Order $order): void
    {
        $this->invalidateTransportCardsCache();
    }

    private function invalidateTransportCardsCache(): void
    {
        if (app()->runningUnitTests() || DB::transactionLevel() === 0) {
            CreatesOrderTransportCards::clearTransportCardsCache();

            return;
        }

        DB::afterCommit(static fn () => CreatesOrderTransportCards::clearTransportCardsCache());
    }
}
