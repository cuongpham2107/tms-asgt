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
        .pill-filter-btn:not(.is-active) {
            background-color: #ffffff;
            border: 1px solid rgb(229 231 235);
            color: rgb(55 65 81);
        }
        .pill-filter-btn:not(.is-active):hover {
            background-color: rgb(249 250 251);
        }
        html.dark .pill-filter-wrapper .pill-filter-btn:not(.is-active) {
            background-color: rgb(30 41 59 / 0.9) !important;
            border-color: rgb(51 65 85 / 0.8) !important;
            color: rgb(226 232 240) !important;
        }
        html.dark .pill-filter-wrapper .pill-filter-btn:not(.is-active):hover {
            background-color: rgb(51 65 85 / 0.95) !important;
            border-color: rgb(71 85 105) !important;
            color: #ffffff !important;
        }
        html.dark .pill-filter-wrapper .pill-filter-btn:not(.is-active) .pill-count-badge {
            background-color: rgb(51 65 85 / 0.9) !important;
            color: rgb(148 163 184) !important;
        }

        /* Color classes for active pill filters */
        .pill-filter-btn.is-active {
            color: #ffffff !important;
            border-color: transparent !important;
        }
        .pill-filter-btn.is-active.bg-blue-600 { background-color: #2563eb !important; }
        .pill-filter-btn.is-active.bg-amber-500 { background-color: #f59e0b !important; }
        .pill-filter-btn.is-active.bg-emerald-500 { background-color: #10b981 !important; }
        .pill-filter-btn.is-active.bg-sky-500 { background-color: #0ea5e9 !important; }
        .pill-filter-btn.is-active.bg-orange-400 { background-color: #fb923c !important; }
        .pill-filter-btn.is-active.bg-red-500, .pill-filter-btn.is-active.bg-red-600 { background-color: #ef4444 !important; }
        .pill-filter-btn.is-active.bg-gray-400, .pill-filter-btn.is-active.bg-gray-500 { background-color: #6b7280 !important; }
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
                    $color = is_array($option) ? ($option['color'] ?? 'bg-blue-600') : 'bg-blue-600';
                    $icon = is_array($option) ? ($option['icon'] ?? null) : null;
                    $isActive = $activeValue === $keyStr;
                    $count = $getCount($keyStr);
                @endphp

                <button
                    type="button"
                    wire:click="{{ $clickAction }}('{{ $keyStr }}')"
                    @class([
                        'pill-filter-btn inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-semibold transition-all duration-150 whitespace-nowrap cursor-pointer',
                        'is-active ' . $color . ' border-transparent text-white shadow-xs dark:shadow-none' => $isActive,
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
                            'pill-count-badge text-[10px] font-bold rounded-full px-1.5 py-0.5',
                            'bg-white/20 text-white dark:bg-white/25' => $isActive,
                            'bg-gray-100 text-gray-500' => !$isActive,
                        ])>
                            {{ $count }}
                        </span>
                    @endif
                </button>
            @endforeach
        </div>
    </div>
</div>
