<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Concerns\ReadsAppData;
use App\Filament\Resources\Trips\TripResource;
use App\Models\Trip;
use App\Services\Trip\TripKmAuditService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class CheckTripKm implements Tool
{
    use ReadsAppData;

    public function description(): Stringable|string
    {
        return 'Kiểm tra km chuyến nhập sai trong khoảng ngày: chuyến quá dài hoặc tăng quá nhiều (trên '.TripKmAuditService::HIGH_TRIP_KM.' km), km giảm, bấm dồn mốc, km tăng quá nhanh, '
            .'chuyến sau bắt đầu thấp hơn hoặc hở km so với chuyến trước của cùng xe. '
            .'Tách riêng lỗi do điều phối sửa trên web và lỗi do tài xế nhập trên app, mỗi dòng kèm link mở form sửa chuyến.';
    }

    public function handle(Request $request): Stringable|string
    {
        if ($denied = $this->denyUnless('ViewAny:Trip')) {
            return $denied;
        }

        [$from, $to] = $this->dateRange($request);
        $search = trim($request->string('search')->toString());
        $source = $request->string('source')->toString();
        $limit = $this->limit($request);

        $issues = collect(app(TripKmAuditService::class)->audit($from, $to, $search !== '' ? $search : null));

        if ($issues->isEmpty()) {
            return 'Khoảng ngày: '.$this->rangeLabel($from, $to)."\nKhông phát hiện chuyến nào nhập km bất thường.";
        }

        $staff = $issues->where('source', TripKmAuditService::SOURCE_STAFF);
        $driver = $issues->where('source', TripKmAuditService::SOURCE_DRIVER);

        $output = 'Khoảng ngày: '.$this->rangeLabel($from, $to)
            ."\nTổng {$issues->count()} lỗi km: {$staff->count()} do điều phối sửa / nhập trên web, {$driver->count()} do tài xế nhập trên app."
            ."\nLink ở cột Chuyến mở thẳng form sửa chuyến.";

        if ($source !== TripKmAuditService::SOURCE_DRIVER) {
            $output .= "\n\n### Do điều phối sửa\n".$this->section($staff->values()->all(), $limit, withEditor: true);
        }

        if ($source !== TripKmAuditService::SOURCE_STAFF) {
            $output .= "\n\n### Do tài xế nhập\n".$this->section($driver->values()->all(), $limit, withEditor: false);
        }

        return $output;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'date_from' => $schema->string()->description('Ngày bắt đầu, định dạng YYYY-MM-DD. Bỏ trống = đầu tháng này.'),
            'date_to' => $schema->string()->description('Ngày kết thúc, định dạng YYYY-MM-DD. Bỏ trống = hôm nay.'),
            'search' => $schema->string()->description('Lọc theo mã chuyến, biển số xe hoặc tên tài xế.'),
            'source' => $schema->string()->enum([TripKmAuditService::SOURCE_STAFF, TripKmAuditService::SOURCE_DRIVER])
                ->description('Chỉ lấy lỗi do điều phối (staff) hoặc do tài xế (driver). Bỏ trống = cả hai.'),
            'limit' => $schema->integer()->min(1)->max(self::MAX_ROWS)->description('Số dòng tối đa mỗi phần, mặc định 15.'),
        ];
    }

    /**
     * @param  list<array{trip: Trip, source: string, issue: string, detail: string, editor: ?string}>  $issues
     */
    private function section(array $issues, int $limit, bool $withEditor): string
    {
        if ($issues === []) {
            return 'Không có.';
        }

        $rows = array_map(fn (array $issue): array => array_values(array_filter([
            $this->link($issue['trip']->trip_code ?? '#'.$issue['trip']->id, TripResource::getEditTripUrl($issue['trip'])),
            $issue['trip']->vehicle?->plate_number,
            $issue['trip']->driver?->name,
            $issue['issue'],
            $issue['detail'],
            $withEditor ? ($issue['editor'] ?? '') : false,
        ], fn ($value): bool => $value !== false)), array_slice($issues, 0, $limit));

        $headers = $withEditor
            ? ['Chuyến', 'Xe', 'Tài xế', 'Lỗi', 'Chi tiết', 'Người sửa']
            : ['Chuyến', 'Xe', 'Tài xế', 'Lỗi', 'Chi tiết'];

        $more = count($issues) > $limit ? "\nCòn ".(count($issues) - $limit).' dòng nữa.' : '';

        return $this->table($headers, $rows).$more;
    }
}
