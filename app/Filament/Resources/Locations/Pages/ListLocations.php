<?php

namespace App\Filament\Resources\Locations\Pages;

use App\Filament\Resources\Locations\LocationResource;
use App\Models\Area;
use App\Models\Location;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListLocations extends ListRecords
{
    protected static string $resource = LocationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Tạo địa điểm')
                ->using(function (array $data): Location {
                    $first = null;
                    $areas = Area::where('code', $data['area_id'])->get();

                    foreach ($areas as $area) {
                        $record = Location::create(array_merge($data, ['area_id' => $area->id]));
                        $first ??= $record;
                    }

                    return $first ?? Location::create($data);
                }),
        ];
    }
}
