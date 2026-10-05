@php
    $options = $getOptions();
    $activeValue = (string) $getActiveValue();
    $clickAction = $getClickAction();

    /**
     * Options still pass raw Tailwind background classes (e.g. `bg-amber-500`);
     * map them onto the panel's semantic colours.
     */
    $toSemanticColor = fn (?string $color): string => match (true) {
        blank($color) => 'primary',
        str_contains($color, 'amber'), str_contains($color, 'orange'), str_contains($color, 'yellow') => 'warning',
        str_contains($color, 'emerald'), str_contains($color, 'green'), str_contains($color, 'teal') => 'success',
        str_contains($color, 'red'), str_contains($color, 'rose') => 'danger',
        str_contains($color, 'sky'), str_contains($color, 'cyan') => 'info',
        str_contains($color, 'gray'), str_contains($color, 'slate'), str_contains($color, 'violet') => 'gray',
        default => 'primary',
    };

    $dotClasses = [
        'primary' => 'bg-primary-500',
        'success' => 'bg-success-500',
        'warning' => 'bg-warning-500',
        'danger' => 'bg-danger-500',
        'info' => 'bg-info-500',
        'gray' => 'bg-gray-400',
    ];
@endphp

@if ($isDropdown())
    @php
        $activeOption = $options[$activeValue] ?? null;
        $activeLabel = is_array($activeOption) ? ($activeOption['label'] ?? $activeValue) : ($activeOption ?? $activeValue);
        $isFiltering = $activeValue !== 'all';
    @endphp

    <x-filament::dropdown placement="bottom-start" :attributes="$getExtraAttributeBag()">
        <x-slot name="trigger">
            <x-filament::button
                :color="$isFiltering ? 'primary' : 'gray'"
                :outlined="$isFiltering"
                size="sm"
                icon="heroicon-m-chevron-down"
                icon-position="after"
            >
                @if ($prefix = $getLabelPrefix())
                    <span class="font-normal text-gray-500 dark:text-gray-400">{{ $prefix }}:</span>
                @endif
                {{ $activeLabel }}
            </x-filament::button>
        </x-slot>

        <x-filament::dropdown.list>
            @foreach ($options as $key => $option)
                @php
                    $keyStr = (string) $key;
                    $label = is_array($option) ? ($option['label'] ?? '') : $option;
                    $isActive = $activeValue === $keyStr;
                @endphp

                <x-filament::dropdown.list.item
                    :color="$isActive ? 'primary' : 'gray'"
                    :icon="$isActive ? 'heroicon-m-check' : null"
                    :badge="$getCount($keyStr)"
                    :badge-color="$toSemanticColor(is_array($option) ? ($option['color'] ?? null) : null)"
                    wire:click="{{ $clickAction }}('{{ $keyStr }}')"
                    x-on:click="close"
                >
                    {{ $label }}
                </x-filament::dropdown.list.item>
            @endforeach
        </x-filament::dropdown.list>
    </x-filament::dropdown>
@else
<div {{ $getExtraAttributeBag()->class(['pill-filter-wrapper']) }}>
    <style>
        .pill-filter-wrapper.fi-sc-has-gap {
            display: flex;
            gap: calc(var(--spacing) * 2) !important;
        }
    </style>
    <div class="flex min-w-0 items-center gap-2 overflow-x-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
        @if ($prefix = $getLabelPrefix())
            <span class="shrink-0 text-xs font-medium text-gray-500 dark:text-gray-400">
                {{ $prefix }}
            </span>
        @endif

        <x-filament::tabs contained class="min-w-0 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
            @foreach ($options as $key => $option)
                @php
                    $keyStr = (string) $key;
                    $label = is_array($option) ? ($option['label'] ?? '') : $option;
                    $color = $toSemanticColor(is_array($option) ? ($option['color'] ?? null) : null);
                    $icon = is_array($option) ? ($option['icon'] ?? null) : null;
                    $isActive = $activeValue === $keyStr;
                    $count = $getCount($keyStr);
                @endphp

                <x-filament::tabs.item
                    :active="$isActive"
                    :icon="$icon"
                    :badge="$count"
                    :badge-color="$color"
                    wire:click="{{ $clickAction }}('{{ $keyStr }}')"
                    class="whitespace-nowrap"
                >
                    <span class="inline-flex items-center gap-1.5">
                        @if (! $icon && is_array($option) && $keyStr !== 'all')
                            <span @class(['h-1.5 w-1.5 shrink-0 rounded-full', $dotClasses[$color]])></span>
                        @endif

                        {{ $label }}
                    </span>
                </x-filament::tabs.item>
            @endforeach
        </x-filament::tabs>
    </div>
</div>
@endif
