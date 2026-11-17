<?php

namespace App\Livewire;

use App\Models\DatabaseNotification;
use Filament\Livewire\DatabaseNotifications as BaseDatabaseNotifications;
use Filament\Support\Components\Attributes\Unexposed;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Chỉ hiển thị thông báo phát sinh trong vòng 24 giờ gần nhất trên ngăn kéo Thông báo của Filament.
 */
class DatabaseNotifications extends BaseDatabaseNotifications
{
    #[Unexposed]
    public function getNotificationsQuery(): Builder|Relation
    {
        return parent::getNotificationsQuery()
            ->where('created_at', '>=', now()->subHours(DatabaseNotification::RETENTION_HOURS));
    }
}
