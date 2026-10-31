<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\Order;
use App\Services\Billing\OrderChargeCalculator;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Tính cước cho đơn hàng ngoài đã hoàn thành nhưng chưa có giá — VD đơn hoàn thành trước khi có bảng giá,
 * hoặc sau khi vừa gán thêm địa điểm vào khu vực tính cước. Dòng cước đã xuất bảng kê (khoá) không bị đụng tới.
 */
class CalculateOrderChargesCommand extends Command
{
    protected $signature = 'order-charges:calculate
        {--from= : Chỉ đơn có ngày đóng hàng dự kiến từ ngày (Y-m-d)}
        {--to= : Chỉ đơn có ngày đóng hàng dự kiến đến ngày (Y-m-d)}
        {--all : Tính lại cả đơn đã có giá (đơn giá gốc và giờ chờ sửa tay sẽ bị thay)}';

    protected $description = 'Tính cước cho các đơn hàng ngoài đã hoàn thành còn thiếu giá';

    public function handle(OrderChargeCalculator $calculator): int
    {
        $orders = Order::query()
            ->where('type', OrderType::External)
            ->where('status', OrderStatus::Completed)
            ->when($this->option('from'), fn (Builder $q, string $from) => $q->whereDate('planned_loading_at', '>=', $from))
            ->when($this->option('to'), fn (Builder $q, string $to) => $q->whereDate('planned_loading_at', '<=', $to))
            ->unless($this->option('all'), fn (Builder $q) => $q->where(fn (Builder $sub) => $sub
                ->doesntHave('charge')
                ->orWhereHas('charge', fn (Builder $charge) => $charge->whereNull('base_price')->whereNull('exported_at'))))
            ->get();

        $this->info("Đang tính cước cho {$orders->count()} đơn…");

        $priced = 0;
        $locked = 0;
        $this->withProgressBar($orders, function (Order $order) use ($calculator, &$priced, &$locked): void {
            $charge = $calculator->calculate($order);
            $locked += $charge->isLocked() ? 1 : 0;
            $priced += ! $charge->isLocked() && $charge->base_price !== null ? 1 : 0;
        });
        $this->newLine(2);

        $this->table(['Kết quả', 'Số đơn'], [
            ['Đã có đơn giá', $priced],
            ['Chưa tra được giá (thiếu khu vực / tuyến / bậc tải)', $orders->count() - $priced - $locked],
            ['Bỏ qua vì đã xuất bảng kê', $locked],
        ]);

        return self::SUCCESS;
    }
}
