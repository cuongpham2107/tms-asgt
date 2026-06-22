<?php

namespace App\Filament;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;

abstract class BaseTable
{
    /**
     * Apply the table configuration. Override in child classes.
     */
    abstract public static function configure(Table $table): Table;

    /**
     * Default toolbar actions (bulk actions).
     * Override to customize per resource.
     */
    protected static function getDefaultToolbarActions(): array
    {
        return [
            BulkActionGroup::make([
                DeleteBulkAction::make(),
                ForceDeleteBulkAction::make(),
                RestoreBulkAction::make(),
            ]),
        ];
    }

    /**
     * Default record actions (row-level actions).
     * Override to customize per resource.
     */
    protected static function getDefaultRecordActions(): array
    {
        return [
            EditAction::make()
                ->label('Sửa')
                ->iconButton()
                ->icon('heroicon-o-pencil-square'),
        ];
    }

    /**
     * Where to place record actions.
     */
    protected static function getDefaultRecordActionsPosition(): RecordActionsPosition
    {
        return RecordActionsPosition::BeforeColumns;
    }

    /**
     * Default filters. Override in child.
     */
    protected static function getDefaultFilters(): array
    {
        return [];
    }

    /**
     * Apply defaults to a table instance. Call this in your configure() method
     * BEFORE adding resource-specific columns/actions, so the resource can
     * override anything it needs.
     *
     * Usage in child class:
     *   public static function configure(Table $table): Table
     *   {
     *       return parent::applyDefaults($table)
     *           ->columns([...])
     *           ->recordActions([...]);  // overrides defaults
     *   }
     */
    public static function applyDefaults(Table $table): Table
    {
        return $table
            ->filters(static::getDefaultFilters())
            ->recordActions(static::getDefaultRecordActions(), position: static::getDefaultRecordActionsPosition())
            ->toolbarActions(static::getDefaultToolbarActions())
            ->emptyStateIcon('heroicon-o-bookmark')
            ->emptyStateHeading('Chưa có dữ liệu')
            ->emptyStateDescription('Dữ liệu sẽ xuất hiện ở đây sau khi bạn thêm mới.');
    }
}
