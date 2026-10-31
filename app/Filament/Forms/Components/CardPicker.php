<?php

namespace App\Filament\Forms\Components;

use Closure;
use Filament\Forms\Components\Concerns\HasJsRenderer;
use Filament\Forms\Components\Field;
use Illuminate\Support\Facades\Vite;
use Throwable;

/**
 * Lưới thẻ chọn (xe, lái xe…) do React vẽ. Dữ liệu thẻ vẫn khai báo ở PHP qua ->cards([...])
 * nên các nơi gọi giữ nguyên API cũ.
 */
class CardPicker extends Field
{
    use HasJsRenderer;

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

    public function getRenderer(): string
    {
        return Vite::asset('resources/js/filament/forms/card-picker.tsx');
    }

    /**
     * @return array<string, mixed>
     */
    public function getRendererConfiguration(): array
    {
        $cards = $this->getCards();
        $icons = [];

        // Icon trong thẻ là tên Heroicon; gửi SVG sẵn để React khỏi cần bảng icon riêng.
        foreach ($cards as $card) {
            foreach ($card['details'] ?? [] as $detail) {
                $icon = $detail['icon'] ?? null;

                if (filled($icon) && ! array_key_exists($icon, $icons)) {
                    $icons[$icon] = $this->iconSvg($icon, 'h-3.5 w-3.5');
                }
            }
        }

        $leadingIcon = $this->getLeadingIcon();

        return [
            'cards' => $cards,
            'icons' => $icons,
            'leadingIcon' => filled($leadingIcon) ? $this->iconSvg($leadingIcon, 'h-5 w-5') : null,
            'searchPlaceholder' => $this->getSearchPlaceholder(),
            'mode' => $this->getMode(),
            'hasSuggestions' => collect($cards)->contains(fn (array $card): bool => ($card['isSuggested'] ?? false) === true),
            'hasTypes' => collect($cards)->contains(fn (array $card): bool => in_array($card['type'] ?? null, ['company', 'rent'], true)),
            'defaultTab' => $this->resolveDefaultTab($cards),
        ];
    }

    protected function getMode(): string
    {
        return 'generic';
    }

    /**
     * @param  array<int, array<string, mixed>>  $cards
     */
    protected function resolveDefaultTab(array $cards): string
    {
        return collect($cards)->contains(fn (array $card): bool => ($card['isSuggested'] ?? false) === true) ? 'suggested' : 'all';
    }

    private function iconSvg(string $icon, string $class): ?string
    {
        try {
            return svg($icon, $class)->toHtml();
        } catch (Throwable) {
            return null;
        }
    }
}
