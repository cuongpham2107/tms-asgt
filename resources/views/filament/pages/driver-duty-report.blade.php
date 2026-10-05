<x-filament-panels::page>
    {{-- Station filter pills --}}
    <div class="flex flex-wrap items-center gap-2">
        <x-filament::button
            type="button"
            wire:click="filterStation('all')"
            :color="$activeStationFilter === 'all' ? 'primary' : 'gray'"
            size="sm"
        >
            Tất cả
        </x-filament::button>
        @foreach (\App\Enums\OnDutyLocation::cases() as $station)
            <x-filament::button
                type="button"
                wire:click="filterStation('{{ $station->value }}')"
                :color="$activeStationFilter === $station->value ? $station->getColor() : 'gray'"
                :icon="$activeStationFilter === $station->value ? 'heroicon-m-check' : null"
                size="sm"
            >
                {{ $station->getLabel() }}
            </x-filament::button>
        @endforeach
    </div>

    {{-- Grid 2-1 layout --}}
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        {{-- Left: summary table (col-span-2) --}}
        <div class="min-w-0 lg:col-span-2">
           {{ $this->table }}
        </div>

        {{-- Right: main table (col-span-1) --}}
        <div class="min-w-0 lg:col-span-1">

            @php $data = $this->getSummaryData(); @endphp
            @php
                $th = 'border border-gray-200 px-3 py-2 text-center text-sm font-semibold text-gray-950 dark:border-white/10 dark:text-white';
                $thSub = 'border border-gray-200 px-3 py-1 text-center text-xs font-medium text-gray-500 dark:border-white/10 dark:text-gray-400';
                $td = 'border border-gray-200 px-3 py-2 text-center tabular-nums dark:border-white/10';
                $thFirst = 'border border-gray-200 px-4 py-2 text-left text-sm font-semibold text-gray-950 dark:border-white/10 dark:text-white';
                $tdFirst = 'border border-gray-200 px-4 py-2 text-left dark:border-white/10';
            @endphp
            <div class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="border-b border-gray-200 px-4 py-3 dark:border-white/10">
                    <h3 class="text-base font-semibold text-gray-950 dark:text-white">Tổng hợp ca trực lái xe</h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full border-collapse text-sm text-gray-950 dark:text-white">
                        <thead class="bg-gray-50 dark:bg-white/5">
                            <tr>
                                <th class="{{ $thFirst }}">Điểm trực</th>
                                <th class="{{ $th }}" colspan="4">Đi làm</th>
                                <th class="{{ $th }}">Nghỉ</th>
                                <th class="{{ $th }}">TTL</th>
                            </tr>
                            <tr>
                                <th class="{{ $thSub }}"></th>
                                <th class="{{ $thSub }}">TTL</th>
                                <th class="{{ $thSub }}">X/2</th>
                                <th class="{{ $thSub }}">Y/2</th>
                                <th class="{{ $thSub }}">X</th>
                                <th class="{{ $thSub }}"></th>
                                <th class="{{ $thSub }}"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($data['stations'] as $s)
                            <tr class="transition duration-75 hover:bg-gray-50 dark:hover:bg-white/5">
                                <td class="{{ $tdFirst }} font-medium">{{ $s['label'] }}</td>
                                <td class="{{ $td }} font-semibold">{{ $s['working_ttl'] }}</td>
                                <td class="{{ $td }}">{{ $s['morning_half'] }}</td>
                                <td class="{{ $td }}">{{ $s['night_half'] }}</td>
                                <td class="{{ $td }}">{{ $s['full'] }}</td>
                                <td class="{{ $td }} text-danger-600 dark:text-danger-400">{{ $s['off'] }}</td>
                                <td class="{{ $td }} font-semibold">{{ $s['total'] }}</td>
                            </tr>
                            @endforeach
                            <tr class="bg-gray-50 font-semibold dark:bg-white/5">
                                <td class="{{ $tdFirst }}">Tổng lái xe</td>
                                <td class="{{ $td }}">{{ $data['grand']['working_ttl'] }}</td>
                                <td class="{{ $td }}">{{ $data['grand']['morning_half'] }}</td>
                                <td class="{{ $td }}">{{ $data['grand']['night_half'] }}</td>
                                <td class="{{ $td }}">{{ $data['grand']['full'] }}</td>
                                <td class="{{ $td }} text-danger-600 dark:text-danger-400">{{ $data['grand']['off'] }}</td>
                                <td class="{{ $td }}">{{ $data['grand']['total'] }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</x-filament-panels::page>
