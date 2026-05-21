<?php

namespace App\Filament\Forms\Components;

use Filament\Forms\Components\Field;

class MapboxLocationPicker extends Field
{
    protected string $view = 'forms.components.mapbox-location-picker';

    protected float|string $defaultLat = 21.4190;

    protected float|string $defaultLng = 105.8808;

    protected int|string $defaultZoom = 13;

    protected ?string $accessToken = null;

    protected ?string $latField = 'lat';

    protected ?string $lngField = 'lng';

    public function defaultLat(float|string $lat): static
    {
        $this->defaultLat = $lat;

        return $this;
    }

    public function defaultLng(float|string $lng): static
    {
        $this->defaultLng = $lng;

        return $this;
    }

    public function defaultZoom(int|string $zoom): static
    {
        $this->defaultZoom = $zoom;

        return $this;
    }

    public function accessToken(?string $token): static
    {
        $this->accessToken = $token;

        return $this;
    }

    public function latField(?string $field): static
    {
        $this->latField = $field;

        return $this;
    }

    public function lngField(?string $field): static
    {
        $this->lngField = $field;

        return $this;
    }

    public function getDefaultLat(): float
    {
        return (float) $this->evaluate($this->defaultLat);
    }

    public function getDefaultLng(): float
    {
        return (float) $this->evaluate($this->defaultLng);
    }

    public function getDefaultZoom(): int
    {
        return (int) $this->evaluate($this->defaultZoom);
    }

    public function getAccessToken(): string
    {
        if ($this->accessToken !== null) {
            return $this->evaluate($this->accessToken);
        }

        return config('services.mapbox.token', '');
    }

    public function getLatField(): string
    {
        return $this->latField;
    }

    public function getLngField(): string
    {
        return $this->lngField;
    }

    public function getLatStatePath(): string
    {
        $parentPath = $this->getContainer()->getStatePath();

        return ($parentPath ? $parentPath.'.' : '').$this->getLatField();
    }

    public function getLngStatePath(): string
    {
        $parentPath = $this->getContainer()->getStatePath();

        return ($parentPath ? $parentPath.'.' : '').$this->getLngField();
    }
}
