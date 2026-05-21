<?php

namespace App\Filament\Forms\Components;

use Closure;
use Filament\Forms\Components\Field;

class CardPicker extends Field
{
    protected string $view = 'filament.forms.components.card-picker';

    protected array|Closure|null $cards = null;

    protected string|Closure|null $searchPlaceholder = null;

    protected string|Closure|null $leadingIcon = null;

    /**
     * @param  array<int, array<string, mixed>> | Closure | null  $cards
     */
    public function cards(array|Closure|null $cards): static
    {
        $this->cards = $cards;

        return $this;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getCards(): array
    {
        return $this->evaluate($this->cards) ?? [];
    }

    public function searchPlaceholder(string|Closure|null $searchPlaceholder): static
    {
        $this->searchPlaceholder = $searchPlaceholder;

        return $this;
    }

    public function leadingIcon(string|Closure|null $leadingIcon): static
    {
        $this->leadingIcon = $leadingIcon;

        return $this;
    }

    public function getLeadingIcon(): ?string
    {
        return $this->evaluate($this->leadingIcon);
    }

    public function getSearchPlaceholder(): string
    {
        return $this->evaluate($this->searchPlaceholder) ?? 'Tìm kiếm...';
    }
}
