@php
    $vehicles = $this->getVehicles();
    $selectedCount = count(array_filter($vehicles, fn($v) => $v['selected']));
    $sc = [
        'amber' => ['dot' => 'bg-amber-500', 'badge' => 'bg-amber-100 text-amber-700', 'border' => 'border-l-amber-400'],
        'emerald' => ['dot' => 'bg-emerald-500', 'badge' => 'bg-emerald-100 text-emerald-700', 'border' => 'border-l-emerald-400'],
        'red' => ['dot' => 'bg-red-500', 'badge' => 'bg-red-100 text-red-700', 'border' => 'border-l-red-400'],
        'gray' => ['dot' => 'bg-gray-400', 'badge' => 'bg-gray-100 text-gray-600', 'border' => 'border-l-gray-300'],
    ];
@endphp

<x-filament-widgets::widget>
    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">
        {{-- Header --}}
        <div class="flex items-center justify-between border-b border-gray-100 px-5 py-3 dark:border-gray-700">
            <span class="text-sm font-semibold text-gray-900 dark:text-white">Danh sách xe</span>
            <div class="flex items-center gap-2">
                <span class="rounded-md bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-500 dark:bg-gray-800 dark:text-gray-400">{{ $selectedCount }}/{{ count($vehicles) }}</span>
                <button
                    wire:click="$dispatch('refreshMapData')"
                    type="button"
                    class="relative flex h-7 w-7 items-center justify-center rounded-lg text-gray-400 transition-colors hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-gray-800 dark:hover:text-gray-300"
                    title="Làm mới"
                    wire:loading.attr="disabled"
                >
                    <x-filament::icon icon="heroicon-o-arrow-path" class="h-4 w-4" wire:loading.class="animate-spin" wire:target="refreshMapData" />
                </button>
            </div>
        </div>

        {{-- Search --}}
        <div class="border-b border-gray-100 px-5 py-3 dark:border-gray-700">
            <x-filament::input.wrapper>
                <x-filament::input
                    type="search"
                    wire:model.live="vehicleSearch"
                    placeholder="Tìm biển số, tài xế..."
                />
            </x-filament::input.wrapper>
        </div>

        {{-- Filters --}}
        <div class="border-b border-gray-100 px-5 py-3 space-y-2 dark:border-gray-700">
            <div class="text-xs font-medium text-gray-500 pb-2">
                <span>Bộ lọc:</span>
            </div>
            <div class="flex flex-col gap-2">
                <x-filament::input.wrapper>
                    <x-filament::input.select
                        wire:model.live="filterStatus"
                        placeholder="Chọn trạng thái"
                    >
                        @foreach([
                            'all' => 'Tất cả trạng thái',
                            'Running' => 'Đang chạy',
                            'On' => 'Sẵn sàng',
                            'Bdsc' => 'Bảo dưỡng',
                            'Off' => 'Tắt máy',
                        ] as $val => $label)
                            <option value="{{ $val }}">{{ $label }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
                <x-filament::input.wrapper>
                    <x-filament::input.select
                        wire:model.live="filterVehicleType"
                        placeholder="Tất cả loại xe"
                    >
                        @foreach($this->getVehicleTypeOptions() as $val => $label)
                            <option value="{{ $val }}">{{ $label }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </div>
        </div>

        {{-- Buttons --}}
        <div class="flex gap-2 border-b border-gray-100 px-5 py-3 dark:border-gray-700">
            <x-filament::button size="xs" wire:click="selectAll" color="primary" wire:loading.attr="disabled" wire:target="selectAll">
                <span wire:loading.remove wire:target="selectAll">Chọn tất cả</span>
                <span wire:loading wire:target="selectAll" class="flex items-center gap-1.5">
                    <x-filament::loading-indicator class="h-3.5 w-3.5" />
                    Đang tải...
                </span>
            </x-filament::button>
            <x-filament::button size="xs" wire:click="deselectAll" color="gray" wire:loading.attr="disabled" wire:target="deselectAll">
                <span wire:loading.remove wire:target="deselectAll">Bỏ chọn</span>
                <span wire:loading wire:target="deselectAll" class="flex items-center gap-1.5">
                    <x-filament::loading-indicator class="h-3.5 w-3.5" />
                    Đang tải...
                </span>
            </x-filament::button>
        </div>

        {{-- Vehicle list --}}
        <div class="max-h-[420px] overflow-y-auto overscroll-contain p-3">
            @forelse($vehicles as $v)
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
                        <span class="ml-2 shrink-0" wire:loading wire:target="toggleVehicle">
                            <x-filament::loading-indicator class="h-4 w-4 text-gray-400" />
                        </span>
                    </div>
                </div>
            @empty
                <div class="px-4 py-10 text-center text-sm text-gray-400">Không có xe nào</div>
            @endforelse
        </div>
    </div>
</x-filament-widgets::widget>
