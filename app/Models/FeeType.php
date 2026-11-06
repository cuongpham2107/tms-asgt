<?php

namespace App\Models;

use App\Enums\FeeCalcMethod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Loại phí. Không có khách = phí dùng chung (nhập tay). Có khách = phí riêng của khách với cách tính + đơn giá:
 * kế toán chọn phí trong modal cước thì số tiền tự điền; bật "Tự cộng cho mọi đơn" thì hệ thống tự thêm.
 * Mã (code) là nhóm cột trong bảng kê nên nhiều dòng được trùng mã.
 */
class FeeType extends Model
{
    protected $fillable = [
        'customer_id',
        'code',
        'name',
        'calc_method',
        'amount',
        'auto_apply',
        'description',
        'sort',
        'is_active',
    ];

    protected $attributes = [
        'calc_method' => 'manual',
    ];

    protected function casts(): array
    {
        return [
            'sort' => 'integer',
            'is_active' => 'boolean',
            'calc_method' => FeeCalcMethod::class,
            'amount' => 'decimal:2',
            'auto_apply' => 'boolean',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Cách tính, mặc định "Nhập tay" khi bản ghi chưa có giá trị (VD: DB chưa chạy migration mới).
     */
    public function method(): FeeCalcMethod
    {
        return $this->calc_method ?? FeeCalcMethod::Manual;
    }

    /**
     * Phí dùng chung + phí riêng của khách.
     *
     * @param  Builder<FeeType>  $query
     */
    public function scopeAvailableFor(Builder $query, ?int $customerId): void
    {
        $query->where(fn (Builder $q) => $q->whereNull('customer_id')->when($customerId, fn (Builder $q) => $q->orWhere('customer_id', $customerId)));
    }

    /**
     * Tên hiển thị trong ô chọn phí: phí riêng ghi thêm đơn giá.
     */
    public function optionLabel(): string
    {
        $method = $this->method();

        return $method->isAuto() && $this->amount !== null
            ? $this->name.' · '.self::money((float) $this->amount).' '.$method->unit()
            : $this->name;
    }

    /**
     * Số lượng gợi ý khi chọn phí cho đơn: số pallet, số thùng, số điểm rớt; còn lại 1.
     */
    public function suggestedQuantity(Order $order): float
    {
        return match ($this->method()) {
            FeeCalcMethod::PerPallet => (float) $order->pallet_count,
            FeeCalcMethod::PerPackage => (float) $order->total_packages,
            FeeCalcMethod::PerExtraDrop => (float) max(0, $order->deliveryPoints()->count() - 1),
            default => 1.0,
        };
    }

    /**
     * Số tiền phí theo cách tính. Phí nhập tay trả null (kế toán tự điền).
     *
     * @return array{amount: float, quantity: float, note: string}|null
     */
    public function calculate(float $quantity, ?float $basePrice): ?array
    {
        $method = $this->method();
        $rate = (float) $this->amount;

        if (! $method->isAuto() || $this->amount === null) {
            return null;
        }

        $amount = $method === FeeCalcMethod::PercentOfBase
            ? round((float) $basePrice * $rate / 100 * $quantity)
            : round($quantity * $rate);

        $note = match ($method) {
            FeeCalcMethod::Fixed => $quantity == 1 ? ($this->description ?: '') : self::number($quantity).' × '.self::money($rate),
            FeeCalcMethod::PercentOfBase => self::number($rate).'% đơn giá chính',
            default => self::number($quantity).' '.$method->quantityLabel().' × '.self::money($rate),
        };

        return ['amount' => $amount, 'quantity' => $quantity, 'note' => $note];
    }

    private static function money(float $value): string
    {
        return number_format($value, 0, ',', '.').' đ';
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, ',', '.'), '0'), ',');
    }
}
