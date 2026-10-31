<?php

namespace App\Filament\Forms\Components;

use App\Filament\Resources\Vehicles\Schemas\VehicleForm;
use App\Models\Vehicle;
use Filament\Actions\Action;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;

class VehiclePicker extends CardPicker
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->leadingIcon('heroicon-o-truck');

        $this->registerActions([
            fn (VehiclePicker $component): Action => $component->getCreateVehicleAction(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function getRendererConfiguration(): array
    {
        return [
            ...parent::getRendererConfiguration(),
            // React mở modal Filament của action này bằng context schemaComponent.
            'createAction' => [
                'name' => 'createVehicle',
                'label' => 'Tạo phương tiện',
                'schemaComponent' => $this->getKey(),
            ],
        ];
    }

    protected function getMode(): string
    {
        return 'vehicle';
    }

    /**
     * @param  array<int, array<string, mixed>>  $cards
     */
    protected function resolveDefaultTab(array $cards): string
    {
        return collect($cards)->contains(fn (array $card): bool => ($card['type'] ?? null) === 'company') ? 'company' : 'all';
    }

    public function getCreateVehicleAction(): Action
    {
        return Action::make('createVehicle')
            ->label('Tạo phương tiện')
            ->icon('heroicon-o-plus')
            ->color('primary')
            ->extraAttributes([
                'class' => 'shrink-0 whitespace-nowrap w-max',
            ])
            ->model(Vehicle::class)
            ->modal()
            ->modalHeading('Thêm phương tiện mới')
            ->modalWidth(Width::MaxContent)
            ->schema(fn (Schema $schema) => VehicleForm::configure($schema)->getComponents())
            ->action(function (array $data, VehiclePicker $component) {
                $vehicle = Vehicle::create($data);
                $component->state($vehicle->id);
            });
    }
}
