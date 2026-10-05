<x-filament-panels::page>
    <div class="space-y-3">
        <div class="flex flex-col divide-y divide-gray-200 rounded-xl bg-white p-2 shadow-sm ring-1 ring-gray-950/5 dark:divide-white/10 dark:bg-gray-900 dark:ring-white/10">
            {{ $this->filtersForm }}
        </div>

        @if ($areaFilter !== 'all')
            <div class="flex flex-wrap items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                <span>Đang lọc:</span>
                <x-filament::badge color="success">
                    KV: {{ $areaFilter }}
                    <x-slot name="deleteButton" label="Bỏ lọc" wire:click="filterArea('all')"></x-slot>
                </x-filament::badge>
            </div>
        @endif
    </div>

    {{ $this->table }}
</x-filament-panels::page>
