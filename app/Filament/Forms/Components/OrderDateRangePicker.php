<?php

namespace App\Filament\Forms\Components;

use Carbon\Carbon;
use CodeWithKyrian\FilamentDateRange\Forms\Components\DateRangePicker;

class OrderDateRangePicker extends DateRangePicker
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->hiddenLabel()
            ->prefixIcon('heroicon-m-calendar')
            ->startPlaceholder('Từ ngày')
            ->endPlaceholder('Đến ngày')
            ->format('Y-m-d')
            ->displayFormat('d/m/Y');
    }

    public static function make(?string $name = 'dateRange'): static
    {
        return parent::make($name);
    }

    public function prefixIcon(mixed $icon): static
    {
        if ($icon instanceof \BackedEnum) {
            $icon = $icon->value;
        } elseif (is_object($icon) && method_exists($icon, 'getIcon')) {
            $icon = $icon->getIcon();
        } elseif (is_object($icon) && property_exists($icon, 'value')) {
            $icon = $icon->value;
        } elseif (is_object($icon) && method_exists($icon, '__toString')) {
            $icon = (string) $icon;
        } elseif (is_object($icon)) {
            $icon = $icon->value ?? (string) $icon;
        }

        // If it resolved to a generic "calendar-date-range", map it to a standard Heroicon name
        if ($icon === 'calendar-date-range') {
            $icon = 'heroicon-m-calendar';
        }

        $this->startPrefixIcon($icon);
        $this->endPrefixIcon($icon);

        return $this;
    }

    public function placeholder(string|\Closure|null $placeholder): static
    {
        $this->startPlaceholder($placeholder);
        $this->endPlaceholder($placeholder);

        return $this;
    }

    public function syncWithProperties(string $startProperty, string $endProperty): static
    {
        $this->afterStateHydrated(function ($livewire, self $component) use ($startProperty, $endProperty) {
            $start = $livewire->$startProperty ? Carbon::parse($livewire->$startProperty)->format('Y-m-d') : null;
            $end = $livewire->$endProperty ? Carbon::parse($livewire->$endProperty)->format('Y-m-d') : null;

            $component->state([
                'start' => $start,
                'end' => $end,
            ]);
        });

        $this->live();

        $this->afterStateUpdated(function ($livewire, ?array $state) use ($startProperty, $endProperty) {
            if (is_array($state)) {
                $start = $state['start'] ?? null;
                $end = $state['end'] ?? null;

                $livewire->$startProperty = $start ? Carbon::parse($start)->toDateString() : null;
                $livewire->$endProperty = $end ? Carbon::parse($end)->toDateString() : null;
            } else {
                $livewire->$startProperty = null;
                $livewire->$endProperty = null;
            }

            if (method_exists($livewire, 'resetPage')) {
                $livewire->resetPage();
            }
        });

        return $this;
    }
}
