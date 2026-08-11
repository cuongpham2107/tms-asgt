<?php

namespace App\Filament\Resources\Locations\Pages;

use App\Filament\Forms\Components\PillFilter;
use App\Filament\Resources\Locations\LocationResource;
use App\Models\Area;
use App\Models\Location;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;

class ListLocations extends ListRecords
{
    protected static string $resource = LocationResource::class;

    protected string $view = 'filament.resources.locations.pages.list-locations';

    #[Url]
    public string $orderType = 'HHHK';

    public array $orderTypeFilters = [
        'HHHK' => ['label' => 'HHHK', 'color' => 'bg-blue-500'],
        'external' => ['label' => 'Hàng ngoài', 'color' => 'bg-amber-500'],
        'all' => ['label' => 'Tất cả', 'color' => 'bg-gray-900'],
    ];

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

    public function filtersForm(Schema $form): Schema
    {
        return $form
            ->components([
                PillFilter::make('orderType')
                    ->options($this->orderTypeFilters)
                    ->activeValue(fn ($livewire) => $livewire->orderType)
                    ->clickAction('filterOrderType'),
            ]);
    }

    public function filterOrderType(string $value): void
    {
        $this->orderType = $value;
        $this->resetPage();
    }

    public function table(Table $table): Table
    {
        return parent::table($table)
            ->modifyQueryUsing(function (Builder $query) {
                return $this->applyActiveFilters($query);
            });
    }

    protected function applyActiveFilters(Builder $query): Builder
    {
        return $query->when(
            $this->orderType !== 'all',
            fn (Builder $q) => $q->whereHas('area', fn ($q) => $q->where('type', $this->orderType)),
        );
    }
}
