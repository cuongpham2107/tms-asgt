<?php

namespace App\Filament\Tables\Columns;

use EduardoRibeiroDev\FilamentLeaflet\Tables\MapColumn;

class UniqueMapColumn extends MapColumn
{
    protected string $view = 'filament.tables.columns.unique-map-column';

    /**
     * Get a unique ID for the map instance to prevent DOM ID collisions when multiple records
     * share the exact same coordinates.
     */
    public function getId(): string
    {
        $record = $this->getRecord();
        $recordKey = $record ? $record->getKey() : uniqid();
        $state = $this->getState();
        $json = json_encode([$state ?? uniqid(), $recordKey]);

        return md5($json);
    }
}
