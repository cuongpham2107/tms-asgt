@php
    $fmt = fn (int $value): string => number_format($value, 0, ',', '.');
@endphp

<x-filament-widgets::widget>
    <x-filament::section
        heading="Sản lượng theo khu vực"
        :description="'Tổng ' . $fmt($total) . ' đơn theo ngày dự kiến đóng hàng'"
    >
        <x-slot name="afterHeader">
            @include('filament.widgets.partials.period-select')
        </x-slot>

        @if ($total === 0)
            <p class="py-6 text-center text-sm text-gray-500">Chưa có đơn hàng trong kỳ.</p>
        @else
            <ol class="space-y-3">
                @foreach ($rows as $row)
                    <li class="space-y-1">
                        <div class="flex items-baseline justify-between gap-3 text-sm">
                            <span class="flex min-w-0 items-baseline gap-2">
                                <span class="shrink-0 rounded bg-sky-50 px-1.5 py-0.5 text-[11px] font-bold text-sky-700 dark:bg-sky-500/10 dark:text-sky-300">{{ $row['code'] }}</span>
                                <span class="truncate text-gray-600 dark:text-gray-300" title="{{ $row['name'] }}">{{ $row['name'] }}</span>
                            </span>
                            <span class="shrink-0 tabular-nums">
                                <span class="font-semibold text-gray-950 dark:text-white">{{ $fmt($row['count']) }}</span>
                                <span class="ml-1 text-xs text-gray-400">{{ round($row['count'] / $total * 100) }}%</span>
                            </span>
                        </div>
                        <div class="h-1.5 rounded-full bg-gray-100 dark:bg-white/5">
                            <div class="h-full rounded-full bg-sky-600" style="width: {{ round($row['count'] / $max * 100, 1) }}%"></div>
                        </div>
                    </li>
                @endforeach
            </ol>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
