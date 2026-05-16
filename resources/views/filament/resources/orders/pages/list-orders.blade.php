<x-filament-panels::page>
    <div class="space-y-3">
        <div class="flex items-center gap-3 overflow-x-auto scrollbar-none">
            @foreach ($this->orderTypeFilters as $key => $type)
                <button
                    type="button"
                    wire:click="filterOrderType('{{ $key }}')"
                    wire:target="filterOrderType('{{ $key }}')"
                    @class([
                        'inline-flex items-center gap-2 rounded-full border px-4 py-2 text-xs font-bold whitespace-nowrap transition-all',
                        $type['color'] . ' text-white border-transparent shadow-sm' => $this->activeOrderTypeFilter === $key,
                        'border-transparent bg-transparent text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-100' => $this->activeOrderTypeFilter !== $key,
                    ])
                >
                    <span>{{ $type['label'] }}</span>
                    <span
                        @class([
                            'rounded-full px-2 py-0.5 text-[11px] font-bold',
                            'bg-white/20 text-white' => $this->activeOrderTypeFilter === $key,
                            'text-gray-500 dark:text-gray-400' => $this->activeOrderTypeFilter !== $key,
                        ])
                    >
                        {{ $this->getOrderTypeCount($key) }}
                    </span>
                </button>
            @endforeach
        </div>

        <div class="flex items-center gap-2 overflow-x-auto scrollbar-none">
            @foreach ($this->orderStatusFilters as $key => $status)
                <button
                    type="button"
                    wire:click="filterStatus('{{ $key }}')"
                    wire:target="filterStatus('{{ $key }}')"
                    @class([
                        'inline-flex items-center gap-2 rounded-full border px-3.5 py-2 text-xs font-bold whitespace-nowrap transition-all',
                        $status['color'] . ' text-white border-transparent shadow-sm' => $this->activeStatusFilter === $key,
                        'border-gray-200 bg-white text-gray-600 hover:border-gray-300 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 dark:hover:bg-gray-800' => $this->activeStatusFilter !== $key,
                    ])
                >
                    @if ($key !== 'all')
                        <span class="h-1.5 w-1.5 rounded-full {{ $status['color'] }}"></span>
                    @endif
                    <span>{{ $status['label'] }}</span>
                    <span
                        @class([
                            'text-[11px] font-semibold',
                            'text-white/80' => $this->activeStatusFilter === $key,
                            'text-gray-400 dark:text-gray-500' => $this->activeStatusFilter !== $key,
                        ])
                    >
                        ({{ $this->getOrderStatusCount($key) }})
                    </span>
                </button>
            @endforeach
        </div>

        <div class="flex items-center gap-2 overflow-x-auto scrollbar-none">
            <button
                type="button"
                wire:click="filterPlace('all')"
                wire:target="filterPlace('all')"
                @class([
                    'inline-flex items-center gap-2 rounded-full border px-3.5 py-2 text-xs font-bold whitespace-nowrap transition-all',
                    'border-transparent bg-gray-900 text-white shadow-sm' => $this->activePlaceFilter === 'all',
                    'border-gray-200 bg-white text-gray-600 hover:border-gray-300 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 dark:hover:bg-gray-800' => $this->activePlaceFilter !== 'all',
                ])
            >
                <span>Tất cả điểm</span>
            </button>

            @foreach ($this->orderPlaceFilters as $key => $place)
                <button
                    type="button"
                    wire:click="filterPlace('{{ $key }}')"
                    wire:target="filterPlace('{{ $key }}')"
                    @class([
                        'inline-flex items-center gap-1.5 rounded-full border px-3.5 py-2 text-xs font-bold whitespace-nowrap transition-all',
                        'border-[#008fd5] bg-[#008fd5] text-white shadow-sm' => $this->activePlaceFilter === $key,
                        'border-gray-200 bg-white text-gray-600 hover:border-gray-300 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 dark:hover:bg-gray-800' => $this->activePlaceFilter !== $key,
                    ])
                >
                    <span>{{ $place }}</span>
                    <span
                        @class([
                            'text-[11px] font-semibold',
                            'text-white/80' => $this->activePlaceFilter === $key,
                            'text-gray-400 dark:text-gray-500' => $this->activePlaceFilter !== $key,
                        ])
                    >
                        ({{ $this->getOrderPlaceCount($key) }})
                    </span>
                </button>
            @endforeach
        </div>

        <div class="flex items-center gap-3 pb-1">
            <div class="relative w-full max-w-xl">
                <x-filament::icon
                    icon="heroicon-o-magnifying-glass"
                    class="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400 dark:text-gray-500"
                />
                <input
                    type="search"
                    wire:model.live.debounce.400ms="orderSearch"
                    placeholder="Tìm theo mã đơn, khách hàng, hàng hóa, biển số, lái xe..."
                    class="h-10 w-full rounded-lg border border-gray-200 bg-white pl-9 pr-3 text-sm text-gray-700 outline-none transition-all placeholder:text-gray-400 focus:border-[#008fd5] focus:ring-2 focus:ring-[#008fd5]/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 dark:placeholder:text-gray-500"
                />
            </div>
            <button
                type="button"
                wire:click="$set('showMineOnly', {{ $showMineOnly ? 'false' : 'true' }})"
                @class([
                    'inline-flex items-center gap-1.5 rounded-lg border px-3 py-2 text-xs font-semibold whitespace-nowrap transition-all',
                    'border-[#008fd5] bg-[#008fd5] text-white' => $showMineOnly,
                    'border-gray-200 bg-white text-gray-500 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-400 dark:hover:bg-gray-800' => ! $showMineOnly,
                ])
            >
                <x-filament::icon icon="heroicon-o-user" class="h-3.5 w-3.5" />
                <span>Đơn của tôi</span>
            </button>
        </div>
    </div>

    {{ $this->table }}
</x-filament-panels::page>
