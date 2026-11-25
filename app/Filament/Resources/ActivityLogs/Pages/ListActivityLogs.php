<?php

namespace App\Filament\Resources\ActivityLogs\Pages;

use App\Filament\Resources\ActivityLogs\ActivityLogResource;
use App\Services\ActivityLog\ActivityLogContextResolver;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Collection;

class ListActivityLogs extends ListRecords
{
    protected static string $resource = ActivityLogResource::class;

    public function getTableRecords(): Collection|Paginator|CursorPaginator
    {
        $records = parent::getTableRecords();

        $items = $records instanceof Paginator || $records instanceof CursorPaginator
            ? $records->items()
            : $records;

        ActivityLogContextResolver::current()->warmForRecords($items);

        return $records;
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
