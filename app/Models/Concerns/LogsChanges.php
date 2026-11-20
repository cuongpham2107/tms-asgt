<?php

namespace App\Models\Concerns;

use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Ghi Nhật ký hoạt động khi tạo / sửa / xoá bản ghi: ai làm, lúc nào, trường nào đổi từ gì sang gì.
 * Model ghi đè `activityLoggedAttributes()` để chỉ ghi một số trường (bỏ GPS, mật khẩu...).
 */
trait LogsChanges
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        $attributes = $this->activityLoggedAttributes();

        return ($attributes === null ? LogOptions::defaults()->logFillable() : LogOptions::defaults()->logOnly($attributes))
            ->logExcept($this->activityIgnoredAttributes())
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /**
     * @return list<string>|null null = mọi trường fillable
     */
    protected function activityLoggedAttributes(): ?array
    {
        return null;
    }

    /**
     * @return list<string>
     */
    protected function activityIgnoredAttributes(): array
    {
        return [];
    }
}
