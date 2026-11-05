<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Concerns\ReadsAppData;
use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\Order;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class ChargeSummary implements Tool
{
    use ReadsAppData;

    /** Ngày tính cước: ngày chạy chuyến, đơn chưa gán chuyến thì theo ngày đóng hàng (giống bảng kê). */
    public const CHARGE_DATE = 'COALESCE(trips.started_at, trips.created_at, orders.planned_loading_at, orders.created_at)';

    public function description(): Stringable|string
    {
        return 'Tổng hợp cước vận chuyển (doanh thu) theo khách hàng trong khoảng ngày: số đơn có cước, tiền trước VAT, VAT, tổng tiền, '
            .'và số đơn đã hoàn thành nhưng chưa có cước. Có thể lọc 1 khách. Kèm link mở bảng kê của từng khách.';
    }

    public function handle(Request $request): Stringable|string
    {
        if ($denied = $this->denyUnless('ViewAny:Order')) {
            return $denied;
        }

        [$from, $to] = $this->dateRange($request);
        $range = [$from->toDateTimeString(), $to->toDateTimeString()];
        $search = trim($request->string('customer')->toString());

        $customerIds = $search === '' ? null : Customer::query()
            ->where('code', 'like', "%{$search}%")
            ->orWhere('name', 'like', "%{$search}%")
            ->pluck('id');

        $charged = DB::table('order_charges')
            ->join('orders', 'orders.id', '=', 'order_charges.order_id')
            ->leftJoin('trips', 'trips.id', '=', 'orders.trip_id')
            ->whereRaw(self::CHARGE_DATE.' BETWEEN ? AND ?', $range)
            ->when($customerIds, fn ($q) => $q->whereIn('orders.customer_id', $customerIds))
            ->groupBy('orders.customer_id')
            ->selectRaw('orders.customer_id, COUNT(*) as orders_count, SUM(order_charges.subtotal) as subtotal, SUM(order_charges.vat_amount) as vat, SUM(order_charges.total) as total')
            ->get()
            ->keyBy('customer_id');

        $missing = Order::query()
            ->leftJoin('trips', 'trips.id', '=', 'orders.trip_id')
            ->where('orders.status', OrderStatus::Completed)
            ->whereDoesntHave('charge')
            ->whereRaw(self::CHARGE_DATE.' BETWEEN ? AND ?', $range)
            ->when($customerIds, fn (Builder $q) => $q->whereIn('orders.customer_id', $customerIds))
            ->groupBy('orders.customer_id')
            ->selectRaw('orders.customer_id, COUNT(*) as missing_count')
            ->pluck('missing_count', 'orders.customer_id');

        $customers = Customer::query()
            ->whereIn('id', $charged->keys()->merge($missing->keys())->unique())
            ->get(['id', 'code', 'name'])
            ->sortByDesc(fn (Customer $c): float => (float) ($charged[$c->id]->total ?? 0));

        $rows = $customers->map(fn (Customer $c): array => [
            $this->link($c->code ?? $c->name, $this->billingUrl($from, $to, customerId: $c->id)),
            $c->name,
            (int) ($charged[$c->id]->orders_count ?? 0),
            $this->money($charged[$c->id]->subtotal ?? 0),
            $this->money($charged[$c->id]->total ?? 0),
            (int) ($missing[$c->id] ?? 0),
        ])->values()->all();

        return 'Khoảng ngày: '.$this->rangeLabel($from, $to)
            ."\nTổng cộng: ".$charged->sum('orders_count').' đơn có cước, trước VAT '.$this->money($charged->sum('subtotal'))
            .', VAT '.$this->money($charged->sum('vat')).', tổng '.$this->money($charged->sum('total'))
            .'; '.$missing->sum().' đơn hoàn thành chưa có cước.'
            ."\n".$this->table(['Khách (mở bảng kê)', 'Tên', 'Đơn có cước', 'Trước VAT', 'Tổng tiền', 'Đơn chưa có cước'], $rows);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'date_from' => $schema->string()->description('Ngày bắt đầu, định dạng YYYY-MM-DD. Bỏ trống = đầu tháng này.'),
            'date_to' => $schema->string()->description('Ngày kết thúc, định dạng YYYY-MM-DD. Bỏ trống = hôm nay.'),
            'customer' => $schema->string()->description('Mã hoặc tên khách hàng. Bỏ trống = tất cả khách.'),
        ];
    }
}
