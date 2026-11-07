{{-- Thẻ đánh giá: lái xe đang chọn (so với trung bình đội xe) hoặc tổng quan đội xe khi chưa chọn ai --}}
@php
    $subject = $selected ?? $fleet;
    $minutes = fn (?int $value): string => $this::formatMinutes($value);
    // Chênh lệch % so với đội xe; $lowerIsBetter đổi màu tốt / xấu
    $delta = function (string $key, bool $lowerIsBetter = true) use ($selected, $fleet): ?array {
        if ($selected === null || ! $selected[$key] || ! $fleet[$key]) {
            return null;
        }

        $percent = (int) round(($selected[$key] - $fleet[$key]) / $fleet[$key] * 100);
        $isGood = $lowerIsBetter ? $percent <= 0 : $percent >= 0;

        return ['text' => ($percent > 0 ? '+' : '').$percent.'% so với đội', 'good' => $isGood];
    };
    $tiles = [
        ['label' => 'Chuyến', 'value' => $subject['trip_count'], 'hint' => $subject['order_count'].' đơn', 'delta' => null],
        ['label' => 'Tổng km', 'value' => number_format($subject['km_total'], 1), 'hint' => null, 'delta' => null],
        ['label' => 'Chạy TB / đơn', 'value' => $minutes($subject['avg_travel']), 'hint' => 'B → C', 'delta' => $delta('avg_travel')],
        ['label' => 'Chờ TB / đơn', 'value' => $minutes($subject['avg_wait']), 'hint' => 'Đóng A→B + hạ C→D', 'delta' => $delta('avg_wait')],
        ['label' => 'Tốc độ chạy', 'value' => $subject['avg_speed'] === null ? '—' : number_format($subject['avg_speed'], 1).' km/h', 'hint' => null, 'delta' => $delta('avg_speed', lowerIsBetter: false)],
        ['label' => 'Chờ > '.$longWait.'h', 'value' => $subject['long_wait_count'], 'hint' => 'đơn', 'delta' => null],
    ];
    $runPercent = 100 - $subject['wait_percent'];
@endphp

<div class="overflow-hidden rounded-xl bg-white shadow-xs ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 xl:col-span-2">
    <div class="flex items-center justify-between gap-2 px-4 py-3 text-white dark:bg-slate-950" style="{{ $navy }}">
        <div class="min-w-0">
            <div class="truncate text-sm font-bold uppercase tracking-wider">{{ $selected['driver_name'] ?? 'Toàn đội xe' }}</div>
            <div class="text-[11px] text-white/70">{{ $selected ? 'So với trung bình đội xe đang lọc' : 'Bấm một lái xe trong bảng để xem chi tiết' }} · {{ $this->periodLabel() }}</div>
        </div>
        @if ($selected)
            <button type="button" wire:click="selectDriver({{ $selected['driver_id'] }})" class="shrink-0 rounded p-1 text-white/70 transition hover:bg-white/10 hover:text-white" title="Bỏ chọn">
                <x-filament::icon icon="heroicon-m-x-mark" class="h-4 w-4" />
            </button>
        @endif
    </div>

    <div class="grid grid-cols-2 gap-px bg-gray-100 sm:grid-cols-3 dark:bg-white/5">
        @foreach ($tiles as $tile)
            <div class="bg-white px-3 py-2.5 dark:bg-gray-900">
                <div class="text-[10px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $tile['label'] }}</div>
                <div class="mt-0.5 font-mono text-lg font-bold leading-tight text-gray-950 dark:text-white">{{ $tile['value'] }}</div>
                @if ($tile['delta'])
                    <div @class([
                        'text-[10px] font-semibold',
                        'text-emerald-600 dark:text-emerald-400' => $tile['delta']['good'],
                        'text-rose-600 dark:text-rose-400' => ! $tile['delta']['good'],
                    ])>{{ $tile['delta']['text'] }}</div>
                @elseif ($tile['hint'])
                    <div class="text-[10px] text-gray-400">{{ $tile['hint'] }}</div>
                @endif
            </div>
        @endforeach
    </div>

    {{-- Tỉ lệ chạy / chờ --}}
    @if ($subject['trip_count'] > 0)
    <div class="space-y-1.5 px-4 py-3">
        <div class="flex justify-between text-[11px] font-semibold">
            <span class="text-sky-700 dark:text-sky-400">Chạy {{ $runPercent }}%</span>
            <span class="text-amber-700 dark:text-amber-400">Chờ {{ $subject['wait_percent'] }}%</span>
        </div>
        <div class="flex h-2.5 overflow-hidden rounded-full bg-gray-100 dark:bg-white/10">
            <div style="width: {{ $runPercent }}%; background-color: rgb(14 165 233);"></div>
            <div style="width: {{ $subject['wait_percent'] }}%; background-color: rgb(245 158 11);"></div>
        </div>
        @if ($selected)
            <div class="text-[10px] text-gray-400">Đội xe: chạy {{ 100 - $fleet['wait_percent'] }}% · chờ {{ $fleet['wait_percent'] }}%</div>
        @endif
    </div>
    @endif
</div>
