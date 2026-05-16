<x-filament-panels::page>
    @php
        $timeline = $this->getTimelineData();
        $order = $timeline['order'];
        $checkpoints = $timeline['checkpoints'];

        $iconMap = [
            'started'          => ['icon' => 'heroicon-o-play-circle',         'color' => 'blue'],
            'arrived_pickup'   => ['icon' => 'heroicon-o-truck',               'color' => 'amber'],
            'left_pickup'      => ['icon' => 'heroicon-o-arrow-right-circle',  'color' => 'amber'],
            'arrived_delivery' => ['icon' => 'heroicon-o-map-pin',             'color' => 'orange'],
            'driver_swap'      => ['icon' => 'heroicon-o-arrow-path',          'color' => 'purple'],
            'completed'        => ['icon' => 'heroicon-o-check-circle',        'color' => 'emerald'],
        ];

        $colorRing = [
            'blue'    => 'ring-blue-200 dark:ring-blue-800',
            'amber'   => 'ring-amber-200 dark:ring-amber-800',
            'orange'  => 'ring-orange-200 dark:ring-orange-800',
            'purple'  => 'ring-purple-200 dark:ring-purple-800',
            'emerald' => 'ring-emerald-200 dark:ring-emerald-800',
        ];

        $colorIcon = [
            'blue'    => 'text-blue-600 dark:text-blue-400',
            'amber'   => 'text-amber-600 dark:text-amber-400',
            'orange'  => 'text-orange-600 dark:text-orange-400',
            'purple'  => 'text-purple-600 dark:text-purple-400',
            'emerald' => 'text-emerald-600 dark:text-emerald-400',
        ];
    @endphp

    <div class="space-y-6">
        {{-- Header: Thông tin chuyến đi --}}
        <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <div class="flex flex-wrap items-center gap-x-8 gap-y-2">
                <div>
                    <span class="text-xs font-medium text-gray-500 dark:text-gray-400">Mã chuyến</span>
                    <p class="text-lg font-bold text-gray-900 dark:text-white">{{ $order['order_code'] }}</p>
                </div>
                <div>
                    <span class="text-xs font-medium text-gray-500 dark:text-gray-400">Trạng thái</span>
                    <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $order['status_label'] }}</p>
                </div>
                <div>
                    <span class="text-xs font-medium text-gray-500 dark:text-gray-400">Xe</span>
                    <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $order['vehicle_plate'] }}</p>
                </div>
                <div>
                    <span class="text-xs font-medium text-gray-500 dark:text-gray-400">Lái xe</span>
                    <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $order['driver_name'] }}</p>
                </div>
            </div>
        </div>

        {{-- Timeline Section --}}
        <div x-data="{ showAll: false, limit: 5 }" class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">
            {{-- Section header --}}
            <div class="flex items-center justify-between border-b border-gray-100 px-5 py-4 dark:border-gray-800">
                <div>
                    <h3 class="text-base font-semibold text-gray-950 dark:text-white">Mốc hành trình</h3>
                    <p class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">{{ count($checkpoints) }} mốc đã ghi nhận</p>
                </div>
            </div>

            {{-- Timeline body --}}
            <div class="px-5 py-4">
                @if (empty($checkpoints))
                    {{-- Empty state --}}
                    <div class="flex flex-col items-center justify-center py-12 text-center">
                        <div class="mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-gray-100 dark:bg-gray-800">
                            <x-filament::icon icon="heroicon-o-map-pin" class="h-6 w-6 text-gray-400 dark:text-gray-500" />
                        </div>
                        <h4 class="text-base font-semibold text-gray-950 dark:text-white">Chưa có mốc hành trình</h4>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Chuyến đi này chưa có dữ liệu hành trình được ghi nhận.</p>
                    </div>
                @else
                    <div class="relative">
                        @foreach ($checkpoints as $index => $cp)
                            @php
                                $meta = $iconMap[$cp['type_value']] ?? ['icon' => 'heroicon-o-question-mark-circle', 'color' => 'gray'];
                                $ring = $colorRing[$meta['color']] ?? 'ring-gray-200 dark:ring-gray-700';
                                $iconCls = $colorIcon[$meta['color']] ?? 'text-gray-500 dark:text-gray-400';
                                $isLast = $loop->last;
                                $isHidden = !$loop->first && $index >= 5;
                            @endphp

                            <div @if ($isHidden) x-show="showAll" x-collapse @endif
                                 class="flex gap-x-4">
                                {{-- Icon + line --}}
                                <div class="relative flex flex-col items-center @if (!$isLast) after:absolute after:top-10 after:bottom-0 after:w-px after:bg-gray-200 dark:after:bg-gray-700 @endif">
                                    <div class="relative z-10 flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-full bg-white ring-1 {{ $ring }} dark:bg-gray-900">
                                        <x-filament::icon icon="{{ $meta['icon'] }}" class="h-4 w-4 {{ $iconCls }}" />
                                    </div>
                                </div>

                                {{-- Content --}}
                                <div class="grow pt-1 {{ $isLast ? 'mb-0' : 'mb-6' }}">
                                    {{-- Header row: type + time --}}
                                    <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1">
                                        <h4 class="text-sm font-semibold text-gray-900 dark:text-white">
                                            {{ $cp['type_label'] }}
                                            @if ($cp['driver_name'])
                                                <span class="font-normal text-gray-500 dark:text-gray-400">— {{ $cp['driver_name'] }}</span>
                                            @endif
                                        </h4>
                                        <span class="flex-shrink-0 text-xs font-medium text-gray-400 dark:text-gray-500">
                                            {{ $cp['occurred_at'] }}
                                        </span>
                                    </div>

                                    {{-- Address --}}
                                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ $cp['address'] }}</p>

                                    {{-- Extra info row --}}
                                    @if ($cp['km_reading'] || $cp['gps'] || $cp['voice_note'] || $cp['photo_count'] > 0)
                                        <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1">
                                            @if ($cp['km_reading'])
                                                <span class="inline-flex items-center gap-1 text-xs text-gray-500 dark:text-gray-400">
                                                    <x-filament::icon icon="heroicon-o-gauge" class="h-3.5 w-3.5" />
                                                    {{ $cp['km_reading'] }}
                                                </span>
                                            @endif
                                            @if ($cp['gps'])
                                                <span class="inline-flex items-center gap-1 text-xs text-gray-500 dark:text-gray-400">
                                                    <x-filament::icon icon="heroicon-o-map-pin" class="h-3.5 w-3.5" />
                                                    {{ $cp['gps'] }}
                                                </span>
                                            @endif
                                            @if ($cp['photo_count'] > 0)
                                                <span class="inline-flex items-center gap-1 text-xs text-gray-500 dark:text-gray-400">
                                                    <x-filament::icon icon="heroicon-o-camera" class="h-3.5 w-3.5" />
                                                    {{ $cp['photo_count'] }} ảnh
                                                </span>
                                            @endif
                                        </div>
                                    @endif

                                    @if ($cp['voice_note'])
                                        <p class="mt-1 text-xs italic text-gray-400 dark:text-gray-500">{{ $cp['voice_note'] }}</p>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>

                    {{-- Show more button --}}
                    @if (count($checkpoints) > 5)
                        <div class="mt-4 border-t border-gray-100 pt-3 dark:border-gray-800">
                            <button type="button" x-on:click="showAll = !showAll"
                                    x-show="!showAll"
                                    class="inline-flex cursor-pointer items-center gap-1.5 text-sm font-medium text-blue-600 hover:text-blue-700 dark:text-blue-400 dark:hover:text-blue-300">
                                <x-filament::icon icon="heroicon-o-chevron-down" class="h-4 w-4" />
                                <span>Xem thêm {{ count($checkpoints) - 5 }} mốc</span>
                            </button>
                            <button type="button" x-on:click="showAll = false"
                                    x-show="showAll"
                                    class="inline-flex cursor-pointer items-center gap-1.5 text-sm font-medium text-blue-600 hover:text-blue-700 dark:text-blue-400 dark:hover:text-blue-300">
                                <x-filament::icon icon="heroicon-o-chevron-up" class="h-4 w-4" />
                                <span>Thu gọn</span>
                            </button>
                        </div>
                    @endif
                @endif
            </div>
        </div>
    </div>
</x-filament-panels::page>
