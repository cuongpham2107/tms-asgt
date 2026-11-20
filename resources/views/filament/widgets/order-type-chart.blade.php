@php
    $fmt = fn (int $value): string => number_format($value, 0, ',', '.');
    $percent = fn (int $count): int => $total > 0 ? (int) round($count / $total * 100) : 0;
@endphp

<x-filament-widgets::widget>
    <x-filament::section
        heading="Cơ cấu loại hàng hoá"
        :description="'Tổng ' . $fmt($total) . ' đơn theo ngày dự kiến đóng hàng'"
    >
        <x-slot name="afterHeader">
            @include('filament.widgets.partials.period-select')
        </x-slot>

        <div class="space-y-4">
            <div class="grid grid-cols-2 gap-3">
                @foreach ($types as $type)
                    <div class="rounded-lg bg-gray-50 px-4 py-3 dark:bg-white/5">
                        <div class="flex items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400">
                            <span class="size-2 rounded-full" style="background: {{ $type['color'] }}"></span>
                            {{ $type['label'] }}
                        </div>
                        <div class="mt-1 flex items-baseline gap-2">
                            <span class="text-3xl font-bold tabular-nums text-gray-950 dark:text-white">{{ $percent($type['count']) }}%</span>
                            <span class="text-sm tabular-nums text-gray-500">{{ $fmt($type['count']) }} đơn</span>
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="flex h-3 overflow-hidden rounded-full bg-gray-100 dark:bg-white/5">
                @foreach ($types as $type)
                    @if ($type['count'] > 0)
                        <div style="width: {{ $type['count'] / $total * 100 }}%; background: {{ $type['color'] }}" title="{{ $type['label'] }}: {{ $fmt($type['count']) }} đơn"></div>
                    @endif
                @endforeach
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
