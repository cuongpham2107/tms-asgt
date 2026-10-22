<x-filament-panels::page>
    @php
        $totals = $this->totals;
        $rows = $this->driverRows;
        $selectedTrips = $this->selectedTrips;
        $selectedDriver = $rows->firstWhere('driver_id', $this->driverId);
        $card = 'rounded-xl bg-white p-4 shadow-xs ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10';
    @endphp

    {{-- Tìm tài xế (kỳ chọn ở nút header) --}}
    <x-filament::input.wrapper prefix-icon="heroicon-m-magnifying-glass" class="w-full sm:w-72">
        <x-filament::input
            type="search"
            wire:model.live.debounce.300ms="search"
            placeholder="Tìm tài xế / mã NV..."
        />
    </x-filament::input.wrapper>

    {{-- KPI --}}
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <div class="{{ $card }}">
            <span class="text-xs font-medium text-gray-500 dark:text-gray-400">Tài xế có chạy</span>
            <div class="mt-2 flex items-baseline gap-2">
                <span class="text-2xl font-bold tracking-tight text-gray-950 dark:text-white">{{ $totals['active_drivers'] }}</span>
                <span class="text-xs text-gray-500 dark:text-gray-400">/ {{ $totals['drivers'] }} tài xế</span>
            </div>
        </div>
        <div class="{{ $card }}">
            <span class="text-xs font-medium text-gray-500 dark:text-gray-400">Tổng chuyến</span>
            <div class="mt-2 text-2xl font-bold tracking-tight text-gray-950 dark:text-white">{{ number_format($totals['trips']) }}</div>
        </div>
        <div class="{{ $card }}">
            <span class="text-xs font-medium text-gray-500 dark:text-gray-400">Tổng km</span>
            <div class="mt-2 text-2xl font-bold tracking-tight text-gray-950 dark:text-white">{{ number_format($totals['km_total'], 1) }}</div>
        </div>
        <div class="{{ $card }}">
            <span class="text-xs font-medium text-gray-500 dark:text-gray-400">Km có hàng / không hàng</span>
            <div class="mt-2 flex items-baseline gap-2">
                <span class="text-2xl font-bold tracking-tight text-emerald-600 dark:text-emerald-400">{{ number_format($totals['km_loaded'], 1) }}</span>
                <span class="text-sm text-gray-400">/</span>
                <span class="text-lg font-semibold text-amber-600 dark:text-amber-400">{{ number_format($totals['km_empty'], 1) }}</span>
                <span class="text-xs text-gray-500 dark:text-gray-400">({{ $this::loadedPercent($totals['km_loaded'], $totals['km_total']) }}% CH)</span>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-4 xl:grid-cols-5">
        {{-- Bảng tài xế --}}
        <div class="overflow-hidden rounded-xl bg-white shadow-xs ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 xl:col-span-3">
            <div class="max-h-[680px] overflow-auto">
                <table class="w-full text-left text-sm">
                    <thead class="sticky top-0 z-10 bg-gray-50 text-xs font-semibold text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                        <tr>
                            <th class="px-3 py-2.5 text-center">STT</th>
                            <th class="px-3 py-2.5">Tài xế</th>
                            <th class="px-3 py-2.5">Điểm trực</th>
                            <th class="px-3 py-2.5 text-center">Số chuyến</th>
                            <th class="px-3 py-2.5 text-right">Km CH</th>
                            <th class="px-3 py-2.5 text-right">Km KH</th>
                            <th class="px-3 py-2.5 text-right">Tổng km</th>
                            <th class="px-3 py-2.5 min-w-[120px]">% có hàng</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @forelse ($rows as $row)
                            @php $percent = $this::loadedPercent($row['km_loaded'], $row['km_total']); @endphp
                            <tr
                                wire:key="driver-{{ $row['driver_id'] }}"
                                wire:click="selectDriver({{ $row['driver_id'] }})"
                                @class([
                                    'cursor-pointer transition-colors',
                                    'bg-primary-50 dark:bg-primary-500/10' => $this->driverId === $row['driver_id'],
                                    'hover:bg-gray-50 dark:hover:bg-white/5' => $this->driverId !== $row['driver_id'],
                                    'text-gray-400 dark:text-gray-500' => $row['trip_count'] === 0,
                                ])
                            >
                                <td class="px-3 py-2 text-center text-xs">{{ $loop->iteration }}</td>
                                <td class="px-3 py-2">
                                    <div class="font-semibold">{{ $row['driver_name'] }}</div>
                                    @if ($row['employee_code'])
                                        <div class="text-xs text-gray-500">{{ $row['employee_code'] }}</div>
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-xs">
                                    @if ($row['station'])
                                        <x-filament::badge :color="$row['station']->getColor()" size="sm" :tooltip="$row['station']->getLabel()">{{ $row['station']->value }}</x-filament::badge>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-center font-semibold tabular-nums">{{ $row['trip_count'] }}</td>
                                <td class="px-3 py-2 text-right tabular-nums text-emerald-700 dark:text-emerald-400">{{ number_format($row['km_loaded'], 1) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums text-amber-700 dark:text-amber-400">{{ number_format($row['km_empty'], 1) }}</td>
                                <td class="px-3 py-2 text-right font-semibold tabular-nums">{{ number_format($row['km_total'], 1) }}</td>
                                <td class="px-3 py-2">
                                    <div class="flex items-center gap-2">
                                        <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-amber-100 dark:bg-amber-500/20">
                                            <div class="h-full rounded-full bg-emerald-500" style="width: {{ $percent }}%"></div>
                                        </div>
                                        <span class="w-9 text-right text-xs tabular-nums">{{ $percent }}%</span>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="px-3 py-8 text-center text-gray-500">Không có tài xế phù hợp.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Chuyến của tài xế đang chọn --}}
        <div class="self-start overflow-hidden rounded-xl bg-white shadow-xs ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 xl:sticky xl:top-20 xl:col-span-2">
            @if ($selectedDriver)
                @php
                    $driverPercent = $this::loadedPercent($selectedDriver['km_loaded'], $selectedDriver['km_total']);
                    $initials = collect(explode(' ', $selectedDriver['driver_name']))->filter()->take(-2)->map(fn ($word) => mb_substr($word, 0, 1))->implode('');
                    $weekdays = ['CN', 'T2', 'T3', 'T4', 'T5', 'T6', 'T7'];
                    $tripsByDay = $selectedTrips->groupBy(fn ($trip) => $trip['started_at']?->toDateString() ?? '');
                @endphp

                {{-- Đầu panel: tài xế --}}
                <div class="flex items-start gap-3 px-4 pt-4">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-primary-50 text-sm font-bold text-primary-700 ring-1 ring-primary-600/10 dark:bg-primary-500/10 dark:text-primary-300">
                        {{ mb_strtoupper($initials) }}
                    </div>
                    <div class="min-w-0 flex-1">
                        <h3 class="truncate text-base font-semibold text-gray-950 dark:text-white">{{ $selectedDriver['driver_name'] }}</h3>
                        <div class="mt-0.5 flex flex-wrap items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400">
                            @if ($selectedDriver['employee_code'])
                                <span>{{ $selectedDriver['employee_code'] }}</span>
                                <span class="text-gray-300 dark:text-gray-600">•</span>
                            @endif
                            <span>{{ $this->periodLabel() }}</span>
                            @if ($selectedDriver['station'])
                                <x-filament::badge :color="$selectedDriver['station']->getColor()" size="sm">{{ $selectedDriver['station']->value }}</x-filament::badge>
                            @endif
                        </div>
                    </div>
                    <x-filament::icon-button
                        icon="heroicon-m-x-mark"
                        color="gray"
                        label="Đóng"
                        wire:click="selectDriver({{ $selectedDriver['driver_id'] }})"
                    />
                </div>

                {{-- Tóm tắt km --}}
                <div class="px-4 pt-4">
                    <div class="grid grid-cols-3 gap-2">
                        <div class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-white/5">
                            <div class="text-[11px] font-medium text-gray-500 dark:text-gray-400">Chuyến</div>
                            <div class="text-lg font-bold tabular-nums text-gray-950 dark:text-white">{{ $selectedDriver['trip_count'] }}</div>
                        </div>
                        <div class="rounded-lg bg-emerald-50 px-3 py-2 dark:bg-emerald-500/10">
                            <div class="text-[11px] font-medium text-emerald-700 dark:text-emerald-400">Km có hàng</div>
                            <div class="text-lg font-bold tabular-nums text-emerald-700 dark:text-emerald-300">{{ number_format($selectedDriver['km_loaded'], 1) }}</div>
                        </div>
                        <div class="rounded-lg bg-amber-50 px-3 py-2 dark:bg-amber-500/10">
                            <div class="text-[11px] font-medium text-amber-700 dark:text-amber-400">Km không hàng</div>
                            <div class="text-lg font-bold tabular-nums text-amber-700 dark:text-amber-300">{{ number_format($selectedDriver['km_empty'], 1) }}</div>
                        </div>
                    </div>
                    <div class="mt-3 flex items-center gap-2">
                        <div class="h-2 flex-1 overflow-hidden rounded-full bg-amber-100 dark:bg-amber-500/20">
                            <div class="h-full rounded-full bg-emerald-500" style="width: {{ $driverPercent }}%"></div>
                        </div>
                        <span class="text-xs font-semibold tabular-nums text-gray-700 dark:text-gray-300">{{ $driverPercent }}% có hàng</span>
                        <span class="text-xs tabular-nums text-gray-500 dark:text-gray-400">· {{ number_format($selectedDriver['km_total'], 1) }} km</span>
                    </div>
                </div>

                {{-- Danh sách chuyến theo ngày --}}
                <div class="mt-4 max-h-[560px] overflow-auto border-t border-gray-100 dark:border-white/5">
                    @forelse ($tripsByDay as $day => $dayTrips)
                        @php $dayDate = $dayTrips->first()['started_at']; @endphp
                        <div wire:key="day-{{ $day }}">
                            <div class="sticky top-0 z-10 flex items-center justify-between bg-gray-50/95 px-4 py-1.5 text-[11px] font-semibold uppercase tracking-wide text-gray-500 backdrop-blur dark:bg-gray-800/95 dark:text-gray-400">
                                <span>{{ $dayDate ? $weekdays[$dayDate->dayOfWeek].', '.$dayDate->format('d/m') : '—' }}</span>
                                <span class="tabular-nums">{{ number_format($dayTrips->sum('km_total'), 1) }} km</span>
                            </div>
                            <ul class="divide-y divide-gray-100 dark:divide-white/5">
                                @foreach ($dayTrips as $trip)
                                    @php $tripPercent = $this::loadedPercent($trip['km_loaded'], $trip['km_total']); @endphp
                                    <li wire:key="trip-{{ $trip['trip_id'] }}" class="flex items-center gap-3 px-4 py-2.5 transition-colors hover:bg-gray-50 dark:hover:bg-white/5">
                                        <span class="w-11 shrink-0 text-xs font-medium tabular-nums text-gray-500 dark:text-gray-400">{{ $trip['started_at']?->format('H:i') ?? '—' }}</span>
                                        <div class="min-w-0 flex-1">
                                            <div class="flex items-center gap-2">
                                                <span class="truncate text-sm font-semibold text-gray-950 dark:text-white">{{ $trip['trip_code'] }}</span>
                                                @if ($trip['plate'])
                                                    <span class="shrink-0 rounded bg-gray-100 px-1.5 py-0.5 font-mono text-[10px] font-semibold text-gray-600 dark:bg-white/10 dark:text-gray-300">{{ $trip['plate'] }}</span>
                                                @endif
                                            </div>
                                            <div class="mt-1.5 flex h-1.5 overflow-hidden rounded-full bg-amber-200 dark:bg-amber-500/30" title="Có hàng {{ $tripPercent }}%">
                                                <div class="h-full bg-emerald-500" style="width: {{ $tripPercent }}%"></div>
                                            </div>
                                        </div>
                                        <div class="w-24 shrink-0 text-right">
                                            <div class="text-sm font-bold tabular-nums text-gray-950 dark:text-white">{{ number_format($trip['km_total'], 1) }} <span class="text-[10px] font-medium text-gray-400">km</span></div>
                                            <div class="text-[11px] tabular-nums">
                                                <span class="text-emerald-600 dark:text-emerald-400">{{ number_format($trip['km_loaded'], 1) }}</span>
                                                <span class="text-gray-300 dark:text-gray-600">/</span>
                                                <span class="text-amber-600 dark:text-amber-400">{{ number_format($trip['km_empty'], 1) }}</span>
                                            </div>
                                        </div>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @empty
                        <x-filament::empty-state
                            icon="heroicon-o-truck"
                            heading="Chưa có chuyến"
                            description="Tài xế chưa có chuyến nào được chốt trong kỳ này."
                            :contained="false"
                        />
                    @endforelse
                </div>
            @else
                <x-filament::empty-state
                    icon="heroicon-o-cursor-arrow-rays"
                    heading="Chọn một tài xế"
                    description="Bấm vào một dòng trong bảng để xem chi tiết chuyến và km trong kỳ."
                    :contained="false"
                />
            @endif
        </div>
    </div>
</x-filament-panels::page>
