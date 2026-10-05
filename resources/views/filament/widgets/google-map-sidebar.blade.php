@php
    $vehicles = $this->getVehicles();
    $totalFiltered = $this->getTotalFilteredCount();
    $selectedCount = count(array_filter($this->selectedVehicleIds, fn($id) => true));
    $hasMore = $this->hasMoreVehicles();

    // Khoá 'amber' | 'emerald' | 'red' | 'gray' do GoogleMapSidebar::getVehicles() trả về (status_color).
    $sc = [
        'amber' => [
            'color' => 'warning',
            'dot' => 'bg-warning-500',
            'pulse' => 'bg-warning-400',
            'border' => 'border-l-warning-500',
            'active_bg' => 'bg-warning-50 dark:bg-warning-400/10',
        ],
        'emerald' => [
            'color' => 'success',
            'dot' => 'bg-success-500',
            'pulse' => 'bg-success-400',
            'border' => 'border-l-success-500',
            'active_bg' => 'bg-success-50 dark:bg-success-400/10',
        ],
        'red' => [
            'color' => 'danger',
            'dot' => 'bg-danger-500',
            'pulse' => 'bg-danger-400',
            'border' => 'border-l-danger-500',
            'active_bg' => 'bg-danger-50 dark:bg-danger-400/10',
        ],
        'gray' => [
            'color' => 'gray',
            'dot' => 'bg-gray-400',
            'pulse' => 'bg-gray-300',
            'border' => 'border-l-gray-400',
            'active_bg' => 'bg-gray-50 dark:bg-white/5',
        ],
    ];

    $statusFilters = [
        'all' => ['Tất cả', null],
        'running' => ['Đang chạy', 'bg-warning-500'],
        'on' => ['Sẵn sàng', 'bg-success-500'],
        'bdsc' => ['Bảo dưỡng', 'bg-danger-500'],
        'off' => ['Tắt máy', null],
    ];
@endphp

<div class="h-full">
    <div class="flex h-full flex-col overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        {{-- Header --}}
        <div class="flex shrink-0 items-center justify-between gap-2 border-b border-gray-200 px-4 py-3 dark:border-white/10">
            <div class="flex min-w-0 items-center gap-2">
                <div class="flex size-7 shrink-0 items-center justify-center rounded-lg bg-primary-50 text-primary-600 dark:bg-primary-400/10 dark:text-primary-400">
                    <x-filament::icon icon="heroicon-m-truck" class="size-4" />
                </div>
                <span class="truncate text-base font-semibold text-gray-950 dark:text-white">Danh sách xe</span>
            </div>
            <div class="flex shrink-0 items-center gap-2">
                <x-filament::badge color="primary" title="Đã chọn / Tổng số xe">
                    <span class="tabular-nums">Đã chọn: {{ $selectedCount }} / {{ $totalFiltered }}</span>
                </x-filament::badge>
                <x-filament::icon-button
                    icon="heroicon-o-arrow-path"
                    color="gray"
                    size="sm"
                    label="Làm mới dữ liệu"
                    tooltip="Làm mới dữ liệu"
                    wire:click="$dispatch('refreshMapData')"
                />
            </div>
        </div>

        {{-- Search Bar --}}
        <div class="shrink-0 border-b border-gray-200 px-3 py-3 dark:border-white/10">
            <x-filament::input.wrapper prefix-icon="heroicon-m-magnifying-glass">
                <x-filament::input
                    type="search"
                    wire:model.live.debounce.300ms="vehicleSearch"
                    placeholder="Tìm biển số, tài xế, chuyến..."
                />
            </x-filament::input.wrapper>
        </div>

        {{-- Status Filter Pills --}}
        <div class="shrink-0 border-b border-gray-200 px-3 py-2 dark:border-white/10">
            <div class="flex flex-wrap gap-1.5">
                @foreach ($statusFilters as $val => [$label, $dot])
                    @php $isActive = $filterStatus === $val; @endphp
                    <button
                        type="button"
                        wire:click="$set('filterStatus', '{{ $val }}')"
                        @class([
                            'inline-flex min-h-7 cursor-pointer items-center gap-1.5 rounded-lg px-2.5 py-1 text-xs font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-600 motion-reduce:transition-none',
                            'bg-primary-600 text-white shadow-sm dark:bg-primary-500' => $isActive,
                            'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-white/5 dark:text-gray-200 dark:hover:bg-white/10' => ! $isActive,
                        ])
                    >
                        @if ($dot)
                            <span @class(['size-2 rounded-full', $isActive ? 'bg-white' : $dot])></span>
                        @endif
                        {{ $label }}
                    </button>
                @endforeach
            </div>
        </div>

        {{-- Action Buttons --}}
        <div class="flex shrink-0 items-center justify-between gap-2 border-b border-gray-200 bg-gray-50 px-3 py-2 dark:border-white/10 dark:bg-white/5">
            <x-filament::button
                wire:click="selectRunningOnly"
                color="warning"
                size="xs"
                icon="heroicon-m-bolt"
                outlined
                title="Chỉ chọn các xe đang chạy trên đường"
            >
                Xe đang chạy
            </x-filament::button>
            <div class="flex items-center gap-3">
                <x-filament::link tag="button" wire:click="selectAll" color="gray" size="sm">
                    Chọn hết
                </x-filament::link>
                <span class="text-gray-300 dark:text-white/20" aria-hidden="true">|</span>
                <x-filament::link tag="button" wire:click="deselectAll" color="gray" size="sm">
                    Bỏ chọn
                </x-filament::link>
            </div>
        </div>

        {{-- Vehicle Cards List (Scrollable, with Infinite Scroll) --}}
        <div class="flex-1 space-y-1.5 overflow-y-auto overscroll-contain p-2">
            @forelse($vehicles as $v)
                @php
                    $style = $sc[$v['status_color']] ?? $sc['gray'];
                @endphp
                <div
                    wire:click="toggleVehicle({{ $v['id'] }})"
                    @class([
                        'group relative flex cursor-pointer flex-col gap-1.5 rounded-lg p-2.5 transition motion-reduce:transition-none',
                        $style['border'] . ' border-l-4 ring-1 ring-gray-950/5 shadow-xs dark:ring-white/10 ' . $style['active_bg'] => $v['selected'],
                        'bg-white ring-1 ring-gray-950/5 hover:bg-gray-50 hover:ring-primary-500/50 dark:bg-white/5 dark:ring-white/10 dark:hover:bg-white/10' => ! $v['selected'],
                    ])
                >
                    {{-- Row 1: Checkbox + Plate + Status Badge --}}
                    <div class="flex items-center justify-between gap-2">
                        <div class="flex min-w-0 items-center gap-2.5">
                            <div @class([
                                'flex size-4 shrink-0 items-center justify-center rounded border transition-colors',
                                'border-primary-600 bg-primary-600' => $v['selected'],
                                'border-gray-300 bg-white dark:border-white/20 dark:bg-white/5' => ! $v['selected'],
                            ])>
                                @if($v['selected'])
                                    <x-filament::icon icon="heroicon-m-check" class="size-3.5 text-white" />
                                @endif
                            </div>
                            <span class="truncate text-sm font-semibold text-gray-950 group-hover:text-primary-600 dark:text-white dark:group-hover:text-primary-400" title="{{ $v['plate'] }}">
                                {{ $v['plate'] }}
                            </span>
                            @if($v['status'] === 'running')
                                <span class="relative flex size-2.5 shrink-0">
                                    <span class="absolute inline-flex h-full w-full animate-ping rounded-full {{ $style['pulse'] }} opacity-75 motion-reduce:animate-none"></span>
                                    <span class="relative inline-flex size-2.5 rounded-full {{ $style['dot'] }}"></span>
                                </span>
                            @else
                                <span class="inline-block size-2 shrink-0 rounded-full {{ $style['dot'] }}"></span>
                            @endif
                        </div>

                        <x-filament::badge :color="$style['color']" size="sm" class="shrink-0">
                            {{ $v['status_label'] }}
                        </x-filament::badge>
                    </div>

                    {{-- Row 2: Driver & Vehicle type --}}
                    <div class="flex items-center justify-between gap-2 ps-6.5 text-xs text-gray-500 dark:text-gray-400">
                        <div class="flex min-w-0 items-center gap-1.5">
                            <x-filament::icon icon="heroicon-m-user" class="size-3.5 shrink-0 text-gray-400 dark:text-gray-500" />
                            <span class="truncate font-medium text-gray-950 dark:text-white" title="{{ $v['driver'] }}">{{ $v['driver'] }}</span>
                            @if(!empty($v['driver_phone']))
                                <span class="shrink-0 tabular-nums">({{ $v['driver_phone'] }})</span>
                            @endif
                        </div>
                        <span class="shrink-0">{{ $v['vehicle_type'] }}</span>
                    </div>

                    {{-- Row 3: Active Trip or GPS Speed (if applicable) --}}
                    @if(!empty($v['active_trip_code']) || $v['gps_speed'] !== null)
                        <div class="flex items-center justify-between gap-2 ps-6.5 pt-0.5 text-xs">
                            @if(!empty($v['active_trip_code']))
                                <span class="inline-flex min-w-0 items-center gap-1 font-mono font-semibold text-primary-600 dark:text-primary-400">
                                    <x-filament::icon icon="heroicon-m-arrow-path-rounded-square" class="size-3.5 shrink-0" />
                                    <span class="truncate">{{ $v['active_trip_code'] }} ({{ $v['active_orders_count'] }} đơn)</span>
                                </span>
                            @else
                                <span></span>
                            @endif

                            @if($v['gps_speed'] !== null && $v['gps_speed'] > 0)
                                <x-filament::badge color="warning" size="sm" icon="heroicon-m-bolt" class="shrink-0 tabular-nums">
                                    {{ round($v['gps_speed'], 1) }} km/h
                                </x-filament::badge>
                            @endif
                        </div>
                    @endif
                </div>
            @empty
                <div class="flex flex-col items-center justify-center gap-2 px-4 py-10 text-center">
                    <div class="rounded-full bg-gray-100 p-3 dark:bg-gray-500/20">
                        <x-filament::icon icon="heroicon-o-magnifying-glass" class="size-6 text-gray-500 dark:text-gray-400" />
                    </div>
                    <p class="text-sm font-semibold text-gray-950 dark:text-white">Không tìm thấy phương tiện nào</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Thử đổi từ khóa hoặc bộ lọc trạng thái</p>
                </div>
            @endforelse

            {{-- Infinite Scroll Trigger & Loader --}}
            @if($hasMore)
                <div
                    x-intersect.threshold.20="$wire.loadMore()"
                    class="flex flex-col items-center justify-center py-3 text-center"
                >
                    <x-filament::button wire:click="loadMore" color="gray" size="xs">
                        <span wire:loading.remove wire:target="loadMore" class="tabular-nums">Hiển thị thêm xe ({{ count($vehicles) }}/{{ $totalFiltered }})</span>
                        <span wire:loading wire:target="loadMore">Đang tải thêm...</span>
                    </x-filament::button>
                </div>
            @endif
        </div>
    </div>
</div>
