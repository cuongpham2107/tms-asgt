<?php

namespace App\Filament\Resources\ActivityLogs;

use App\Filament\BaseResource;
use App\Filament\Plugins\ActivitylogPlugin;
use App\Filament\Resources\ActivityLogs\Pages\ListActivityLogs;
use App\Filament\Resources\ActivityLogs\Tables\ActivityLogsTable;
use Filament\Tables\Table;
use Spatie\Activitylog\Models\Activity;

class ActivityLogResource extends BaseResource
{
    protected static ?string $model = Activity::class;

    protected static ?string $slug = 'activity-logs';

    public static function getPlugin(): ?ActivitylogPlugin
    {
        try {
            return filament('activitylog');
        } catch (\Throwable) {
            return null;
        }
    }

    public static function getNavigationGroup(): ?string
    {
        return static::getPlugin()?->getNavigationGroup() ?? 'Quản lý danh mục';
    }

    public static function getNavigationIcon(): ?string
    {
        return static::getPlugin()?->getNavigationIcon() ?? 'heroicon-o-book-open';
    }

    public static function getNavigationSort(): ?int
    {
        return static::getPlugin()?->getNavigationSort() ?? 6;
    }

    public static function getModelLabel(): string
    {
        return static::getPlugin()?->getLabel() ?? 'Nhật ký hoạt động';
    }

    public static function getPluralModelLabel(): string
    {
        return static::getPlugin()?->getPluralLabel() ?? 'Nhật ký hoạt động';
    }

    public static function getNavigationBadge(): ?string
    {
        if (static::getPlugin()?->getNavigationCountBadge()) {
            return (string) static::getModel()::count();
        }

        return null;
    }

    public static function canViewAny(): bool
    {
        $plugin = static::getPlugin();
        if ($plugin !== null) {
            return $plugin->isAuthorized();
        }

        $user = auth()->user();
        if (! $user) {
            return false;
        }

        return $user->hasRole('super_admin') || $user->can('view_any_activitylog') || $user->can('ViewAny:Activity');
    }

    public static function table(Table $table): Table
    {
        return ActivityLogsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListActivityLogs::route('/'),
        ];
    }
}
