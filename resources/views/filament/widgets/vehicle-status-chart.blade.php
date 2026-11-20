@php
    $percent = fn (int $count, int $of): float => $of > 0 ? round($count / $of * 100, 1) : 0;
@endphp

<x-filament-widgets::widget>
    <x-filament::section
        heading="Trạng thái xe"
        :description="'Thời gian thực · ' . $total . ' xe công ty (không tính nghỉ / bảo dưỡng) · cập nhật ' . $updatedAt"
    >
        <div wire:poll.30s class="space-y-4">
            <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                @foreach ($statuses as $key => $status)
                    <div class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-white/5">
                        <div class="flex items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400">
                            <span class="size-2 rounded-full" style="background: {{ $status['color'] }}"></span>
                            {{ $status['label'] }}
                        </div>
                        <div class="mt-0.5 flex items-baseline gap-1.5">
                            <span class="text-2xl font-bold tabular-nums text-gray-950 dark:text-white">{{ $totals[$key] }}</span>
                            <span class="text-xs tabular-nums text-gray-400">{{ $percent($totals[$key], $total) }}%</span>
                        </div>
                    </div>
                @endforeach
            </div>

            @if ($total > 0)
                <div class="flex h-2.5 overflow-hidden rounded-full bg-gray-100 dark:bg-white/5">
                    @foreach ($statuses as $key => $status)
                        @if ($totals[$key] > 0)
                            <div style="width: {{ $percent($totals[$key], $total) }}%; background: {{ $status['color'] }}" title="{{ $status['label'] }}: {{ $totals[$key] }} xe"></div>
                        @endif
                    @endforeach
                </div>

                <div class="space-y-2.5 border-t border-gray-100 pt-3 dark:border-white/5">
                    <div class="text-xs font-medium uppercase tracking-wide text-gray-400">Theo khu vực</div>
                    @foreach ($areas as $area)
                        @php($areaTotal = array_sum($area['counts']))
                        <div class="grid grid-cols-[9.5rem_1fr_2.5rem] items-center gap-3">
                            <span class="truncate text-sm text-gray-700 dark:text-gray-200" title="{{ $area['title'] }}">{{ $area['title'] }}</span>
                            <div class="flex h-5 overflow-hidden rounded bg-gray-100 dark:bg-white/5">
                                @foreach ($statuses as $key => $status)
                                    @if ($area['counts'][$key] > 0)
                                        @php($width = $percent($area['counts'][$key], $areaTotal))
                                        <div
                                            class="flex items-center justify-center text-[11px] font-semibold text-white"
                                            style="width: {{ $width }}%; background: {{ $status['color'] }}"
                                            title="{{ $status['label'] }}: {{ $area['counts'][$key] }} xe"
                                        >{{ $width >= 10 ? $area['counts'][$key] : '' }}</div>
                                    @endif
                                @endforeach
                            </div>
                            <span class="text-right text-sm font-semibold tabular-nums text-gray-950 dark:text-white">{{ $areaTotal }}</span>
                        </div>
                    @endforeach
                </div>
            @else
                <p class="py-6 text-center text-sm text-gray-500">Chưa có xe nào đang hoạt động.</p>
            @endif
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
