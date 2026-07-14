<x-filament-panels::page>
    {{-- Date filter pills --}}
    <div class="mb-3 flex items-center gap-2">
        @foreach ([
            'today' => 'Hôm nay',
            'week' => 'Tuần này',
            'month' => 'Tháng này',
        ] as $key => $label)
            <button
                type="button"
                wire:click="filterDate('{{ $key }}')"
                @class([
                    'inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-xs font-semibold transition-all duration-150 whitespace-nowrap cursor-pointer',
                    'bg-[#008fd5] border-transparent text-white shadow-sm' => $activeDateFilter === $key,
                    'border-gray-200 bg-white text-gray-600 hover:border-gray-300 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-400 dark:hover:border-gray-500 dark:hover:bg-gray-700' => $activeDateFilter !== $key,
                ])
            >
                @if ($activeDateFilter === $key)
                    <span class="h-1.5 w-1.5 rounded-full bg-white shrink-0"></span>
                @endif
                <span>{{ $label }}</span>
            </button>
        @endforeach
    </div>

    {{-- Station filter pills --}}
    <div class="mb-4 flex items-center gap-2">
        <button
            type="button"
            wire:click="filterStation('all')"
            @class([
                'inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-xs font-semibold transition-all duration-150 whitespace-nowrap cursor-pointer',
                'bg-[#008fd5] border-transparent text-white shadow-sm' => $activeStationFilter === 'all',
                'border-gray-200 bg-white text-gray-600 hover:border-gray-300 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-400 dark:hover:border-gray-500 dark:hover:bg-gray-700' => $activeStationFilter !== 'all',
            ])
        >
            <span>Tất cả</span>
        </button>
        @foreach (\App\Enums\OnDutyLocation::cases() as $station)
            @php
                $color = match($station->value) {
                    'TN' => 'bg-sky-500',
                    'BN' => 'bg-amber-500',
                    'NBA' => 'bg-emerald-500',
                    default => 'bg-[#008fd5]',
                };
            @endphp
            <button
                type="button"
                wire:click="filterStation('{{ $station->value }}')"
                @class([
                    'inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-xs font-semibold transition-all duration-150 whitespace-nowrap cursor-pointer',
                    $color . ' border-transparent text-white shadow-sm' => $activeStationFilter === $station->value,
                    'border-gray-200 bg-white text-gray-600 hover:border-gray-300 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-400 dark:hover:border-gray-500 dark:hover:bg-gray-700' => $activeStationFilter !== $station->value,
                ])
            >
                @if ($activeStationFilter === $station->value)
                    <span class="h-1.5 w-1.5 rounded-full bg-white shrink-0"></span>
                @endif
                <span>{{ $station->getLabel() }}</span>
            </button>
        @endforeach
    </div>

    {{ $this->table }}
</x-filament-panels::page>
