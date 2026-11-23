<?php

namespace App\Observers;

use App\Jobs\RecalculateCustomerCharges;
use App\Models\WaitingFeeRate;
use Illuminate\Database\Eloquent\Model;

/**
 * Bảng phí giờ chờ / bảng giá cước của khách thay đổi thì tính lại cước các đơn chưa khoá của khách đó,
 * để đơn đã tính trước khi có giá không bị kẹt ở 0đ.
 */
class RateChangeObserver
{
    public function saved(Model $rate): void
    {
        $this->recalculate($rate);
    }

    public function deleted(Model $rate): void
    {
        $this->recalculate($rate);
    }

    private function recalculate(Model $rate): void
    {
        $customerIds = array_filter(array_unique([$rate->customer_id, $rate->getOriginal('customer_id')]));
        $scope = $rate instanceof WaitingFeeRate ? RecalculateCustomerCharges::SCOPE_WAITING : RecalculateCustomerCharges::SCOPE_FREIGHT;

        foreach ($customerIds as $customerId) {
            RecalculateCustomerCharges::dispatch((int) $customerId, $scope, now()->toDateTimeString());
        }
    }
}
