@php
    $vehicles = $this->getVehicles();
    $totalFiltered = $this->getTotalFilteredCount();
    $selectedCount = count(array_filter($this->selectedVehicleIds, fn($id) => true));
    $hasMore = $this->hasMoreVehicles();

    $sc = [
        'amber' => [
            'dot' => 'bg-amber-500',
            'pulse' => 'bg-amber-400',
            'badge' => 'bg-amber-100 text-amber-900 font-bold ring-1 ring-amber-500/30 dark:bg-amber-900/60 dark:text-amber-200',
            'border' => 'border-l-amber-500',
            'active_bg' => 'bg-amber-50/70 border-amber-300 dark:bg-amber-950/40 dark:border-amber-700',
        ],
        'emerald' => [
            'dot' => 'bg-emerald-500',
            'pulse' => 'bg-emerald-400',
            'badge' => 'bg-emerald-100 text-emerald-900 font-bold ring-1 ring-emerald-500/30 dark:bg-emerald-900/60 dark:text-emerald-200',
            'border' => 'border-l-emerald-500',
            'active_bg' => 'bg-emerald-50/70 border-emerald-300 dark:bg-emerald-950/40 dark:border-emerald-700',
        ],
        'red' => [
            'dot' => 'bg-red-500',
            'pulse' => 'bg-red-400',
            'badge' => 'bg-red-100 text-red-900 font-bold ring-1 ring-red-500/30 dark:bg-red-900/60 dark:text-red-200',
            'border' => 'border-l-red-500',
            'active_bg' => 'bg-red-50/70 border-red-300 dark:bg-red-950/40 dark:border-red-700',
        ],
        'gray' => [
            'dot' => 'bg-gray-500',
            'pulse' => 'bg-gray-400',
            'badge' => 'bg-gray-100 text-gray-900 font-bold ring-1 ring-gray-300 dark:bg-gray-800 dark:text-gray-200',
            'border' => 'border-l-gray-400',
            'active_bg' => 'bg-gray-100/70 border-gray-300 dark:bg-gray-800/60 dark:border-gray-700',
        ],
    ];
@endphp

<div class="h-full">
    <div class="flex flex-col h-full overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">
        {{-- Header --}}
        <div class="flex items-center justify-between border-b border-gray-100 px-4 py-3 dark:border-gray-700 shrink-0">
            <div class="flex items-center gap-2">
                <div class="flex h-7 w-7 items-center justify-center rounded-lg bg-primary-100 text-primary-700 dark:bg-primary-950 dark:text-primary-400">
                    <x-filament::icon icon="heroicon-m-truck" class="h-4 w-4" />
                </div>
                <span class="text-sm font-bold tracking-tight text-gray-900 dark:text-white">Danh sách xe</span>
            </div>
            <div class="flex items-center gap-2">
                <span class="inline-flex items-center rounded-full bg-primary-100 px-2.5 py-0.5 text-xs font-bold text-primary-800 ring-1 ring-inset ring-primary-700/20 dark:bg-primary-950 dark:text-primary-300" title="Đã chọn / Tổng số xe">
                    Đã chọn: {{ $selectedCount }} / {{ $totalFiltered }}
                </span>
                <button
                    wire:click="$dispatch('refreshMapData')"
                    type="button"
                    class="relative flex h-7 w-7 items-center justify-center rounded-lg text-gray-500 transition-colors hover:bg-gray-100 hover:text-gray-800 dark:hover:bg-gray-800 dark:hover:text-gray-200"
                    title="Làm mới dữ liệu"
                    wire:loading.attr="disabled"
                >
                    <x-filament::icon icon="heroicon-o-arrow-path" class="h-4 w-4" wire:loading.class="animate-spin" wire:target="refreshMapData" />
                </button>
            </div>
        </div>

        {{-- Search Bar --}}
        <div class="border-b border-gray-100 px-3.5 py-2.5 dark:border-gray-700 shrink-0">
            <x-filament::input.wrapper prefix-icon="heroicon-m-magnifying-glass">
                <x-filament::input
                    type="search"
                    wire:model.live.debounce.300ms="vehicleSearch"
                    placeholder="Tìm biển số, tài xế, chuyến..."
                    class="text-xs font-medium text-gray-900 dark:text-white"
                />
            </x-filament::input.wrapper>
        </div>

        {{-- Status Filter Pills --}}
        <div class="border-b border-gray-100 px-3 py-2 dark:border-gray-700 shrink-0">
            <div class="flex flex-wrap gap-1.5">
                @foreach([
                    'all' => 'Tất cả',
                    'running' => 'Đang chạy',
                    'on' => 'Sẵn sàng',
                    'bdsc' => 'Bảo dưỡng',
                    'off' => 'Tắt máy',
                ] as $val => $label)
                    <button
                        type="button"
                        wire:click="$set('filterStatus', '{{ $val }}')"
                        class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1 text-xs font-semibold transition-all {{ $filterStatus === $val ? 'bg-primary-600 text-white shadow-sm' : 'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700' }}"
                    >
                        @if($val === 'running')
                            <span class="h-2 w-2 rounded-full bg-amber-500 {{ $filterStatus === $val ? 'bg-white' : '' }}"></span>
                        @elseif($val === 'on')
                            <span class="h-2 w-2 rounded-full bg-emerald-500 {{ $filterStatus === $val ? 'bg-white' : '' }}"></span>
                        @elseif($val === 'bdsc')
                            <span class="h-2 w-2 rounded-full bg-red-500 {{ $filterStatus === $val ? 'bg-white' : '' }}"></span>
                        @endif
                        {{ $label }}
                    </button>
                @endforeach
            </div>
        </div>

        {{-- Action Buttons --}}
        <div class="flex items-center justify-between border-b border-gray-100 bg-gray-50 px-3.5 py-2 dark:border-gray-700 dark:bg-gray-800/60 shrink-0">
            <div class="flex items-center gap-1.5">
                <button
                    type="button"
                    wire:click="selectRunningOnly"
                    class="inline-flex items-center gap-1 rounded-md px-2.5 py-1 text-xs font-bold text-amber-800 bg-amber-100/80 hover:bg-amber-200 transition-colors dark:text-amber-200 dark:bg-amber-950/60"
                    title="Chỉ chọn các xe đang chạy trên đường"
                >
                    <x-filament::icon icon="heroicon-m-bolt" class="h-3.5 w-3.5" />
                    Xe đang chạy
                </button>
            </div>
            <div class="flex items-center gap-2">
                <button
                    type="button"
                    wire:click="selectAll"
                    class="rounded-md px-2 py-1 text-xs font-semibold text-gray-700 hover:bg-gray-200 hover:text-gray-900 dark:text-gray-300 dark:hover:bg-gray-700"
                >
                    Chọn hết
                </button>
                <span class="text-gray-300 dark:text-gray-600">|</span>
                <button
                    type="button"
                    wire:click="deselectAll"
                    class="rounded-md px-2 py-1 text-xs font-semibold text-gray-700 hover:bg-gray-200 hover:text-gray-900 dark:text-gray-300 dark:hover:bg-gray-700"
                >
                    Bỏ chọn
                </button>
            </div>
        </div>

        {{-- Vehicle Cards List (Scrollable, with Infinite Scroll) --}}
        <div class="flex-1 overflow-y-auto overscroll-contain p-2 space-y-1.5">
            @forelse($vehicles as $v)
                @php
                    $style = $sc[$v['status_color']] ?? $sc['gray'];
                @endphp
                <div
                    wire:click="toggleVehicle({{ $v['id'] }})"
                    class="group relative flex cursor-pointer flex-col gap-1.5 rounded-lg border p-2.5 transition-all {{ $v['selected'] ? $style['border'] . ' border-l-4 ' . $style['active_bg'] . ' shadow-xs' : 'bg-white border-gray-200 hover:border-primary-400 hover:bg-gray-50/80 dark:bg-gray-850 dark:border-gray-700 dark:hover:bg-gray-800' }}"
                >
                    {{-- Row 1: Checkbox + Plate + Status Badge --}}
                    <div class="flex items-center justify-between gap-2">
                        <div class="flex items-center gap-2.5 min-w-0">
                            <div class="flex h-4 w-4 shrink-0 items-center justify-center rounded border {{ $v['selected'] ? 'border-primary-600 bg-primary-600' : 'border-gray-400 bg-white dark:border-gray-500 dark:bg-gray-800' }} transition-colors">
                                @if($v['selected'])
                                    <x-filament::icon icon="heroicon-o-check" class="h-3.5 w-3.5 text-white stroke-[3]" />
                                @endif
                            </div>
                            <span class="truncate text-xs font-black text-gray-950 group-hover:text-primary-600 dark:text-white dark:group-hover:text-primary-400 tracking-tight">
                                {{ $v['plate'] }}
                            </span>
                            @if($v['status'] === 'running')
                                <span class="relative flex h-2.5 w-2.5">
                                    <span class="absolute inline-flex h-full w-full animate-ping rounded-full {{ $style['pulse'] }} opacity-75"></span>
                                    <span class="relative inline-flex h-2.5 w-2.5 rounded-full {{ $style['dot'] }}"></span>
                                </span>
                            @else
                                <span class="inline-block h-2 w-2 shrink-0 rounded-full {{ $style['dot'] }}"></span>
                            @endif
                        </div>

                        <span class="shrink-0 rounded-md px-2 py-0.5 text-[10px] {{ $style['badge'] }}">
                            {{ $v['status_label'] }}
                        </span>
                    </div>

                    {{-- Row 2: Driver & Vehicle type --}}
                    <div class="flex items-center justify-between text-xs text-gray-700 dark:text-gray-300 pl-6.5">
                        <div class="flex items-center gap-1.5 truncate">
                            <x-filament::icon icon="heroicon-m-user" class="h-3.5 w-3.5 shrink-0 text-gray-600 dark:text-gray-400" />
                            <span class="truncate font-semibold text-gray-900 dark:text-gray-100">{{ $v['driver'] }}</span>
                            @if(!empty($v['driver_phone']))
                                <span class="text-gray-500 font-medium text-[11px]">({{ $v['driver_phone'] }})</span>
                            @endif
                        </div>
                        <span class="shrink-0 text-[11px] font-medium text-gray-500 dark:text-gray-400">{{ $v['vehicle_type'] }}</span>
                    </div>

                    {{-- Row 3: Active Trip or GPS Speed (if applicable) --}}
                    @if(!empty($v['active_trip_code']) || $v['gps_speed'] !== null)
                        <div class="flex items-center justify-between text-xs pl-6.5 pt-0.5">
                            @if(!empty($v['active_trip_code']))
                                <span class="inline-flex items-center gap-1 font-mono font-bold text-blue-700 dark:text-blue-300">
                                    <x-filament::icon icon="heroicon-m-arrow-path-rounded-square" class="h-3.5 w-3.5" />
                                    {{ $v['active_trip_code'] }} ({{ $v['active_orders_count'] }} đơn)
                                </span>
                            @else
                                <span></span>
                            @endif

                            @if($v['gps_speed'] !== null && $v['gps_speed'] > 0)
                                <span class="inline-flex items-center gap-1 font-bold text-amber-800 dark:text-amber-300 bg-amber-100/70 px-1.5 py-0.5 rounded">
                                    <x-filament::icon icon="heroicon-m-bolt" class="h-3 w-3" />
                                    {{ round($v['gps_speed'], 1) }} km/h
                                </span>
                            @endif
                        </div>
                    @endif
                </div>
            @empty
                <div class="flex flex-col items-center justify-center py-10 text-center">
                    <x-filament::icon icon="heroicon-o-magnifying-glass" class="h-8 w-8 text-gray-400 dark:text-gray-600 mb-2" />
                    <p class="text-xs font-semibold text-gray-700 dark:text-gray-300">Không tìm thấy phương tiện nào</p>
                    <p class="text-[11px] text-gray-500 dark:text-gray-500 mt-0.5">Thử đổi từ khóa hoặc bộ lọc trạng thái</p>
                </div>
            @endforelse

            {{-- Infinite Scroll Trigger & Loader --}}
            @if($hasMore)
                <div
                    x-intersect.threshold.20="$wire.loadMore()"
                    class="flex flex-col items-center justify-center py-3 text-center"
                >
                    <button
                        wire:click="loadMore"
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 bg-gray-50 px-3 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-100 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300"
                    >
                        <x-filament::loading-indicator class="h-3.5 w-3.5 text-primary-600" wire:loading wire:target="loadMore" />
                        <span wire:loading.remove wire:target="loadMore">Hiển thị thêm xe ({{ count($vehicles) }}/{{ $totalFiltered }})</span>
                        <span wire:loading wire:target="loadMore">Đang tải thêm...</span>
                    </button>
                </div>
            @endif
        </div>
    </div>
</div>
