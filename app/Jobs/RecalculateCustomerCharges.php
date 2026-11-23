<?php

namespace App\Jobs;

use App\Models\OrderCharge;
use App\Services\Billing\OrderChargeCalculator;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Tính lại cước các đơn chưa khoá của một khách sau khi bảng giá của khách thay đổi:
 * - waiting: bảng phí giờ chờ đổi → tính lại phần giờ chờ (giữ nguyên đơn giá cước).
 * - freight: bảng giá cước đổi → tính lại các đơn đang chưa có đơn giá.
 * Đơn kế toán đã "Lưu cước" sau thời điểm bảng giá đổi thì giữ nguyên số đã sửa tay.
 */
class RecalculateCustomerCharges implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public const SCOPE_WAITING = 'waiting';

    public const SCOPE_FREIGHT = 'freight';

    public function __construct(
        public int $customerId,
        public string $scope,
        public string $changedAt,
    ) {
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return $this->customerId.'-'.$this->scope;
    }

    public function handle(OrderChargeCalculator $calculator): void
    {
        $changedAt = Carbon::parse($this->changedAt);

        $charges = OrderCharge::query()
            ->where('customer_id', $this->customerId)
            ->whereNull('exported_at')
            ->where(fn ($query) => $query->whereNull('manually_updated_at')->orWhere('manually_updated_at', '<', $changedAt))
            ->when($this->scope === self::SCOPE_FREIGHT, fn ($query) => $query->whereNull('base_price'))
            ->with('order')
            ->get();

        $updated = 0;

        foreach ($charges as $charge) {
            if ($charge->order === null) {
                continue;
            }

            $changed = $this->scope === self::SCOPE_WAITING
                ? $calculator->refreshWaiting($charge->order, $charge)
                : $calculator->calculate($charge->order, $charge)->wasChanged();

            $updated += $changed ? 1 : 0;
        }

        Log::info("[RecalculateCustomerCharges] customer {$this->customerId} {$this->scope}: {$updated}/{$charges->count()} cước được tính lại.");
    }
}
