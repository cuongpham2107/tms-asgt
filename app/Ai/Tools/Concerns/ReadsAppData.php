<?php

namespace App\Ai\Tools\Concerns;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Filament\Pages\TripStatisticsReport;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Laravel\Ai\Tools\Request;

/**
 * Tiện ích dùng chung cho các công cụ chỉ đọc của trợ lý dữ liệu:
 * kiểm tra quyền, đọc khoảng ngày, giới hạn số dòng, tạo link mở bản ghi.
 */
trait ReadsAppData
{
    public const MAX_ROWS = 30;

    public const MAX_RANGE_DAYS = 366;

    /**
     * Trả về thông báo lỗi nếu người đang chat không có quyền, null nếu được phép.
     */
    protected function denyUnless(string $permission): ?string
    {
        $user = Auth::user();

        if ($user === null || ! $user->can($permission)) {
            return 'Người dùng hiện tại không có quyền xem dữ liệu này ('.$permission.'). Hãy báo lại cho người dùng.';
        }

        return null;
    }

    /**
     * Đọc khoảng ngày từ tham số (Y-m-d). Mặc định: tháng hiện tại.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    protected function dateRange(Request $request): array
    {
        $from = $this->parseDate($request->string('date_from')->toString()) ?? CarbonImmutable::now()->startOfMonth();
        $to = $this->parseDate($request->string('date_to')->toString()) ?? CarbonImmutable::now();

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        if ($from->diffInDays($to) > self::MAX_RANGE_DAYS) {
            $from = $to->subDays(self::MAX_RANGE_DAYS);
        }

        return [$from->startOfDay(), $to->endOfDay()];
    }

    protected function parseDate(string $value): ?CarbonImmutable
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        return CarbonImmutable::createFromFormat('Y-m-d', $value) ?: null;
    }

    protected function limit(Request $request): int
    {
        return max(1, min(self::MAX_ROWS, $request->integer('limit', 15)));
    }

    /**
     * Lọc theo ngày bắt đầu chuyến, chuyến chưa chạy thì theo ngày tạo (giống bảng kê).
     */
    protected function whereTripDateBetween(Builder $query, CarbonImmutable $from, CarbonImmutable $to, string $table = 'trips'): Builder
    {
        return $query->whereRaw("COALESCE({$table}.started_at, {$table}.created_at) BETWEEN ? AND ?", [
            $from->toDateTimeString(),
            $to->toDateTimeString(),
        ]);
    }

    /**
     * Link mở đúng 1 đơn trong trang Đơn hàng: bỏ các bộ lọc mặc định (HHHK, NBA, "Đơn của tôi") và chọn đúng trạng thái của đơn,
     * vì "Tất cả trạng thái" ở trang này ẩn đơn đã hoàn thành/huỷ.
     */
    protected function orderUrl(Order $order): string
    {
        return OrderResource::getUrl('index', [
            'orderSearch' => $order->order_code,
            'activeOrderTypeFilter' => 'all',
            'activeStatusFilter' => in_array($order->status, [OrderStatus::Completed, OrderStatus::Cancelled], true) ? $order->status->value : 'all',
            'activePlaceFilter' => 'all',
            'showMineOnly' => 0,
        ]);
    }

    /**
     * Link mở trang Bảng kê đã lọc sẵn khoảng ngày, tab loại đơn (HHHK / hàng ngoài) và từ khoá (mã đơn hoặc mã khách).
     */
    protected function billingUrl(CarbonImmutable $from, CarbonImmutable $to, ?string $search = null, ?OrderType $type = null, ?int $customerId = null): string
    {
        $tab = $type?->value ?? 'all';

        return TripStatisticsReport::getUrl(array_filter([
            'startDate' => $from->toDateString(),
            'endDate' => $to->toDateString(),
            'activeTab' => $tab,
            'serviceType' => $tab,
            'search' => $search,
            'customerId' => $customerId,
        ], fn ($value): bool => $value !== null && $value !== ''));
    }

    protected function link(string $label, string $url): string
    {
        return '['.str_replace(['[', ']'], '', $label).']('.$url.')';
    }

    protected function money(float|int|null $value): string
    {
        return number_format((float) $value, 0, ',', '.').' đ';
    }

    protected function rangeLabel(CarbonImmutable $from, CarbonImmutable $to): string
    {
        return $from->format('d/m/Y').' - '.$to->format('d/m/Y');
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<string|int|float|null>>  $rows
     */
    protected function table(array $headers, array $rows): string
    {
        if ($rows === []) {
            return 'Không có dữ liệu.';
        }

        $clean = fn ($cell): string => str_replace(['|', "\n"], ['/', ' '], (string) ($cell ?? '—'));

        $lines = [
            '| '.implode(' | ', $headers).' |',
            '|'.str_repeat(' --- |', count($headers)),
        ];

        foreach ($rows as $row) {
            $lines[] = '| '.implode(' | ', array_map($clean, $row)).' |';
        }

        return implode("\n", $lines);
    }
}
