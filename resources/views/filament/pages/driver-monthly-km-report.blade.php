<x-filament-panels::page>
    @php
        $totals = $this->totals;
        $groups = $this->driverGroups;
        $selectedTrips = $this->selectedTrips;
        $selectedDriver = $this->allDriverRows->firstWhere('driver_id', $this->driverId);
        $navy = 'background-color: rgb(30 58 95 / var(--tw-bg-opacity, 1));';
        $weekdays = ['CN', 'T2', 'T3', 'T4', 'T5', 'T6', 'T7'];
    @endphp

    <div x-data="{ collapsed: {}, toggleGroup(code) { this.collapsed[code] = ! this.collapsed[code] } }" class="w-full space-y-3">
        @include('filament.pages.partials.dispatch-table-styles')

        {{-- Tìm theo tên / mã NV --}}
        <div class="flex items-center gap-2">
            <div class="w-full max-w-xs">
                <x-filament::input.wrapper prefix-icon="heroicon-m-magnifying-glass">
                    <x-filament::input
                        type="search"
                        wire:model.live.debounce.400ms="search"
                        placeholder="Tìm tên hoặc mã NV lái xe..."
                    />
                </x-filament::input.wrapper>
            </div>
            @if (filled($search))
                <x-filament::icon-button
                    icon="heroicon-m-x-mark"
                    wire:click="$set('search', '')"
                    label="Xoá tìm kiếm"
                    color="gray"
                    tooltip="Xoá tìm kiếm"
                />
            @endif
        </div>

        <div class="grid grid-cols-1 items-start gap-3 xl:grid-cols-5">
            {{-- Bảng tổng hợp theo lái xe --}}
            <div class="dispatch-table-container overflow-auto rounded-xl bg-white shadow-xs ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 xl:col-span-3">
                <table class="dispatch-table w-full border-collapse font-sans text-xs">
                    <thead class="sticky top-0 z-20 shadow-sm">
                        <tr class="dark:bg-slate-950" style="{{ $navy }}">
                            <th colspan="9" class="border-b border-white/10 px-4 py-2.5 text-center text-sm font-bold uppercase tracking-wider text-white sm:text-base dark:bg-slate-950" style="{{ $navy }}">
                                Thống kê chuyến & km lái xe · {{ $this->periodLabel() }}
                            </th>
                        </tr>
                        <tr class="text-[11px] font-bold uppercase tracking-wide text-white dark:bg-slate-950" style="{{ $navy }}">
                            <th class="min-w-[110px] whitespace-nowrap border-b border-r border-white/10 px-2 py-2 text-center">Điểm trực</th>
                            <th class="min-w-[150px] whitespace-nowrap border-b border-r border-white/10 px-2 py-2 text-center">Tên lái xe</th>
                            <th class="min-w-[90px] whitespace-nowrap border-b border-r border-white/10 px-2 py-2 text-center">Mã NV</th>
                            <th class="min-w-[55px] whitespace-nowrap border-b border-r border-white/10 px-1 py-2 text-center">Chuyến</th>
                            <th class="min-w-[70px] whitespace-nowrap border-b border-r border-white/10 px-1 py-2 text-center" title="Chuyến không có đơn">Chuyến KH</th>
                            <th class="min-w-[70px] whitespace-nowrap border-b border-r border-white/10 px-2 py-2 text-center">Km CH</th>
                            <th class="min-w-[70px] whitespace-nowrap border-b border-r border-white/10 px-2 py-2 text-center">Km KH</th>
                            <th class="min-w-[75px] whitespace-nowrap border-b border-r border-white/10 px-2 py-2 text-center">Tổng km</th>
                            <th class="min-w-[80px] whitespace-nowrap border-b border-white/10 px-2 py-2 text-center">% có hàng</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @forelse ($groups as $group)
                            @php
                                $gCode = $group['code'];
                                $count = $group['rows']->count();
                            @endphp

                            @foreach ($group['rows'] as $index => $row)
                                @php
                                    $isLastInGroup = $index === $count - 1;
                                    $isSelected = $this->driverId === $row['driver_id'];
                                    $percent = $this::loadedPercent($row['km_loaded'], $row['km_total']);
                                    $isIdle = $row['trip_count'] === 0;
                                @endphp

                                <tr
                                    wire:key="driver-{{ $row['driver_id'] }}"
                                    x-show="! collapsed['{{ $gCode }}'] || {{ $index === 0 ? 'true' : 'false' }}"
                                    @if ($index === 0)
                                        :class="{ 'dispatch-group-end': Boolean(collapsed['{{ $gCode }}']{{ $isLastInGroup ? ' || true' : '' }}) }"
                                    @endif
                                    @class([
                                        'dispatch-row cursor-pointer',
                                        'dispatch-group-end' => $isLastInGroup,
                                        'bg-primary-50 dark:bg-primary-500/15' => $isSelected,
                                        'bg-white dark:bg-gray-900' => ! $isSelected && $index % 2 === 0,
                                        'bg-gray-50/40 dark:bg-white/[0.015]' => ! $isSelected && $index % 2 !== 0,
                                    ])
                                >
                                    {{-- Điểm trực: chỉ hiện ở dòng đầu nhóm --}}
                                    @if ($index === 0)
                                        <td class="select-none whitespace-nowrap border-r border-gray-100 bg-gray-50/80 px-2 py-1.5 text-left align-middle font-bold text-gray-900 dark:border-white/5 dark:bg-white/5 dark:text-gray-100">
                                            <button
                                                type="button"
                                                @click="toggleGroup('{{ $gCode }}')"
                                                class="inline-flex cursor-pointer items-center gap-1.5 font-bold transition hover:opacity-80"
                                                title="Bấm để đóng/mở nhóm {{ $group['title'] }}"
                                            >
                                                <span
                                                    class="inline-flex h-3.5 w-3.5 shrink-0 items-center justify-center rounded font-mono text-[10px] font-bold leading-none text-white"
                                                    style="{{ $navy }}"
                                                    x-text="collapsed['{{ $gCode }}'] ? '+' : '−'"
                                                >−</span>
                                                <span class="text-xs">{{ $group['title'] }}</span>
                                                <span x-show="collapsed['{{ $gCode }}']" class="text-[10px] font-semibold text-primary-600 dark:text-primary-400">({{ $count }} LX)</span>
                                            </button>
                                        </td>
                                    @else
                                        <td class="border-r border-gray-100 px-2 py-1.5 dark:border-white/5"></td>
                                    @endif

                                    <td
                                        wire:click="selectDriver({{ $row['driver_id'] }})"
                                        @class([
                                            'whitespace-nowrap border-r border-gray-100 px-2 py-1.5 font-semibold dark:border-white/5',
                                            'text-primary-600 dark:text-primary-400' => ! $isIdle,
                                            'text-gray-400 dark:text-gray-500' => $isIdle,
                                        ])
                                        title="Bấm để xem chuyến của {{ $row['driver_name'] }}"
                                    >
                                        <span class="hover:underline">{{ $row['driver_name'] }}</span>
                                    </td>
                                    <td wire:click="selectDriver({{ $row['driver_id'] }})" class="whitespace-nowrap border-r border-gray-100 px-2 py-1.5 text-center font-mono text-[11px] text-gray-600 dark:border-white/5 dark:text-gray-400">{{ $row['employee_code'] ?? '—' }}</td>
                                    <td wire:click="selectDriver({{ $row['driver_id'] }})" class="whitespace-nowrap border-r border-gray-100 px-1 py-1.5 text-center font-bold text-gray-800 dark:border-white/5 dark:text-gray-200">{{ $row['trip_count'] }}</td>
                                    <td
                                        wire:click="selectDriver({{ $row['driver_id'] }})"
                                        @class([
                                            'whitespace-nowrap border-r border-gray-100 px-1 py-1.5 text-center dark:border-white/5',
                                            'font-bold text-amber-700 dark:text-amber-400' => $row['empty_trip_count'] > 0,
                                            'text-gray-400 dark:text-gray-500' => $row['empty_trip_count'] === 0,
                                        ])
                                    >{{ $row['empty_trip_count'] }}</td>
                                    <td wire:click="selectDriver({{ $row['driver_id'] }})" class="whitespace-nowrap border-r border-gray-100 px-2 py-1.5 text-right font-mono text-[11px] text-emerald-700 dark:border-white/5 dark:text-emerald-400">{{ number_format($row['km_loaded'], 1) }}</td>
                                    <td wire:click="selectDriver({{ $row['driver_id'] }})" class="whitespace-nowrap border-r border-gray-100 px-2 py-1.5 text-right font-mono text-[11px] text-amber-700 dark:border-white/5 dark:text-amber-400">{{ number_format($row['km_empty'], 1) }}</td>
                                    <td wire:click="selectDriver({{ $row['driver_id'] }})" class="whitespace-nowrap border-r border-gray-100 px-2 py-1.5 text-right font-mono text-[11px] font-bold text-gray-900 dark:border-white/5 dark:text-white">{{ number_format($row['km_total'], 1) }}</td>
                                    {{-- % có hàng: tô nền theo tỉ lệ, cùng kiểu ô KM over --}}
                                    <td
                                        wire:click="selectDriver({{ $row['driver_id'] }})"
                                        class="km-over-cell whitespace-nowrap px-2 py-1.5 text-center font-mono text-[11px] font-semibold text-gray-800 dark:text-gray-200"
                                        title="Có hàng {{ number_format($row['km_loaded'], 1) }} / {{ number_format($row['km_total'], 1) }} km"
                                        @if ($percent > 0)
                                            style="background: linear-gradient(to right, rgb(16 185 129 / 0.25) {{ $percent }}%, transparent {{ $percent }}%);"
                                        @endif
                                    >
                                        {{ $isIdle ? '—' : $percent.'%' }}
                                    </td>
                                </tr>
                            @endforeach
                        @empty
                            <tr>
                                <td colspan="9" class="px-3 py-8 text-center text-gray-500">Không có lái xe phù hợp.</td>
                            </tr>
                        @endforelse
                    </tbody>

                    <tfoot class="sticky bottom-0 z-10">
                        <tr class="bg-gray-100 text-xs font-bold text-gray-900 dark:bg-gray-800 dark:text-white">
                            <td colspan="3" class="border-r border-t-2 border-gray-300 px-2 py-2 uppercase dark:border-white/10">
                                Tổng · {{ $totals['active_drivers'] }}/{{ $totals['drivers'] }} lái xe có chạy
                            </td>
                            <td class="border-r border-t-2 border-gray-300 px-1 py-2 text-center dark:border-white/10">{{ number_format($totals['trips']) }}</td>
                            <td class="border-r border-t-2 border-gray-300 px-1 py-2 text-center text-amber-700 dark:border-white/10 dark:text-amber-400">{{ number_format($totals['empty_trips']) }}</td>
                            <td class="border-r border-t-2 border-gray-300 px-2 py-2 text-right font-mono text-[11px] text-emerald-700 dark:border-white/10 dark:text-emerald-400">{{ number_format($totals['km_loaded'], 1) }}</td>
                            <td class="border-r border-t-2 border-gray-300 px-2 py-2 text-right font-mono text-[11px] text-amber-700 dark:border-white/10 dark:text-amber-400">{{ number_format($totals['km_empty'], 1) }}</td>
                            <td class="border-r border-t-2 border-gray-300 px-2 py-2 text-right font-mono text-[11px] dark:border-white/10">{{ number_format($totals['km_total'], 1) }}</td>
                            <td class="border-t-2 border-gray-300 px-2 py-2 text-center font-mono text-[11px] dark:border-white/10">{{ $this::loadedPercent($totals['km_loaded'], $totals['km_total']) }}%</td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            {{-- Chuyến của lái xe đang chọn --}}
            <div class="dispatch-table-container overflow-auto rounded-xl bg-white shadow-xs ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 xl:sticky xl:top-20 xl:col-span-2">
                @if ($selectedDriver)
                    @php
                        $driverPercent = $this::loadedPercent($selectedDriver['km_loaded'], $selectedDriver['km_total']);
                        $tripsByDay = $selectedTrips->groupBy(fn ($trip) => $trip['started_at']?->toDateString() ?? '');
                    @endphp

                    <table class="dispatch-table w-full border-collapse font-sans text-xs">
                        <thead class="sticky top-0 z-20 shadow-sm">
                            <tr class="dark:bg-slate-950" style="{{ $navy }}">
                                <th colspan="6" class="border-b border-white/10 px-3 py-2.5 text-left text-white dark:bg-slate-950" style="{{ $navy }}">
                                    <div class="flex items-center justify-between gap-2">
                                        <div class="min-w-0">
                                            <div class="truncate text-sm font-bold uppercase tracking-wider">{{ $selectedDriver['driver_name'] }}</div>
                                            <div class="mt-0.5 flex flex-wrap items-center gap-1.5 text-[11px] font-medium normal-case text-white/70">
                                                @if ($selectedDriver['employee_code'])
                                                    <span class="font-mono">{{ $selectedDriver['employee_code'] }}</span>
                                                    <span>·</span>
                                                @endif
                                                @if ($selectedDriver['station'])
                                                    <span>{{ $selectedDriver['station']->getLabel() }}</span>
                                                    <span>·</span>
                                                @endif
                                                <span>{{ $this->periodLabel() }}</span>
                                            </div>
                                        </div>
                                        <button
                                            type="button"
                                            wire:click="selectDriver({{ $selectedDriver['driver_id'] }})"
                                            class="shrink-0 rounded p-1 text-white/70 transition hover:bg-white/10 hover:text-white"
                                            title="Đóng"
                                        >
                                            <x-filament::icon icon="heroicon-m-x-mark" class="h-4 w-4" />
                                        </button>
                                    </div>
                                </th>
                            </tr>
                            <tr class="text-[11px] font-bold uppercase tracking-wide text-white dark:bg-slate-950" style="{{ $navy }}">
                                <th class="whitespace-nowrap border-b border-r border-white/10 px-2 py-2 text-center">Ngày</th>
                                <th class="whitespace-nowrap border-b border-r border-white/10 px-2 py-2 text-center">Mã chuyến</th>
                                <th class="whitespace-nowrap border-b border-r border-white/10 px-2 py-2 text-center">BSX</th>
                                <th class="whitespace-nowrap border-b border-r border-white/10 px-2 py-2 text-center">Km CH</th>
                                <th class="whitespace-nowrap border-b border-r border-white/10 px-2 py-2 text-center">Km KH</th>
                                <th class="whitespace-nowrap border-b border-white/10 px-2 py-2 text-center">Tổng</th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                            @forelse ($tripsByDay as $day => $dayTrips)
                                @php
                                    $dayDate = $dayTrips->first()['started_at'];
                                    $dayCount = $dayTrips->count();
                                @endphp

                                @foreach ($dayTrips->values() as $index => $trip)
                                    @php $tripPercent = $this::loadedPercent($trip['km_loaded'], $trip['km_total']); @endphp
                                    <tr
                                        wire:key="trip-{{ $trip['trip_id'] }}"
                                        @class([
                                            'dispatch-row',
                                            'dispatch-group-end' => $index === $dayCount - 1,
                                            'bg-white dark:bg-gray-900' => $index % 2 === 0,
                                            'bg-gray-50/40 dark:bg-white/[0.015]' => $index % 2 !== 0,
                                        ])
                                    >
                                        @if ($index === 0)
                                            <td class="whitespace-nowrap border-r border-gray-100 bg-gray-50/80 px-2 py-1.5 font-bold text-gray-900 dark:border-white/5 dark:bg-white/5 dark:text-gray-100">
                                                {{ $dayDate ? $weekdays[$dayDate->dayOfWeek].', '.$dayDate->format('d/m') : '—' }}
                                            </td>
                                        @else
                                            <td class="border-r border-gray-100 px-2 py-1.5 dark:border-white/5"></td>
                                        @endif
                                        <td class="whitespace-nowrap border-r border-gray-100 px-2 py-1.5 dark:border-white/5">
                                            <span class="mr-1 font-mono text-[10px] text-gray-500 dark:text-gray-400">{{ $trip['started_at']?->format('H:i') }}</span>
                                            <span class="font-semibold text-gray-900 dark:text-gray-100">{{ $trip['trip_code'] }}</span>
                                            @if ($trip['is_empty'])
                                                <x-filament::badge color="warning" size="xs" class="ml-1 inline-flex">Không hàng</x-filament::badge>
                                            @endif
                                        </td>
                                        <td class="whitespace-nowrap border-r border-gray-100 px-2 py-1.5 text-center font-semibold text-gray-800 dark:border-white/5 dark:text-gray-200">{{ $trip['plate'] ?? '—' }}</td>
                                        <td class="whitespace-nowrap border-r border-gray-100 px-2 py-1.5 text-right font-mono text-[11px] text-emerald-700 dark:border-white/5 dark:text-emerald-400">{{ number_format($trip['km_loaded'], 1) }}</td>
                                        <td class="whitespace-nowrap border-r border-gray-100 px-2 py-1.5 text-right font-mono text-[11px] text-amber-700 dark:border-white/5 dark:text-amber-400">{{ number_format($trip['km_empty'], 1) }}</td>
                                        <td
                                            class="km-over-cell whitespace-nowrap px-2 py-1.5 text-right font-mono text-[11px] font-bold text-gray-900 dark:text-white"
                                            title="Có hàng {{ $tripPercent }}%"
                                            @if ($tripPercent > 0)
                                                style="background: linear-gradient(to right, rgb(16 185 129 / 0.25) {{ $tripPercent }}%, transparent {{ $tripPercent }}%);"
                                            @endif
                                        >
                                            {{ number_format($trip['km_total'], 1) }}
                                        </td>
                                    </tr>
                                @endforeach
                            @empty
                                <tr>
                                    <td colspan="6">
                                        <x-filament::empty-state
                                            icon="heroicon-o-truck"
                                            heading="Chưa có chuyến"
                                            description="Lái xe chưa có chuyến nào được chốt trong kỳ này."
                                            :contained="false"
                                        />
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>

                        <tfoot class="sticky bottom-0 z-10">
                            <tr class="bg-gray-100 text-xs font-bold text-gray-900 dark:bg-gray-800 dark:text-white">
                                <td colspan="3" class="border-r border-t-2 border-gray-300 px-2 py-2 uppercase dark:border-white/10">
                                    {{ $selectedDriver['trip_count'] }} chuyến ({{ $selectedDriver['empty_trip_count'] }} không hàng) · {{ $driverPercent }}% có hàng
                                </td>
                                <td class="border-r border-t-2 border-gray-300 px-2 py-2 text-right font-mono text-[11px] text-emerald-700 dark:border-white/10 dark:text-emerald-400">{{ number_format($selectedDriver['km_loaded'], 1) }}</td>
                                <td class="border-r border-t-2 border-gray-300 px-2 py-2 text-right font-mono text-[11px] text-amber-700 dark:border-white/10 dark:text-amber-400">{{ number_format($selectedDriver['km_empty'], 1) }}</td>
                                <td class="border-t-2 border-gray-300 px-2 py-2 text-right font-mono text-[11px] dark:border-white/10">{{ number_format($selectedDriver['km_total'], 1) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                @else
                    <div class="border-b border-white/10 px-3 py-2.5 text-center text-sm font-bold uppercase tracking-wider text-white dark:bg-slate-950" style="{{ $navy }}">
                        Chi tiết chuyến
                    </div>
                    <x-filament::empty-state
                        icon="heroicon-o-cursor-arrow-rays"
                        heading="Chọn một lái xe"
                        description="Bấm vào một dòng trong bảng để xem các chuyến và km trong kỳ."
                        :contained="false"
                    />
                @endif
            </div>
        </div>
    </div>
</x-filament-panels::page>
