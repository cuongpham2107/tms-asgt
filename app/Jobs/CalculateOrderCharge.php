<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\Billing\OrderChargeCalculator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Tính cước khi đơn hàng ngoài hoàn thành — chạy trên queue để không làm chậm checkpoint của app tài xế.
 */
class CalculateOrderCharge implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $orderId)
    {
        $this->afterCommit();
    }

    public function handle(OrderChargeCalculator $calculator): void
    {
        $order = Order::find($this->orderId);

        if ($order === null) {
            return;
        }

        $calculator->calculate($order);
    }
}
