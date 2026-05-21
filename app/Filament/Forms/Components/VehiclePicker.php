<?php

namespace App\Filament\Forms\Components;

class VehiclePicker extends CardPicker
{
    protected string $view = 'filament.forms.components.card-picker';

    protected function setUp(): void
    {
        parent::setUp();

        $this->leadingIcon('heroicon-o-truck');
    }
}
