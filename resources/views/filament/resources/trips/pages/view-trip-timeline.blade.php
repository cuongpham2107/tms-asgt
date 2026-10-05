<x-filament-panels::page>
    @php
        $timeline = $this->getTimelineData();
        $order = $timeline['order'];
        $checkpoints = $timeline['checkpoints'];

        $iconMap = [
            'started'          => 'heroicon-o-play-circle',
            'arrived_pickup'   => 'heroicon-o-truck',
            'left_pickup'      => 'heroicon-o-arrow-right-circle',
            'arrived_delivery' => 'heroicon-o-map-pin',
            'driver_swap'      => 'heroicon-o-arrow-path',
            'completed'        => 'heroicon-o-check-circle',
            'end'              => 'heroicon-o-flag',
            'cancelled'        => 'heroicon-o-x-circle',
        ];

        $semanticColors = ['primary', 'gray', 'success', 'warning', 'danger', 'info'];

        $statusColor = $this->getRecord()->status?->getColor();
        $statusColor = in_array($statusColor, $semanticColors, true) ? $statusColor : 'gray';
    @endphp

    <div class="space-y-6">
        {{-- Header: Thông tin chuyến đi --}}
        <x-filament::section>
            <dl class="flex flex-wrap items-center gap-x-8 gap-y-3">
                <div class="min-w-0">
                    <dt class="text-xs text-gray-500 dark:text-gray-400">Mã chuyến</dt>
                    <dd class="mt-1 truncate text-lg font-semibold text-gray-950 dark:text-white" title="{{ $order['order_code'] }}">{{ $order['order_code'] }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-gray-500 dark:text-gray-400">Trạng thái</dt>
                    <dd class="mt-1 flex">
                        <x-filament::badge :color="$statusColor">{{ $order['status_label'] }}</x-filament::badge>
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-gray-500 dark:text-gray-400">Xe</dt>
                    <dd class="mt-1 text-sm font-semibold text-gray-950 dark:text-white">{{ $order['vehicle_plate'] }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-gray-500 dark:text-gray-400">Lái xe</dt>
                    <dd class="mt-1 text-sm font-semibold text-gray-950 dark:text-white">{{ $order['driver_name'] }}</dd>
                </div>
            </dl>
        </x-filament::section>

        {{-- Timeline Section --}}
        <div x-data="{ showAll: false, limit: 5 }">
            <x-filament::section icon="heroicon-o-map" heading="Mốc hành trình">
                <x-slot name="description">
                    <span class="tabular-nums">{{ count($checkpoints) }}</span> mốc đã ghi nhận
                </x-slot>

                @if (empty($checkpoints))
                    {{-- Empty state --}}
                    <div class="flex flex-col items-center justify-center py-12 text-center">
                        <div class="mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-gray-100 dark:bg-gray-500/20">
                            <x-filament::icon icon="heroicon-o-map-pin" class="h-6 w-6 text-gray-500 dark:text-gray-400" />
                        </div>
                        <h4 class="text-base font-semibold text-gray-950 dark:text-white">Chưa có mốc hành trình</h4>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Chuyến đi này chưa có dữ liệu hành trình được ghi nhận.</p>
                    </div>
                @else
                    <ol class="relative">
                        @foreach ($checkpoints as $index => $cp)
                            @php
                                $icon = $iconMap[$cp['type_value']] ?? 'heroicon-o-question-mark-circle';
                                $color = in_array($cp['type_color'] ?? null, $semanticColors, true) ? $cp['type_color'] : 'gray';
                                $isLast = $loop->last;
                                $isHidden = !$loop->first && $index >= 5;
                            @endphp

                            <li @if ($isHidden) x-show="showAll" x-collapse @endif
                                 class="flex gap-x-4">
                                {{-- Icon + line --}}
                                <div @class([
                                    'relative flex flex-col items-center',
                                    'after:absolute after:top-8 after:bottom-0 after:w-px after:bg-gray-200 dark:after:bg-white/10' => ! $isLast,
                                ])>
                                    <div class="fi-color-{{ $color }} relative z-10 flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-(--color-50) text-(--color-600) ring-1 ring-(--color-600)/20 dark:bg-(--color-400)/10 dark:text-(--color-400) dark:ring-(--color-400)/30">
                                        <x-filament::icon :icon="$icon" class="h-4 w-4" />
                                    </div>
                                </div>

                                {{-- Content --}}
                                <div class="min-w-0 grow pt-1 {{ $isLast ? 'mb-0' : 'mb-6' }}">
                                    {{-- Header row: type + time --}}
                                    <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1">
                                        <h4 class="text-sm font-semibold text-gray-950 dark:text-white">
                                            {{ $cp['type_label'] }}
                                            @if ($cp['driver_name'])
                                                <span class="font-normal text-gray-500 dark:text-gray-400">— {{ $cp['driver_name'] }}</span>
                                            @endif
                                        </h4>
                                        <span class="shrink-0 text-xs tabular-nums text-gray-500 dark:text-gray-400">
                                            {{ $cp['occurred_at'] }}
                                        </span>
                                    </div>

                                    {{-- Address --}}
                                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $cp['address'] }}</p>

                                    {{-- Extra info row --}}
                                    @if ($cp['gps'] || $cp['voice_note'] || $cp['photo_count'] > 0)
                                        <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1">
                                            @if ($cp['gps'])
                                                <span class="inline-flex items-center gap-1 text-xs tabular-nums text-gray-500 dark:text-gray-400">
                                                    <x-filament::icon icon="heroicon-m-map-pin" class="h-3.5 w-3.5 text-gray-400 dark:text-gray-500" />
                                                    {{ $cp['gps'] }}
                                                </span>
                                            @endif
                                            @if ($cp['photo_count'] > 0)
                                                <span class="inline-flex items-center gap-1 text-xs text-gray-500 dark:text-gray-400">
                                                    <x-filament::icon icon="heroicon-m-camera" class="h-3.5 w-3.5 text-gray-400 dark:text-gray-500" />
                                                    <span class="tabular-nums">{{ $cp['photo_count'] }}</span> ảnh
                                                </span>
                                            @endif
                                        </div>
                                    @endif

                                    @if ($cp['voice_note'])
                                        <p class="mt-1 text-xs italic text-gray-500 dark:text-gray-400">{{ $cp['voice_note'] }}</p>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ol>

                    {{-- Show more button --}}
                    @if (count($checkpoints) > 5)
                        <div class="mt-4 border-t border-gray-200 pt-3 dark:border-white/10">
                            <x-filament::link tag="button" type="button" x-on:click="showAll = !showAll" x-show="!showAll" icon="heroicon-m-chevron-down" size="sm">
                                Xem thêm {{ count($checkpoints) - 5 }} mốc
                            </x-filament::link>
                            <x-filament::link tag="button" type="button" x-on:click="showAll = false" x-show="showAll" icon="heroicon-m-chevron-up" size="sm">
                                Thu gọn
                            </x-filament::link>
                        </div>
                    @endif
                @endif
            </x-filament::section>
        </div>
    </div>
</x-filament-panels::page>
