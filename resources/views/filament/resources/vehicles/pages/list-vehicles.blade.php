<x-filament-panels::page>
    {{-- Filters Bar --}}
    <div class="flex flex-col divide-y divide-gray-200 rounded-xl bg-white p-2 shadow-sm ring-1 ring-gray-950/5 dark:divide-white/10 dark:bg-gray-900 dark:ring-white/10">
        {{ $this->filtersForm }}
    </div>

    {{ $this->table }}
</x-filament-panels::page>
