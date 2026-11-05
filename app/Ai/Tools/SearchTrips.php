<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Concerns\ReadsAppData;
use App\Enums\TripStatus;
use App\Filament\Resources\Trips\TripResource;
use App\Filament\Resources\Vehicles\VehicleResource;
use App\Models\Trip;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class SearchTrips implements Tool
{
    use ReadsAppData;

    public function description(): Stringable|string
    {
        return 'Tra cứu chuyến xe theo khoảng ngày, biển số, tên tài xế, mã chuyến, khách hàng hoặc trạng thái. '
            .'Dùng để LIỆT KÊ từng chuyến cụ thể (kèm link mở dòng thời gian). Câu hỏi đếm/xếp hạng/thống kê thì dùng AggregateData.';
    }

    public function handle(Request $request): Stringable|string
    {
        if ($denied = $this->denyUnless('ViewAny:Trip')) {
            return $denied;
        }

        [$from, $to] = $this->dateRange($request);
        $search = trim($request->string('search')->toString());
        $status = TripStatus::tryFrom($request->string('status')->toString());

        $query = $this->whereTripDateBetween(Trip::query(), $from, $to)
            ->when($status, fn (Builder $q) => $q->where('status', $status))
            ->when($search !== '', fn (Builder $q) => $q->where(function (Builder $sub) use ($search): void {
                $sub->where('trip_code', 'like', "%{$search}%")
                    ->orWhereHas('vehicle', fn (Builder $v) => $v->where('plate_number', 'like', "%{$search}%"))
                    ->orWhereHas('driver', fn (Builder $d) => $d->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('orders.customer', fn (Builder $c) => $c->where('code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%"));
            }));

        $total = (clone $query)->count();
        $totalKm = (clone $query)->sum('total_km');

        $trips = $query->with(['vehicle:id,plate_number', 'driver:id,name', 'orders.customer:id,code'])
            ->orderByRaw('COALESCE(started_at, created_at) DESC')
            ->limit($this->limit($request))
            ->get();

        $rows = $trips->map(fn (Trip $trip): array => [
            $this->link($trip->trip_code ?? '#'.$trip->id, TripResource::getUrl('timeline', ['record' => $trip])),
            ($trip->started_at ?? $trip->created_at)?->format('d/m/Y H:i'),
            $trip->vehicle ? $this->link($trip->vehicle->plate_number, VehicleResource::getUrl('index', ['search' => $trip->vehicle->plate_number])) : null,
            $trip->driver?->name,
            $trip->orders->pluck('customer.code')->filter()->unique()->implode(', '),
            $trip->status?->getLabel(),
            number_format((float) $trip->total_km, 0, ',', '.'),
        ])->all();

        return 'Khoảng ngày: '.$this->rangeLabel($from, $to)
            ."\nTổng số chuyến: {$total}, tổng km: ".number_format((float) $totalKm, 0, ',', '.')
            ."\n{$trips->count()} chuyến mới nhất:\n"
            .$this->table(['Chuyến', 'Bắt đầu', 'Xe', 'Tài xế', 'Khách', 'Trạng thái', 'Km'], $rows);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'date_from' => $schema->string()->description('Ngày bắt đầu, định dạng YYYY-MM-DD. Bỏ trống = đầu tháng này.'),
            'date_to' => $schema->string()->description('Ngày kết thúc, định dạng YYYY-MM-DD. Bỏ trống = hôm nay.'),
            'search' => $schema->string()->description('Mã chuyến, biển số xe, tên tài xế, mã hoặc tên khách hàng.'),
            'status' => $schema->string()->enum(array_column(TripStatus::cases(), 'value'))->description('Trạng thái chuyến.'),
            'limit' => $schema->integer()->min(1)->max(self::MAX_ROWS)->description('Số chuyến hiển thị, mặc định 15.'),
        ];
    }
}
