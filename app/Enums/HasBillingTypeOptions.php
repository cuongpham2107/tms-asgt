<?php

namespace App\Enums;

use Filament\Forms\Components\Select;

/**
 * Dùng chung cho loại hình tính cước / phụ phí: "Chuẩn" là chuỗi rỗng nên ô chọn để trống = Chuẩn.
 */
trait HasBillingTypeOptions
{
    public function getLabel(): string
    {
        return $this->value === '' ? 'Chuẩn' : $this->value;
    }

    /**
     * Khớp giá trị nhập từ Excel không phân biệt hoa thường / khoảng trắng thừa.
     */
    public static function fromText(?string $text): ?self
    {
        $normalize = fn (string $value): string => mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $value)));

        return collect(self::cases())->first(fn (self $case): bool => $normalize($case->value) === $normalize((string) $text));
    }

    /**
     * Ô chọn loại hình: để trống = Chuẩn, lưu chuỗi rỗng.
     */
    public static function select(string $name): Select
    {
        return Select::make($name)
            ->options(collect(self::cases())
                ->reject(fn (self $case): bool => $case->value === '')
                ->mapWithKeys(fn (self $case): array => [$case->value => $case->getLabel()])
                ->all())
            ->placeholder('Chuẩn')
            ->formatStateUsing(fn (mixed $state): ?string => blank($state) ? null : (string) $state)
            ->dehydrateStateUsing(fn (?string $state): string => (string) $state);
    }
}
