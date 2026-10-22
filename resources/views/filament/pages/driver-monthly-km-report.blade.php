<x-filament-panels::page>
    @php
        $totals = $this->totals;
        $rows = $this->driverRows;
        $selectedTrips = $this->selectedTrips;
        $selectedDriver = $rows->firstWhere('driver_id', $this->driverId);
        $card = 'rounded-xl bg-white p-4 shadow-xs ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10';
    @endphp

    {{-- Bộ lọc tháng + tìm tài xế --}}
    <div class="flex flex-wrap items-center gap-2">
        <div class="inline-flex items-center rounded-lg bg-white shadow-xs ring-1 ring-gray-950/10 dark:bg-gray-900 dark:ring-white/10">
            <button type="button" wire:click="shiftMonth(-1)" class="px-2.5 py-2 text-gray-500 hover:text-gray-900 dark:hover:text-white" title="Tháng trước">
                <x-filament::icon icon="heroicon-m-chevron-left" class="h-4 w-4" />
            </button>
            <input type="month" wire:model.live="month" class="border-0 bg-transparent px-1 py-1.5 text-sm font-semibold text-gray-900 focus:ring-0 dark:text-white" />
            <button type="button" wire:click="shiftMonth(1)" class="px-2.5 py-2 text-gray-500 hover:text-gray-900 dark:hover:text-white" title="Tháng sau">
                <x-filament::icon icon="heroicon-m-chevron-right" class="h-4 w-4" />
            </button>
        </div>

        <input
            type="search"
            wire:model.live.debounce.300ms="search"
            placeholder="Tìm tài xế / mã NV..."
            class="w-64 rounded-lg border-0 bg-white px-3 py-2 text-sm shadow-xs ring-1 ring-gray-950/10 focus:ring-2 focus:ring-primary-500 dark:bg-gray-900 dark:text-white dark:ring-white/10"
        />

        <span class="text-xs text-gray-500 dark:text-gray-400">
            Chỉ tính chuyến đã hoàn thành thuộc ca đã kết thúc.
        </span>
    </div>

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
                            <th class="px-3 py-2.5 text-right">Số chuyến</th>
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
                                <td class="px-3 py-2 text-xs">{{ $row['station'] ?? '—' }}</td>
                                <td class="px-3 py-2 text-right font-semibold tabular-nums">{{ $row['trip_count'] }}</td>
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
        <div class="overflow-hidden rounded-xl bg-white shadow-xs ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 xl:col-span-2">
            @if ($selectedDriver)
                <div class="border-b border-gray-100 px-4 py-3 dark:border-white/5">
                    <h3 class="text-sm font-semibold text-gray-950 dark:text-white">{{ $selectedDriver['driver_name'] }}</h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        {{ $selectedDriver['trip_count'] }} chuyến · {{ number_format($selectedDriver['km_total'], 1) }} km
                        · tháng {{ $this->monthDate()->format('m/Y') }}
                    </p>
                </div>
                <div class="max-h-[620px] overflow-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="sticky top-0 bg-gray-50 text-xs font-semibold text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                            <tr>
                                <th class="px-3 py-2">Ngày</th>
                                <th class="px-3 py-2">Mã chuyến</th>
                                <th class="px-3 py-2">Xe</th>
                                <th class="px-3 py-2 text-right">CH</th>
                                <th class="px-3 py-2 text-right">KH</th>
                                <th class="px-3 py-2 text-right">Tổng</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                            @forelse ($selectedTrips as $trip)
                                <tr wire:key="trip-{{ $trip['trip_id'] }}" class="hover:bg-gray-50 dark:hover:bg-white/5">
                                    <td class="px-3 py-2 text-xs tabular-nums">{{ $trip['started_at']?->format('d/m H:i') ?? '—' }}</td>
                                    <td class="px-3 py-2 font-medium">{{ $trip['trip_code'] }}</td>
                                    <td class="px-3 py-2 text-xs">{{ $trip['plate'] ?? '—' }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums text-emerald-700 dark:text-emerald-400">{{ number_format($trip['km_loaded'], 1) }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums text-amber-700 dark:text-amber-400">{{ number_format($trip['km_empty'], 1) }}</td>
                                    <td class="px-3 py-2 text-right font-semibold tabular-nums">{{ number_format($trip['km_total'], 1) }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="px-3 py-8 text-center text-gray-500">Chưa có chuyến nào được chốt trong tháng.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            @else
                <div class="flex h-full min-h-[200px] items-center justify-center p-6 text-center text-sm text-gray-500 dark:text-gray-400">
                    Chọn một tài xế để xem danh sách chuyến trong tháng.
                </div>
            @endif
        </div>
    </div>
</x-filament-panels::page>
