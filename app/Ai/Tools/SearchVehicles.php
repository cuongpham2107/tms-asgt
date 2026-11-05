<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Concerns\ReadsAppData;
use App\Enums\VehicleStatus;
use App\Filament\Resources\Vehicles\VehicleResource;
use App\Models\Vehicle;
use App\Models\VehicleDocument;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class SearchVehicles implements Tool
{
    use ReadsAppData;

    public function description(): Stringable|string
    {
        return 'Tra cứu xe: biển số, trạng thái, tài xế, km hiện tại, vị trí GPS gần nhất. '
            .'Đặt expiring_within_days để liệt kê giấy tờ xe (đăng ký, đăng kiểm, giấy phép hàng nguy hiểm) đã hết hạn hoặc sắp hết hạn. '
            .'Kèm link mở xe.';
    }

    public function handle(Request $request): Stringable|string
    {
        if ($denied = $this->denyUnless('ViewAny:Vehicle')) {
            return $denied;
        }

        $search = trim($request->string('search')->toString());
        $status = VehicleStatus::tryFrom($request->string('status')->toString());

        if ($request->filled('expiring_within_days')) {
            return $this->expiringDocuments(max(0, min(365, $request->integer('expiring_within_days'))), $search);
        }

        $query = Vehicle::query()
            ->when($status, fn (Builder $q) => $q->where('status', $status))
            ->when($search !== '', fn (Builder $q) => $q->where(function (Builder $sub) use ($search): void {
                $sub->where('plate_number', 'like', "%{$search}%")
                    ->orWhereHas('driver', fn (Builder $d) => $d->where('name', 'like', "%{$search}%"));
            }));

        $total = (clone $query)->count();
        $vehicles = $query->with('driver:id,name')->orderBy('plate_number')->limit($this->limit($request))->get();

        $rows = $vehicles->map(fn (Vehicle $v): array => [
            $this->link($v->plate_number, VehicleResource::getUrl('index', ['search' => $v->plate_number])),
            $v->status?->getLabel(),
            $v->driver?->name,
            $v->current_mileage,
            $v->gps_address,
            $v->last_gps_update?->format('d/m/Y H:i'),
        ])->all();

        return "Tổng số xe khớp: {$total}\n"
            .$this->table(['Xe', 'Trạng thái', 'Tài xế', 'Km hiện tại', 'Vị trí GPS', 'Cập nhật GPS'], $rows);
    }

    private function expiringDocuments(int $days, string $search): string
    {
        $deadline = now()->addDays($days)->endOfDay();

        $documents = VehicleDocument::query()
            ->with('vehicle:id,plate_number')
            ->whereNotNull('expiry_date')
            ->where('expiry_date', '<=', $deadline)
            ->when($search !== '', fn (Builder $q) => $q->whereHas('vehicle', fn (Builder $v) => $v->where('plate_number', 'like', "%{$search}%")))
            ->orderBy('expiry_date')
            ->limit(self::MAX_ROWS)
            ->get()
            ->map(fn (VehicleDocument $d): array => [
                'plate' => $d->vehicle?->plate_number,
                'type' => $d->doc_type?->getLabel(),
                'expiry' => $d->expiry_date,
            ]);

        $permits = Vehicle::query()
            ->whereNotNull('dangerous_goods_permit_expiry_date')
            ->where('dangerous_goods_permit_expiry_date', '<=', $deadline)
            ->when($search !== '', fn (Builder $q) => $q->where('plate_number', 'like', "%{$search}%"))
            ->get(['id', 'plate_number', 'dangerous_goods_permit_expiry_date'])
            ->map(fn (Vehicle $v): array => [
                'plate' => $v->plate_number,
                'type' => 'Giấy phép hàng nguy hiểm',
                'expiry' => $v->dangerous_goods_permit_expiry_date,
            ]);

        $rows = $documents->concat($permits)
            ->sortBy('expiry')
            ->take(self::MAX_ROWS)
            ->map(fn (array $row): array => [
                $this->link((string) $row['plate'], VehicleResource::getUrl('index', ['search' => $row['plate']])),
                $row['type'],
                $row['expiry']?->format('d/m/Y'),
                $row['expiry']?->isPast() ? 'ĐÃ HẾT HẠN' : 'Còn '.(int) now()->startOfDay()->diffInDays($row['expiry']).' ngày',
            ])->values()->all();

        return "Giấy tờ đã hết hạn hoặc hết hạn trong {$days} ngày tới (đến {$deadline->format('d/m/Y')}):\n"
            .$this->table(['Xe', 'Giấy tờ', 'Hết hạn', 'Tình trạng'], $rows);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'search' => $schema->string()->description('Biển số xe hoặc tên tài xế.'),
            'status' => $schema->string()->enum(array_column(VehicleStatus::cases(), 'value'))->description('Trạng thái xe.'),
            'expiring_within_days' => $schema->integer()->min(0)->max(365)->description('Liệt kê giấy tờ hết hạn trong số ngày tới (vd 30).'),
            'limit' => $schema->integer()->min(1)->max(self::MAX_ROWS)->description('Số xe hiển thị, mặc định 15.'),
        ];
    }
}
