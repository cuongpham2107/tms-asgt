<x-filament-panels::page>
    {{-- Date filter pills --}}
    <div class="mb-3 flex items-center gap-2">
        @foreach ([
            'today' => 'Hôm nay',
            'week' => 'Tuần này',
            'month' => 'Tháng này',
        ] as $key => $label)
            <button
                type="button"
                wire:click="filterDate('{{ $key }}')"
                @class([
                    'inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-xs font-semibold transition-all duration-150 whitespace-nowrap cursor-pointer',
                    'bg-[#008fd5] border-transparent text-white shadow-sm' => $activeDateFilter === $key,
                    'border-gray-200 bg-white text-gray-600 hover:border-gray-300 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-400 dark:hover:border-gray-500 dark:hover:bg-gray-700' => $activeDateFilter !== $key,
                ])
            >
                @if ($activeDateFilter === $key)
                    <span class="h-1.5 w-1.5 rounded-full bg-white shrink-0"></span>
                @endif
                <span>{{ $label }}</span>
            </button>
        @endforeach
    </div>

    {{-- Station filter pills --}}
    <div class="mb-4 flex items-center gap-2">
        <button
            type="button"
            wire:click="filterStation('all')"
            @class([
                'inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-xs font-semibold transition-all duration-150 whitespace-nowrap cursor-pointer',
                'bg-[#008fd5] border-transparent text-white shadow-sm' => $activeStationFilter === 'all',
                'border-gray-200 bg-white text-gray-600 hover:border-gray-300 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-400 dark:hover:border-gray-500 dark:hover:bg-gray-700' => $activeStationFilter !== 'all',
            ])
        >
            <span>Tất cả</span>
        </button>
        @foreach (\App\Enums\OnDutyLocation::cases() as $station)
            @php
                $color = match($station->value) {
                    'TN' => 'bg-sky-500',
                    'BN' => 'bg-amber-500',
                    'NBA' => 'bg-emerald-500',
                    default => 'bg-[#008fd5]',
                };
            @endphp
            <button
                type="button"
                wire:click="filterStation('{{ $station->value }}')"
                @class([
                    'inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-xs font-semibold transition-all duration-150 whitespace-nowrap cursor-pointer',
                    $color . ' border-transparent text-white shadow-sm' => $activeStationFilter === $station->value,
                    'border-gray-200 bg-white text-gray-600 hover:border-gray-300 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-400 dark:hover:border-gray-500 dark:hover:bg-gray-700' => $activeStationFilter !== $station->value,
                ])
            >
                @if ($activeStationFilter === $station->value)
                    <span class="h-1.5 w-1.5 rounded-full bg-white shrink-0"></span>
                @endif
                <span>{{ $station->getLabel() }}</span>
            </button>
        @endforeach
    </div>

    {{-- Grid 2-1 layout --}}
    <div class="grid grid-cols-3 gap-4">
        {{-- Left: summary table (col-span-2) --}}
        <div class="col-span-2">
            @php $data = $this->getSummaryData(); @endphp
            <div class="rounded-xl border border-gray-200 bg-white shadow-sm">
                <div class="border-b border-gray-200 px-4 py-3">
                    <h3 class="text-sm font-semibold text-gray-900">Tổng hợp ca trực lái xe</h3>
                </div>
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b bg-gray-50">
                            <th class="px-4 py-2 text-left font-medium text-gray-700">Điểm trực</th>
                            <th class="px-3 py-2 text-center font-medium text-gray-700" colspan="3">Đi làm</th>
                            <th class="px-3 py-2 text-center font-medium text-gray-700">Nghỉ</th>
                            <th class="px-3 py-2 text-center font-medium text-gray-700">TTL</th>
                        </tr>
                        <tr class="border-b bg-gray-50 text-xs text-gray-500">
                            <th></th>
                            <th class="px-3 py-1 text-center">TTL</th>
                            <th class="px-3 py-1 text-center">X</th>
                            <th class="px-3 py-1 text-center">Y/2</th>
                            <th class="px-3 py-1 text-center">X</th>
                            <th class="px-3 py-1 text-center"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($data['stations'] as $s)
                        <tr class="border-b hover:bg-gray-50">
                            <td class="px-4 py-2 font-medium">{{ $s['label'] }}</td>
                            <td class="px-3 py-2 text-center">{{ $s['working'] }}</td>
                            <td class="px-3 py-2 text-center">{{ $s['full'] }}</td>
                            <td class="px-3 py-2 text-center">{{ $s['half'] }}</td>
                            <td class="px-3 py-2 text-center text-red-600">{{ $s['off'] }}</td>
                            <td class="px-3 py-2 text-center font-bold">{{ $s['total'] }}</td>
                        </tr>
                        @endforeach
                        <tr class="border-t-2 border-gray-300 bg-gray-100 font-bold">
                            <td class="px-4 py-2">Tổng lái xe</td>
                            <td class="px-3 py-2 text-center">{{ $data['grand']['working'] }}</td>
                            <td class="px-3 py-2 text-center">{{ $data['grand']['full'] }}</td>
                            <td class="px-3 py-2 text-center">{{ $data['grand']['half'] }}</td>
                            <td class="px-3 py-2 text-center text-red-600">{{ $data['grand']['off'] }}</td>
                            <td class="px-3 py-2 text-center">{{ $data['grand']['total'] }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Right: main table (col-span-1) --}}
        <div class="col-span-1">
            {{ $this->table }}
        </div>
    </div>
</x-filament-panels::page>
