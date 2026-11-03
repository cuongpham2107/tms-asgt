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
    public string $areaFilter = 'all';

    public function getAreaFilters(): array
    {
        $areas = ['all' => ['label' => 'Tất cả KV', 'color' => 'bg-blue-600']];

        foreach (Area::query()->where('is_active', true)->orderBy('sort_order', 'asc')->pluck('code') as $code) {
            $areas[$code] = ['label' => $code, 'color' => 'bg-blue-500'];
        }

        return $areas;
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Tạo địa điểm')
                ->after(function (Location $record) {
                    if (! $record->area_id && $record->areas()->exists()) {
                        $record->updateQuietly(['area_id' => $record->areas()->first()?->id]);
                    }
                }),
        ];
    }

    public function filtersForm(Schema $form): Schema
    {
        return $form
            ->components([
                PillFilter::make('areaFilter')
                    ->options($this->getAreaFilters())
                    ->activeValue(fn ($livewire) => $livewire->areaFilter)
                    ->clickAction('filterArea'),
            ]);
    }

    public function filterArea(string $value): void
    {
        $this->areaFilter = $value;
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
        return $query
            ->when(
                $this->areaFilter !== 'all',
                fn (Builder $q) => $q->where(function ($sq) {
                    $sq->whereHas('areas', fn ($asq) => $asq->where('code', $this->areaFilter))
                        ->orWhereHas('area', fn ($asq) => $asq->where('code', $this->areaFilter));
                }),
            );
    }
}
