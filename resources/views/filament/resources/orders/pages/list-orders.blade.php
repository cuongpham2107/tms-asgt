<x-filament-panels::page>
    {{-- Filter bar container --}}
    <div class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">
        {{-- Order type filters --}}
        <div class="border-b border-gray-100 px-5 py-3 dark:border-gray-800">
            <div class="flex items-center gap-2 overflow-x-auto scrollbar-none">
                <span class="mr-2 text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500">Loại đơn</span>
                @foreach ($this->orderTypeFilters as $key => $type)
                    <button
                        type="button"
                        wire:click="filterOrderType('{{ $key }}')"
                        @class([
                            'inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-xs font-semibold transition-all duration-150',
                            'border-[#008fd5] bg-[#008fd5] text-white shadow-sm shadow-[#008fd5]/20' => $this->activeOrderTypeFilter === $key,
                            'border-gray-200 bg-white text-gray-600 hover:border-[#008fd5]/40 hover:text-[#008fd5] dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:border-[#008fd5]/40 dark:hover:text-[#008fd5]' => $this->activeOrderTypeFilter !== $key,
                        ])
                    >
                        <span>{{ $type['label'] }}</span>
                        <span @class([
                            'rounded-full px-1.5 py-0.5 text-[10px] font-bold',
                            'bg-white/20' => $this->activeOrderTypeFilter === $key,
                            'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400' => $this->activeOrderTypeFilter !== $key,
                        ])>
                            {{ $this->getOrderTypeCount($key) }}
                        </span>
                    </button>
                @endforeach
            </div>
        </div>

        {{-- Status filters --}}
        <div class="border-b border-gray-100 px-5 py-3 dark:border-gray-800">
            <div class="flex items-center gap-1.5 overflow-x-auto scrollbar-none">
                <span class="mr-2 text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500">Trạng thái</span>
                @foreach ($this->orderStatusFilters as $key => $status)
                    <button
                        type="button"
                        wire:click="filterStatus('{{ $key }}')"
                        @class([
                            'inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-xs font-semibold transition-all duration-150',
                            $status['color'] . ' border-transparent text-white shadow-sm' => $this->activeStatusFilter === $key,
                            'border-gray-200 bg-white text-gray-600 hover:border-gray-300 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700' => $this->activeStatusFilter !== $key,
                        ])
                    >
                        @if ($key !== 'all')
                            <span class="h-1.5 w-1.5 rounded-full {{ $this->activeStatusFilter === $key ? 'bg-white' : $status['color'] }}"></span>
                        @endif
                        <span>{{ $status['label'] }}</span>
                        <span @class([
                            'text-[10px] font-semibold',
                            'text-white/70' => $this->activeStatusFilter === $key,
                            'text-gray-400 dark:text-gray-500' => $this->activeStatusFilter !== $key,
                        ])>
                            ({{ $this->getOrderStatusCount($key) }})
                        </span>
                    </button>
                @endforeach
            </div>
        </div>

        {{-- Place filters --}}
        <div class="px-5 py-3">
            <div class="flex items-center gap-1.5 overflow-x-auto scrollbar-none">
                <span class="mr-2 text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500">Khu vực</span>
                <button
                    type="button"
                    wire:click="filterPlace('all')"
                    @class([
                        'inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-xs font-semibold transition-all duration-150',
                        'border-[#008fd5] bg-[#008fd5] text-white shadow-sm shadow-[#008fd5]/20' => $this->activePlaceFilter === 'all',
                        'border-gray-200 bg-white text-gray-600 hover:border-[#008fd5]/40 hover:text-[#008fd5] dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:border-[#008fd5]/40 dark:hover:text-[#008fd5]' => $this->activePlaceFilter !== 'all',
                    ])
                >
                    <span>Tất cả</span>
                </button>

                @foreach ($this->orderPlaceFilters as $key => $place)
                    <button
                        type="button"
                        wire:click="filterPlace('{{ $key }}')"
                        @class([
                            'inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-xs font-semibold transition-all duration-150',
                            'border-[#008fd5] bg-[#008fd5] text-white shadow-sm shadow-[#008fd5]/20' => $this->activePlaceFilter === $key,
                            'border-gray-200 bg-white text-gray-600 hover:border-[#008fd5]/40 hover:text-[#008fd5] dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:border-[#008fd5]/40 dark:hover:text-[#008fd5]' => $this->activePlaceFilter !== $key,
                        ])
                    >
                        <span>{{ $place }}</span>
                        <span @class([
                            'rounded-full px-1.5 py-0.5 text-[10px] font-bold',
                            'bg-white/20' => $this->activePlaceFilter === $key,
                            'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400' => $this->activePlaceFilter !== $key,
                        ])>
                            {{ $this->getOrderPlaceCount($key) }}
                        </span>
                    </button>
                @endforeach
            </div>
        </div>
    </div>

    {{-- Search + Date + Mine --}}
    <div class="mt-2 flex flex-wrap items-center gap-3">
        <div class="relative flex-1 min-w-0 sm:max-w-md">
            <x-filament::icon
                icon="heroicon-o-magnifying-glass"
                class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400 dark:text-gray-500"
            />
            <input
                type="search"
                wire:model.live.debounce.400ms="orderSearch"
                placeholder="Tìm mã đơn, khách hàng, hàng hóa, biển số, lái xe..."
                class="h-10 w-full rounded-lg border border-gray-200 bg-white pl-9 pr-3 text-sm text-gray-700 outline-none transition-all placeholder:text-gray-400 focus:border-[#008fd5] focus:ring-2 focus:ring-[#008fd5]/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 dark:placeholder:text-gray-500 dark:focus:border-[#008fd5] dark:focus:ring-[#008fd5]/20"
            />
        </div>

        <div class="flex items-center gap-2">
            <x-filament::input.wrapper class="w-48">
                <x-slot name="prefix">
                    <x-filament::icon icon="heroicon-o-calendar" class="h-3.5 w-3.5" />
                </x-slot>
                <x-filament::input
                    type="date"
                    wire:model.lazy="startDate"
                    placeholder="Từ ngày"
                />
            </x-filament::input.wrapper>

            <span class="text-xs text-gray-400 dark:text-gray-500">→</span>

            <x-filament::input.wrapper class="w-48">
                <x-slot name="prefix">
                    <x-filament::icon icon="heroicon-o-calendar" class="h-3.5 w-3.5" />
                </x-slot>
                <x-filament::input
                    type="date"
                    wire:model.lazy="endDate"
                    placeholder="Đến ngày"
                />
            </x-filament::input.wrapper>

            @if (filled($startDate) || filled($endDate))
                <x-filament::button
                    wire:click="$set('startDate', null); $set('endDate', null)"
                    color="gray"
                    size="sm"
                    icon="heroicon-o-x-mark"
                />
            @endif
        </div>

        <x-filament::button
            wire:click="$toggle('showMineOnly')"
            :color="$showMineOnly ? 'primary' : 'gray'"
            size="sm"
            :icon="$showMineOnly ? 'heroicon-s-user' : 'heroicon-o-user'"
        >
            Đơn của tôi
        </x-filament::button>
    </div>

    {{-- Active filters summary --}}
    @if ($activeOrderTypeFilter !== 'all' || $activeStatusFilter !== 'all' || $activePlaceFilter !== 'all')
        <div class="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
            <span>Đang lọc:</span>
            @if ($activeOrderTypeFilter !== 'all')
                <span class="inline-flex items-center gap-1 rounded-full bg-[#008fd5]/10 px-2 py-0.5 font-medium text-[#008fd5]">
                    {{ $orderTypeFilters[$activeOrderTypeFilter]['label'] ?? $activeOrderTypeFilter }}
                    <button wire:click="filterOrderType('all')" class="ml-0.5 hover:text-red-500">&times;</button>
                </span>
            @endif
            @if ($activeStatusFilter !== 'all')
                <span class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 font-medium text-amber-700 dark:bg-amber-900/30 dark:text-amber-300">
                    {{ $orderStatusFilters[$activeStatusFilter]['label'] ?? $activeStatusFilter }}
                    <button wire:click="filterStatus('all')" class="ml-0.5 hover:text-red-500">&times;</button>
                </span>
            @endif
            @if ($activePlaceFilter !== 'all')
                <span class="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2 py-0.5 font-medium text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300">
                    {{ $orderPlaceFilters[$activePlaceFilter] ?? $activePlaceFilter }}
                    <button wire:click="filterPlace('all')" class="ml-0.5 hover:text-red-500">&times;</button>
                </span>
            @endif
        </div>
    @endif

    <div>
        {{ $this->table }}
    </div>
</x-filament-panels::page>
