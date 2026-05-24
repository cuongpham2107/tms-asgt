<x-filament-panels::page>
    @php
        $stats = $this->getStats();
        $sidebarVehicles = $this->getSidebarVehicles();
        $lastUpdated = $this->getLastUpdated();
        $selectedCount = count(array_filter($sidebarVehicles, fn($v) => $v['selected']));

        $colors = [
            'running' => ['bg' => 'bg-amber-500', 'bg-light' => 'bg-amber-50', 'text' => 'text-amber-600', 'dark-bg' => 'dark:bg-amber-950/30', 'border' => 'border-amber-200', 'dark-border' => 'dark:border-amber-700'],
            'on' => ['bg' => 'bg-emerald-500', 'bg-light' => 'bg-emerald-50', 'text' => 'text-emerald-600', 'dark-bg' => 'dark:bg-emerald-950/30', 'border' => 'border-emerald-200', 'dark-border' => 'dark:border-emerald-700'],
            'bdsc' => ['bg' => 'bg-red-500', 'bg-light' => 'bg-red-50', 'text' => 'text-red-600', 'dark-bg' => 'dark:bg-red-950/30', 'border' => 'border-red-200', 'dark-border' => 'dark:border-red-700'],
            'off' => ['bg' => 'bg-gray-400', 'bg-light' => 'bg-gray-50', 'text' => 'text-gray-500', 'dark-bg' => 'dark:bg-gray-900/50', 'border' => 'border-gray-200', 'dark-border' => 'dark:border-gray-700'],
        ];

        $sc = [
            'amber' => ['dot' => 'bg-amber-500', 'badge' => 'bg-amber-100 text-amber-700', 'border' => 'border-l-amber-400'],
            'emerald' => ['dot' => 'bg-emerald-500', 'badge' => 'bg-emerald-100 text-emerald-700', 'border' => 'border-l-emerald-400'],
            'red' => ['dot' => 'bg-red-500', 'badge' => 'bg-red-100 text-red-700', 'border' => 'border-l-red-400'],
            'gray' => ['dot' => 'bg-gray-400', 'badge' => 'bg-gray-100 text-gray-600', 'border' => 'border-l-gray-300'],
        ];
    @endphp
    @php
        [$playMin, $playMax] = $this->getPlaybackBounds();
        $playMinFmt = $playMin ? (now()->setTimestamp($playMin)->format('Y-m-d H:i')) : null;
        $playMaxFmt = $playMax ? (now()->setTimestamp($playMax)->format('Y-m-d H:i')) : null;
    @endphp

    <div class="space-y-6">
        {{-- Stats bar --}}
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5">
            <div class="relative overflow-hidden rounded-xl border border-gray-200 bg-white px-5 py-4 dark:border-gray-700 dark:bg-gray-900">
                <div class="flex items-center gap-3">
                    <div class="flex h-11 w-11 items-center justify-center rounded-lg bg-gray-100 dark:bg-gray-800">
                        <x-filament::icon icon="heroicon-o-truck" class="h-5 w-5 text-gray-500 dark:text-gray-400" />
                    </div>
                    <div>
                        <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Tổng xe</p>
                        <p class="text-2xl font-bold tracking-tight text-gray-900 dark:text-white">{{ $stats['total'] }}</p>
                    </div>
                </div>
            </div>

            @foreach ([
                ['key' => 'running', 'label' => 'Đang chạy', 'icon' => 'heroicon-o-play', 'count' => $stats['running']],
                ['key' => 'on', 'label' => 'Sẵn sàng', 'icon' => 'heroicon-o-check-circle', 'count' => $stats['on']],
                ['key' => 'bdsc', 'label' => 'Bảo dưỡng', 'icon' => 'heroicon-o-wrench-screwdriver', 'count' => $stats['bdsc']],
                ['key' => 'off', 'label' => 'Tắt máy', 'icon' => 'heroicon-o-stop', 'count' => $stats['off']],
            ] as $item)
                @php
                    $c = $colors[$item['key']] ?? [];
                @endphp
                <div class="relative overflow-hidden rounded-xl border {{ $c['border'] }} {{ $c['bg-light'] }} px-5 py-4 {{ $c['dark-bg'] }} {{ $c['dark-border'] }}">
                    <div class="flex items-center gap-3">
                        <div class="flex h-11 w-11 items-center justify-center rounded-lg {{ $c['bg-light'] }} dark:bg-gray-800">
                            <x-filament::icon :icon="$item['icon']" class="h-5 w-5 {{ $c['text'] }}" />
                        </div>
                        <div>
                            <p class="text-xs font-medium {{ $c['text'] }}">{{ $item['label'] }}</p>
                            <p class="text-2xl font-bold tracking-tight {{ $c['text'] }}">{{ $item['count'] }}</p>
                        </div>
                    </div>
                    @if ($stats['total'] > 0)
                        @php $pct = round($item['count'] / $stats['total'] * 100); @endphp
                        <div class="absolute bottom-0 left-0 right-0 h-1 bg-black/5 dark:bg-white/5">
                            <div class="h-full {{ $c['bg'] }} rounded-r-sm transition-all duration-500" style="width: {{ $pct }}%"></div>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>

        {{-- Map + Sidebar --}}
        <div class="flex gap-6 items-start">
            {{-- Sidebar --}}
            <div class="w-72 shrink-0 lg:w-80">
                <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">
                    <div class="flex items-center justify-between border-b border-gray-100 px-5 py-3.5 dark:border-gray-700">
                        <span class="text-sm font-semibold text-gray-900 dark:text-white">Danh sách xe</span>
                        <div class="flex items-center gap-2">
                            <span class="rounded-md bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-500 dark:bg-gray-800 dark:text-gray-400">{{ $selectedCount }}/{{ count($sidebarVehicles) }}</span>
                            <button
                                wire:click="refreshData"
                                type="button"
                                class="relative flex h-7 w-7 items-center justify-center rounded-lg text-gray-400 transition-colors hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-gray-800 dark:hover:text-gray-300"
                                title="Làm mới"
                                wire:loading.attr="disabled"
                            >
                                <x-filament::icon icon="heroicon-o-arrow-path" class="h-4 w-4" wire:loading.class="animate-spin" wire:target="refreshData" />
                            </button>
                        </div>
                    </div>

                    <div class="border-b border-gray-100 px-5 py-3 dark:border-gray-700">
                        <x-filament::input.wrapper>
                            <x-filament::input
                                type="search"
                                wire:model.live="vehicleSearch"
                                placeholder="Tìm biển số, tài xế..."
                            />
                        </x-filament::input.wrapper>
                    </div>

                    <div class="flex gap-2 border-b border-gray-100 px-5 py-3 dark:border-gray-700">
                        <x-filament::button size="xs" wire:click="selectAllVehicles" color="primary" wire:loading.attr="disabled" wire:target="selectAllVehicles">
                            <span wire:loading.remove wire:target="selectAllVehicles">Chọn tất cả</span>
                            <span wire:loading wire:target="selectAllVehicles" class="flex items-center gap-1.5">
                                <x-filament::loading-indicator class="h-3.5 w-3.5" />
                                Đang tải...
                            </span>
                        </x-filament::button>
                        <x-filament::button size="xs" wire:click="deselectAllVehicles" color="gray" wire:loading.attr="disabled" wire:target="deselectAllVehicles">
                            <span wire:loading.remove wire:target="deselectAllVehicles">Bỏ chọn</span>
                            <span wire:loading wire:target="deselectAllVehicles" class="flex items-center gap-1.5">
                                <x-filament::loading-indicator class="h-3.5 w-3.5" />
                                Đang tải...
                            </span>
                        </x-filament::button>
                    </div>

                    <div class="max-h-[420px] overflow-y-auto overscroll-contain p-3">
                        @forelse($sidebarVehicles as $v)
                            @continue($vehicleSearch && ! str_contains(str_lower($v['plate']), str_lower($vehicleSearch)) && ! str_contains(str_lower($v['driver']), str_lower($vehicleSearch)))
                            <div
                                wire:click="toggleVehicle({{ $v['id'] }})"
                                class="{{ $v['selected'] ? $sc[$v['status_color']]['border'] . ' border-l-4' : 'border-l-4 border-l-transparent opacity-50' }} mb-1.5 cursor-pointer rounded-r-lg border border-gray-100 bg-white px-3.5 py-3 transition-all hover:border-gray-200 hover:shadow-sm dark:border-gray-700 dark:bg-gray-800 dark:hover:border-gray-600"
                            >
                                <div class="flex items-center gap-2.5">
                                    <div class="flex h-5 w-5 shrink-0 items-center justify-center rounded border-2 {{ $v['selected'] ? 'border-primary-500 bg-primary-500' : 'border-gray-300' }} transition-colors">
                                        @if($v['selected'])
                                            <x-filament::icon icon="heroicon-o-check" class="h-3 w-3 text-white" />
                                        @endif
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <div class="flex items-center gap-1.5">
                                            <span class="truncate text-sm font-semibold text-gray-900 dark:text-white">{{ $v['plate'] }}</span>
                                            <span class="inline-block h-2 w-2 shrink-0 rounded-full {{ $sc[$v['status_color']]['dot'] }}"></span>
                                        </div>
                                        <div class="truncate text-xs text-gray-500 dark:text-gray-400">{{ $v['driver'] }}</div>
                                    </div>
                                    <span class="shrink-0 rounded-full px-2 py-0.5 text-[10px] font-medium leading-tight {{ $sc[$v['status_color']]['badge'] }}">{{ $v['status_label'] }}</span>
                                </div>
                            </div>
                        @empty
                            <div class="px-4 py-10 text-center text-sm text-gray-400">Không có xe nào</div>
                        @endforelse
                    </div>
                </div>
            </div>

            {{-- Map --}}
            <div class="relative min-w-0 flex-1 overflow-hidden rounded-xl border border-gray-200 shadow-sm dark:border-gray-700" style="height: 620px">
                <div wire:loading.class="opacity-30" wire:target="toggleVehicle,selectAllVehicles,deselectAllVehicles,refreshData" class="h-full transition-opacity duration-300">
                    <x-filament-leaflet::map
                        :config="$this->getMapData()"
                        widget
                    />
                </div>

                <div wire:loading.class.remove="opacity-0 pointer-events-none" wire:target="toggleVehicle,selectAllVehicles,deselectAllVehicles,refreshData" class="opacity-0 pointer-events-none absolute inset-0 z-50 flex items-center justify-center rounded-xl" style="background: rgba(255,255,255,0.15); backdrop-filter: blur(4px);">
                    <div class="flex flex-col items-center gap-3 rounded-xl bg-gray-200 px-8 py-6 shadow-lg ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                        <x-filament::loading-indicator class="h-10 w-10 text-primary-500" />
                        <span class="text-sm font-semibold text-gray-600 dark:text-gray-300">Đang tải route...</span>
                    </div>
                </div>
            </div>
        </div>

        {{-- Legend & Info --}}
        <div class="flex flex-wrap items-center justify-between gap-4 rounded-xl border border-gray-200 bg-white px-6 py-4 dark:border-gray-700 dark:bg-gray-900">
            <div class="flex flex-wrap items-center gap-4 text-xs">
                <span class="font-semibold text-gray-500 dark:text-gray-400">Chú thích route:</span>
                <span class="flex items-center gap-1.5">
                    <span class="inline-block h-3.5 w-3.5 rounded-full bg-green-500"></span>
                    <span class="font-medium text-gray-700 dark:text-gray-300">Bắt đầu</span>
                </span>
                <span class="flex items-center gap-1.5">
                    <span class="inline-block h-3.5 w-3.5 rounded-full bg-blue-500"></span>
                    <span class="font-medium text-gray-700 dark:text-gray-300">Đang đi</span>
                </span>
                <span class="flex items-center gap-1.5">
                    <span class="inline-block h-3.5 w-3.5 rounded-full bg-violet-500"></span>
                    <span class="font-medium text-gray-700 dark:text-gray-300">Đến nơi</span>
                </span>
                <span class="flex items-center gap-1.5">
                    <span class="inline-block h-0 w-6 border-b-2 border-dashed border-gray-400"></span>
                    <span class="font-medium text-gray-700 dark:text-gray-300">GPS breadcrumbs</span>
                </span>
            </div>
            <div class="flex items-center gap-3 text-xs text-gray-400">
                @if($playMin && $playMax)
                    <div class="flex items-center gap-2">
                        <button
                            wire:click="togglePlayback"
                            type="button"
                            class="flex items-center gap-2 rounded-lg px-2 py-1 text-gray-400 transition-colors hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-gray-800"
                        >
                            <x-filament::icon :icon="$this->playbackPlaying ? 'heroicon-o-pause' : 'heroicon-o-play'" class="h-3.5 w-3.5" />
                            <span class="text-xs">{{ $this->playbackPlaying ? 'Tạm dừng' : 'Phát' }}</span>
                        </button>

                        <div class="flex items-center gap-2 px-2">
                            <input type="range" min="{{ $playMin }}" max="{{ $playMax }}" step="60" wire:model="playbackTimestamp" />
                            <div class="text-xs text-gray-500">{{ $this->playbackTimestamp ? now()->setTimestamp($this->playbackTimestamp)->format('Y-m-d H:i') : $playMaxFmt }}</div>
                        </div>
                    </div>
                @endif
                <button
                    wire:click="refreshData"
                    type="button"
                    class="flex items-center gap-1.5 rounded-lg px-2 py-1 text-gray-400 transition-colors hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-gray-800"
                    wire:loading.attr="disabled"
                >
                    <x-filament::icon icon="heroicon-o-arrow-path" class="h-3.5 w-3.5" wire:loading.class="animate-spin" wire:target="refreshData" />
                    <span wire:loading.remove wire:target="refreshData">Làm mới</span>
                    <span wire:loading wire:target="refreshData" class="text-primary-500 font-medium">Đang tải...</span>
                </button>
                @if ($lastUpdated)
                    <span class="text-gray-300 dark:text-gray-600">|</span>
                    <span class="flex items-center gap-1">
                        <span class="inline-block h-2 w-2 rounded-full bg-emerald-400"></span>
                        {{ $lastUpdated->format('H:i:s') }}
                    </span>
                @endif
            </div>
        </div>
    </div>
</x-filament-panels::page>
