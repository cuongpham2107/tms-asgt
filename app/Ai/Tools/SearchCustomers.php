<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Concerns\ReadsAppData;
use App\Filament\Resources\Customers\CustomerResource;
use App\Models\Customer;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class SearchCustomers implements Tool
{
    use ReadsAppData;

    public function description(): Stringable|string
    {
        return 'Tìm khách hàng theo mã hoặc tên: thông tin liên hệ, trạng thái, số đơn trong tháng này. Kèm link mở khách hàng.';
    }

    public function handle(Request $request): Stringable|string
    {
        if ($denied = $this->denyUnless('ViewAny:Customer')) {
            return $denied;
        }

        $search = trim($request->string('search')->toString());

        $customers = Customer::query()
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $sub) => $sub->where('code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%")))
            ->withCount(['orders as orders_this_month' => fn (Builder $q) => $q->where('created_at', '>=', now()->startOfMonth())])
            ->orderBy('code')
            ->limit($this->limit($request))
            ->get(['id', 'code', 'name', 'contact_person', 'phone', 'address', 'is_active']);

        $rows = $customers->map(fn (Customer $c): array => [
            $this->link($c->code ?? $c->name, CustomerResource::getUrl('index', ['search' => $c->code ?? $c->name])),
            $c->name,
            $c->contact_person,
            $c->phone,
            $c->address,
            $c->is_active ? 'Đang hoạt động' : 'Ngừng',
            $c->orders_this_month,
        ])->all();

        return $this->table(['Mã', 'Tên', 'Liên hệ', 'Điện thoại', 'Địa chỉ', 'Trạng thái', 'Đơn tháng này'], $rows);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'search' => $schema->string()->description('Mã hoặc tên khách hàng.'),
            'limit' => $schema->integer()->min(1)->max(self::MAX_ROWS)->description('Số khách hiển thị, mặc định 15.'),
        ];
    }
}
