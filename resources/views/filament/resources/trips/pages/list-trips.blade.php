<x-filament-panels::page>
    <div class="space-y-4">
        {{-- Filters Bar --}}
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="w-[420px]">
                {{ $this->dateRangeForm }}
            </div>

            <div class="flex-1 min-w-0 sm:max-w-md">
                {{ $this->searchForm }}
            </div>
        </div>
        {{-- Table --}}
        {{ $this->table }}
    </div>
</x-filament-panels::page>
