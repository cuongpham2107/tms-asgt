<?php

namespace App\Filament\Forms\Components;

class DriverPicker extends CardPicker
{
    protected string $view = 'filament.forms.components.driver-picker';

    protected function setUp(): void
    {
        parent::setUp();

        $this->leadingIcon('heroicon-o-user');
    }
}
