<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Concerns\ReadsAppData;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class SearchOrders implements Tool
{
    use ReadsAppData;

    public function description(): Stringable|string
    {
        return 'Tra cứu đơn hàng theo khoảng ngày đóng hàng, mã đơn, khách hàng, trạng thái; '
            .'có thể lọc riêng đơn đã hoàn thành nhưng CHƯA có cước (missing_charge=true) để soát trước khi làm bảng kê. '
            .'Mỗi đơn có 2 link: mã đơn → mở đơn trong trang Đơn hàng; "Bảng kê" → mở đúng đơn đó trong trang Bảng kê. Câu hỏi về bảng kê/cước thì dẫn link Bảng kê.';
    }

    public function handle(Request $request): Stringable|string
    {
        if ($denied = $this->denyUnless('ViewAny:Order')) {
            return $denied;
        }

        [$from, $to] = $this->dateRange($request);
        $search = trim($request->string('search')->toString());
        $status = OrderStatus::tryFrom($request->string('status')->toString());
        $missingCharge = $request->boolean('missing_charge');
        $orderType = OrderType::tryFrom($request->string('order_type')->toString());

        $query = Order::query()
            ->whereRaw('COALESCE(planned_loading_at, created_at) BETWEEN ? AND ?', [$from->toDateTimeString(), $to->toDateTimeString()])
            ->when($status, fn (Builder $q) => $q->where('status', $status))
            ->when($orderType, fn (Builder $q) => $q->where('type', $orderType))
            ->when($missingCharge, fn (Builder $q) => $q->where('status', OrderStatus::Completed)->whereDoesntHave('charge'))
            ->when($search !== '', fn (Builder $q) => $q->where(function (Builder $sub) use ($search): void {
                $sub->where('order_code', 'like', "%{$search}%")
                    ->orWhere('cargo_name', 'like', "%{$search}%")
                    ->orWhereHas('customer', fn (Builder $c) => $c->where('code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%"));
            }));

        $total = (clone $query)->count();

        $orders = $query->with(['customer:id,code', 'trip:id,trip_code,vehicle_id,started_at', 'trip.vehicle:id,plate_number', 'charge:id,order_id,total,exported_at'])
            ->orderByRaw('COALESCE(planned_loading_at, created_at) DESC')
            ->limit($this->limit($request))
            ->get();

        $rows = $orders->map(function (Order $order): array {
            // Bảng kê lọc theo ngày chạy chuyến, đơn chưa gán chuyến thì theo ngày đóng hàng.
            $day = CarbonImmutable::parse($order->trip?->started_at ?? $order->planned_loading_at ?? $order->created_at);

            return [
                $this->link($order->order_code ?? '#'.$order->id, $this->orderUrl($order)),
                $day->format('d/m/Y'),
                $order->customer?->code,
                $order->trip?->vehicle?->plate_number,
                $order->status?->getLabel(),
                match (true) {
                    $order->charge === null => 'Chưa có cước',
                    $order->charge->exported_at !== null => $this->money($order->charge->total).' (đã xuất bảng kê)',
                    default => $this->money($order->charge->total).' (chưa xuất bảng kê)',
                },
                $this->link('Bảng kê', $this->billingUrl($day->startOfDay(), $day->endOfDay(), $order->order_code, $order->type)),
            ];
        })->all();

        return 'Khoảng ngày: '.$this->rangeLabel($from, $to)
            ."\nTổng số đơn khớp điều kiện: {$total}"
            ."\nHiển thị {$orders->count()} đơn mới nhất:\n"
            .$this->table(['Đơn', 'Ngày', 'Khách', 'Xe', 'Trạng thái', 'Cước', 'Mở bảng kê'], $rows);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'date_from' => $schema->string()->description('Ngày bắt đầu, định dạng YYYY-MM-DD. Bỏ trống = đầu tháng này.'),
            'date_to' => $schema->string()->description('Ngày kết thúc, định dạng YYYY-MM-DD. Bỏ trống = hôm nay.'),
            'search' => $schema->string()->description('Mã đơn, tên hàng, mã hoặc tên khách hàng.'),
            'status' => $schema->string()->enum(array_column(OrderStatus::cases(), 'value'))->description('Trạng thái đơn.'),
            'order_type' => $schema->string()->enum(array_column(OrderType::cases(), 'value'))->description('HHHK = hàng hoá hàng không, external = hàng ngoài.'),
            'missing_charge' => $schema->boolean()->description('true = chỉ lấy đơn đã hoàn thành nhưng chưa có cước.'),
            'limit' => $schema->integer()->min(1)->max(self::MAX_ROWS)->description('Số đơn hiển thị, mặc định 15.'),
        ];
    }
}
