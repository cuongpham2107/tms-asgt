<?php

namespace App\Filament\Forms\Components;

class DriverPicker extends CardPicker
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->leadingIcon('heroicon-o-user');
    }

    protected function getMode(): string
    {
        return 'driver';
    }

    /**
     * @param  array<int, array<string, mixed>>  $cards
     */
    protected function resolveDefaultTab(array $cards): string
    {
        return 'all';
    }
}
