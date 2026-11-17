<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Notifications\DatabaseNotification as BaseDatabaseNotification;

/**
 * Thông báo hệ thống lưu trong bảng notifications; tự động xóa sau 24 giờ qua model:prune.
 */
class DatabaseNotification extends BaseDatabaseNotification
{
    use MassPrunable;

    public const int RETENTION_HOURS = 24;

    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subHours(self::RETENTION_HOURS));
    }
}
