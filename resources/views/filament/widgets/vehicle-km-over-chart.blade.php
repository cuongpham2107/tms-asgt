@php
    $fmt = fn (float $value): string => number_format($value, 0, ',', '.');
    $limitPercent = $limit / $scale * 100;
    $rowClasses = [
        'red' => 'bg-rose-50 dark:bg-rose-500/10',
        'orange' => 'bg-orange-50 dark:bg-orange-500/10',
        'light' => 'bg-yellow-50 dark:bg-yellow-500/10',
    ];
    $barClasses = [
        'red' => 'bg-rose-500',
        'orange' => 'bg-orange-400',
        'light' => 'bg-yellow-400',
    ];
@endphp

<x-filament-widgets::widget>
    {{-- Màn lớn: bảng cao bằng khối 4 biểu đồ bên trái và cuộn bên trong, không tự kéo cao hàng. --}}
    <style>
        @media (min-width: 1024px) {
            .km-over-section { height: 100%; display: flex; flex-direction: column; }
            .km-over-section > .fi-section-content-ctn,
            .km-over-section .fi-section-content { flex: 1; min-height: 0; display: flex; flex-direction: column; }
        }
    </style>

    <x-filament::section
        class="km-over-section"
        heading="Xếp hạng KM xe trong chu kỳ"
        :description="'Tính từ ngày 23 · định mức ' . $fmt($limit) . ' km/xe · vượt ' . $fmt(\App\Filament\Widgets\VehicleKmOverChartWidget::OVER_FEE_PER_KM) . ' đ/km'"
    >
        <div class="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-2">
            <div class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-white/5">
                <div class="text-xs text-gray-500 dark:text-gray-400">Số xe kiểm soát</div>
                <div class="text-lg font-bold text-gray-900 dark:text-white">{{ count($rows) }}</div>
            </div>
            <div class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-white/5">
                <div class="text-xs text-gray-500 dark:text-gray-400">Tổng KM over</div>
                <div class="font-mono text-lg font-bold text-gray-900 dark:text-white">{{ $fmt($totalKm) }}</div>
            </div>
            <div class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-white/5" title="Tổng KM over ÷ (số xe × {{ $fmt($limit) }} km)">
                <div class="text-xs text-gray-500 dark:text-gray-400">Hệ số kiểm soát</div>
                <div @class([
                    'font-mono text-lg font-bold',
                    'text-rose-600' => $coefficient >= 1,
                    'text-gray-900 dark:text-white' => $coefficient < 1,
                ])>{{ number_format($coefficient, 3, ',', '.') }}</div>
            </div>
            <div class="rounded-lg bg-rose-50 px-3 py-2 dark:bg-rose-500/10">
                <div class="text-xs text-rose-700 dark:text-rose-300">Tổng chi phí vượt KM</div>
                <div class="font-mono text-lg font-bold text-rose-600 dark:text-rose-400">{{ $fmt($overCost) }} đ</div>
            </div>
        </div>

        @if (count($rows) === 0)
            <p class="py-6 text-center text-sm text-gray-500">Chưa có xe nào có dữ liệu KM chu kỳ.</p>
        @else
            <div class="lg:relative lg:min-h-80 lg:flex-1">
            <div class="max-h-[32rem] overflow-y-auto rounded-lg ring-1 ring-gray-200 lg:absolute lg:inset-0 lg:max-h-none dark:ring-white/10">
                <table class="w-full text-sm">
                    <thead class="sticky top-0 z-10 bg-gray-50 text-xs text-gray-500 dark:bg-gray-900 dark:text-gray-400">
                        <tr>
                            <th class="w-10 px-2 py-2 text-center">#</th>
                            <th class="px-2 py-2 text-left">BSX</th>
                            <th class="hidden px-2 py-2 text-left sm:table-cell lg:hidden 2xl:table-cell">Chủ xe</th>
                            <th class="px-2 py-2 text-center">Tải trọng</th>
                            <th class="px-2 py-2 text-right">KM over</th>
                            <th class="w-1/2 px-3 py-2 text-left">Định mức {{ $fmt($limit) }} km</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($rows as $index => $row)
                            <tr @class([$rowClasses[$row['level']] ?? ''])>
                                <td class="px-2 py-1.5 text-center text-gray-500">{{ $index + 1 }}</td>
                                <td class="whitespace-nowrap px-2 py-1.5 font-semibold text-gray-900 dark:text-white">{{ $row['plate'] }}</td>
                                <td class="hidden px-2 py-1.5 text-gray-600 sm:table-cell lg:hidden 2xl:table-cell dark:text-gray-300">{{ $row['owner'] }}</td>
                                <td class="px-2 py-1.5 text-center text-gray-600 dark:text-gray-300">{{ $row['capacity'] ?? '—' }}</td>
                                <td @class([
                                    'px-2 py-1.5 text-right font-mono font-semibold',
                                    'text-rose-600' => $row['level'] === 'red',
                                    'text-gray-900 dark:text-white' => $row['level'] !== 'red',
                                ])>{{ $fmt($row['km']) }}</td>
                                <td class="px-3 py-1.5">
                                    <div class="relative h-3.5 rounded bg-gray-100 dark:bg-white/5">
                                        <div
                                            @class(['h-full rounded', $barClasses[$row['level']] ?? 'bg-sky-500'])
                                            style="width: {{ round($row['km'] / $scale * 100, 2) }}%"
                                        ></div>
                                        <div class="absolute inset-y-[-2px] w-px bg-rose-500" style="left: {{ round($limitPercent, 2) }}%"></div>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
