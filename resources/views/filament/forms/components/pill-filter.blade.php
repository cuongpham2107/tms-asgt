@php
    $options = $getOptions();
    $activeValue = (string) $getActiveValue();
    $clickAction = $getClickAction();
@endphp

<div {{ $getExtraAttributeBag()->class(['pill-filter-wrapper']) }}>
    <style>
        .pill-filter-wrapper.fi-sc-has-gap {
            display: flex;
            gap: calc(var(--spacing) * 2) !important;
        }
    </style>
    <div class="flex items-center overflow-x-auto scrollbar-none">
        @if ($prefix = $getLabelPrefix())
            <span class="mr-2 text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500 shrink-0">
                {{ $prefix }}
            </span>
        @endif

        <div class="flex items-center gap-1.5 overflow-x-auto scrollbar-none">
            @foreach ($options as $key => $option)
                @php
                    $keyStr = (string) $key;
                    $label = is_array($option) ? ($option['label'] ?? '') : $option;
                    $color = is_array($option) ? ($option['color'] ?? 'bg-[#008fd5]') : 'bg-[#008fd5]';
                    $icon = is_array($option) ? ($option['icon'] ?? null) : null;
                    $isActive = $activeValue === $keyStr;
                    $count = $getCount($keyStr);
                @endphp

                <button
                    type="button"
                    wire:click="{{ $clickAction }}('{{ $keyStr }}')"
                    @class([
                        'inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-xs font-semibold transition-all duration-150 whitespace-nowrap cursor-pointer',
                        $color . ' border-transparent text-white shadow-sm' => $isActive,
                        'border-gray-200 bg-white text-gray-600 hover:border-gray-300 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700' => !$isActive,
                    ])
                >
                    @if ($icon)
                        <x-filament::icon :icon="$icon" class="h-3.5 w-3.5 shrink-0" />
                    @elseif (is_array($option) && $keyStr !== 'all')
                        <span @class([
                            'h-1.5 w-1.5 rounded-full shrink-0',
                            'bg-white' => $isActive,
                            $color => !$isActive,
                        ])></span>
                    @endif

                    <span>{{ $label }}</span>

                    @if ($count !== null)
                        <span @class([
                            'text-[10px] font-bold rounded-full px-1.5 py-0.5',
                            'bg-white/20 text-white' => $isActive,
                            'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400' => !$isActive,
                        ])>
                            {{ $count }}
                        </span>
                    @endif
                </button>
            @endforeach
        </div>
    </div>
</div>
