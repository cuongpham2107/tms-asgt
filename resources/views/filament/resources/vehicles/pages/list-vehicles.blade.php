<x-filament-panels::page>
    {{-- Status Filters --}}
    <div class="flex items-center gap-1">
        @foreach ($this->vehicleStatusFilters as $key => $status)
            <button wire:click="filterStatus('{{ $key }}')" wire:target="filterStatus('{{ $key }}')"
                @class([
                    'flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-bold transition-all whitespace-nowrap border',
                    $status['color'] . ' text-white border-transparent' =>
                        $this->activeStatusFilter === $key,
                    'bg-white text-gray-600 border-gray-200 hover:bg-gray-50 hover:border-gray-300 dark:bg-gray-800 dark:text-gray-300 dark:border-gray-600 dark:hover:bg-gray-700 dark:hover:border-gray-500' =>
                        $this->activeStatusFilter !== $key,
                ])>
                @if ($status['icon'])
                    <x-filament::icon icon="{{ $status['icon'] }}" class="h-4 w-4" />
                @endif
                <span>{{ $status['label'] }}</span>
            </button>
        @endforeach
    </div>

    <div class="flex items-center gap-4 mb-2 overflow-x-auto scrollbar-hide">
        {{-- Type Filters --}}
        <div class="flex items-center gap-1">
            @foreach ($this->vehicleTypes as $key => $type)
                @if ($key === 'all')
                    @continue
                @endif
                <button wire:click="filterType('{{ $key }}')"
                    wire:target="filterType('{{ $key }}')" @class([
                        'flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-bold transition-all whitespace-nowrap border',
                        $type['color'] . ' text-white border-transparent' =>
                            $this->activeTypeFilter === $key,
                        'bg-white text-gray-600 border-gray-200 hover:bg-gray-50 hover:border-gray-300 dark:bg-gray-800 dark:text-gray-300 dark:border-gray-600 dark:hover:bg-gray-700 dark:hover:border-gray-500' =>
                            $this->activeTypeFilter !== $key,
                    ])>
                    @if ($type['icon'])
                        <x-filament::icon icon="{{ $type['icon'] }}" class="h-4 w-4" />
                    @endif
                    <span>{{ $type['label'] }}</span>
                </button>
            @endforeach
        </div>

        <div class="h-6 w-px bg-gray-200 shrink-0 dark:bg-gray-700"></div>

        {{-- Place Filters --}}
        <div class="flex items-center gap-1">
            @foreach ($this->placeVehicleCurrent as $key => $place)
                <button wire:click="filterPlace('{{ $key }}')"
                    wire:target="filterPlace('{{ $key }}')" @class([
                        'px-4 py-1.5 rounded-full text-xs font-bold transition-all whitespace-nowrap border',
                        'bg-[#008fd5] text-white border-[#008fd5]' =>
                            $this->activePlaceFilter === $key,
                        'bg-white text-gray-600 border-gray-200 hover:bg-gray-50 hover:border-gray-300 dark:bg-gray-800 dark:text-gray-300 dark:border-gray-600 dark:hover:bg-gray-700 dark:hover:border-gray-500' =>
                            $this->activePlaceFilter !== $key,
                    ])>
                    {{ $place }}
                </button>
            @endforeach
        </div>
    </div>
    <!-- Search Section -->
    <div class="flex items-center gap-3 mb-4">
        <div class="relative max-w-75 flex-1">
            <x-filament::icon icon="heroicon-o-magnifying-glass"
                class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 dark:text-gray-500 text-[13px]" />
            <input placeholder="Tìm biển số, loại xe..."
                class="w-full h-9 bg-white border border-gray-200 rounded-lg pl-9 pr-3 text-[12.5px] text-gray-700 placeholder:text-gray-400 focus:outline-none focus:border-[#008fd5] transition-all dark:bg-gray-800 dark:border-gray-700 dark:text-gray-200 dark:placeholder:text-gray-500 dark:focus:border-[#008fd5]"
                type="text" value="">
        </div>
        
    </div>
    {{ $this->table }}
</x-filament-panels::page>
