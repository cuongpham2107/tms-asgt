<?php

namespace App\Filament\Forms\Components;

use Closure;
use Filament\Forms\Components\Field;

class PillFilter extends Field
{
    protected string $view = 'filament.forms.components.pill-filter';

    protected array|Closure $options = [];

    protected ?Closure $countCallback = null;

    protected ?string $labelPrefix = null;

    protected ?Closure $activeValueCallback = null;

    protected ?string $clickAction = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hiddenLabel();
    }

    public function options(array|Closure $options): static
    {
        $this->options = $options;

        return $this;
    }

    public function getOptions(): array
    {
        return (array) $this->evaluate($this->options);
    }

    public function countCallback(Closure $callback): static
    {
        $this->countCallback = $callback;

        return $this;
    }

    public function getCount(string $key): ?int
    {
        if ($this->countCallback) {
            $count = $this->evaluate($this->countCallback, ['key' => $key]);

            return $count !== null ? (int) $count : null;
        }

        return null;
    }

    public function labelPrefix(?string $prefix): static
    {
        $this->labelPrefix = $prefix;

        return $this;
    }

    public function getLabelPrefix(): ?string
    {
        return $this->labelPrefix;
    }

    public function activeValue(Closure $callback): static
    {
        $this->activeValueCallback = $callback;

        return $this;
    }

    public function getActiveValue(): ?string
    {
        if ($this->activeValueCallback) {
            return (string) $this->evaluate($this->activeValueCallback);
        }

        return null;
    }

    public function clickAction(?string $action): static
    {
        $this->clickAction = $action;

        return $this;
    }

    public function getClickAction(): ?string
    {
        return $this->clickAction;
    }
}
