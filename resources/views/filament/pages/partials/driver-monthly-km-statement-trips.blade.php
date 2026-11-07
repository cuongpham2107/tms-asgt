{{-- Danh sách chuyến: mỗi dòng có thanh dòng thời gian đóng (A→B) / chạy (B→C) / hạ (C→D), độ dài theo tổng thời gian --}}
@php
    $paginator = $this->statementPage;
    $rows = collect($paginator->items());
    $time = fn ($value): ?string => $value ? \Carbon\Carbon::parse($value)->format('H:i d/m') : null;
    $minutes = fn (?int $value): string => $this::formatMinutes($value);
    $maxTotal = max(1, (int) $rows->max('time_total_trip'));
    $longWait = \App\Services\DriverMonthlyKmReportService::LONG_WAIT_MINUTES;
    $phases = [
        'time_loading' => ['Đóng', 'rgb(245 158 11)'],
        'time_travel' => ['Chạy', 'rgb(14 165 233)'],
        'time_unloading' => ['Hạ', 'rgb(139 92 246)'],
    ];
    $typeColor = ['HHHK' => 'info', 'Hàng ngoài' => 'warning', 'Xe không hàng' => 'gray'];
@endphp

<div class="dispatch-table-container overflow-auto rounded-xl bg-white shadow-xs ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
    <table class="dispatch-table w-full border-collapse font-sans text-xs">
        <thead class="sticky top-0 z-20 shadow-sm">
            <tr class="dark:bg-slate-950" style="{{ $navy }}">
                <th colspan="9" class="border-b border-white/10 px-4 py-2 text-white dark:bg-slate-950" style="{{ $navy }}">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <span class="text-sm font-bold uppercase tracking-wider">Chuyến · {{ $this->periodLabel() }} · {{ number_format($paginator->total()) }} dòng</span>
                        <span class="flex items-center gap-3 text-[11px] font-medium normal-case text-white/80">
                            @foreach ($phases as [$label, $color])
                                <span class="inline-flex items-center gap-1"><span class="h-2 w-3 rounded-sm" style="background-color: {{ $color }};"></span>{{ $label }}</span>
                            @endforeach
                        </span>
                    </div>
                </th>
            </tr>
            <tr class="text-[11px] font-bold uppercase tracking-wide text-white dark:bg-slate-950" style="{{ $navy }}">
                @foreach (['#', 'Ngày · Mã', 'Lái xe · Xe', 'Hành trình', 'Bắt đầu → kết thúc', 'Dòng thời gian', 'Chờ', 'Km', 'Hàng'] as $header)
                    <th class="whitespace-nowrap border-b border-r border-white/10 px-2 py-2 text-center last:border-r-0">{{ $header }}</th>
                @endforeach
            </tr>
        </thead>

        <tbody class="divide-y divide-gray-100 dark:divide-white/5">
            @forelse ($rows as $index => $row)
                @php
                    $wait = \App\Services\DriverMonthlyKmReportService::waitMinutes($row);
                    $total = (int) $row['time_total_trip'];
                    $timeline = 'A '.($time($row['time_a']) ?? '—').' · B '.($time($row['time_b']) ?? '—').' · C '.($time($row['time_c']) ?? '—').' · D '.($time($row['time_d']) ?? '—');
                @endphp
                <tr
                    wire:key="statement-{{ $row['trip_id'] }}-{{ $row['order_id'] ?? 'trip' }}"
                    @class([
                        'dispatch-row',
                        'bg-white dark:bg-gray-900' => $index % 2 === 0,
                        'bg-gray-50/40 dark:bg-white/[0.015]' => $index % 2 !== 0,
                    ])
                >
                    <td class="border-r border-gray-100 px-2 py-1.5 text-center font-mono text-[11px] text-gray-400 dark:border-white/5">{{ $paginator->firstItem() + $index }}</td>
                    <td class="whitespace-nowrap border-r border-gray-100 px-2 py-1.5 dark:border-white/5">
                        <div class="flex items-center gap-1.5">
                            <span class="font-semibold text-gray-950 dark:text-white">{{ $row['code'] }}</span>
                            <x-filament::badge :color="$typeColor[$row['service_type']] ?? 'gray'" size="xs">{{ $row['service_type'] }}</x-filament::badge>
                        </div>
                        <div class="text-[10px] text-gray-500 dark:text-gray-400">{{ filled($row['date']) ? \Carbon\Carbon::parse($row['date'])->format('d/m/Y') : '—' }}</div>
                    </td>
                    <td class="whitespace-nowrap border-r border-gray-100 px-2 py-1.5 dark:border-white/5">
                        <div class="font-medium text-gray-900 dark:text-gray-100">{{ $row['driver_name'] }}</div>
                        <div class="font-mono text-[10px] text-gray-500 dark:text-gray-400">{{ $row['plate_number'] ?? '—' }} · {{ $row['vehicle_type'] }}</div>
                    </td>
                    <td class="whitespace-nowrap border-r border-gray-100 px-2 py-1.5 dark:border-white/5">
                        <div class="font-semibold text-gray-900 dark:text-gray-100">{{ $row['journey'] ?: '—' }}</div>
                        <div class="text-[10px] text-gray-500 dark:text-gray-400">{{ $row['customer'] ?: '—' }} → {{ $row['warehouse'] ?: '—' }}</div>
                    </td>
                    <td class="whitespace-nowrap border-r border-gray-100 px-2 py-1.5 font-mono text-[11px] dark:border-white/5">
                        <div class="text-emerald-700 dark:text-emerald-400">▶ {{ $time($row['trip_started_at']) ?? '—' }}</div>
                        <div class="text-rose-700 dark:text-rose-400">■ {{ $time($row['trip_ended_at']) ?? '—' }}</div>
                    </td>
                    <td class="min-w-[220px] border-r border-gray-100 px-2 py-1.5 dark:border-white/5" title="{{ $timeline }}">
                        <div class="flex items-center gap-2">
                            <div class="flex h-2.5 flex-1 overflow-hidden rounded-full bg-gray-100 dark:bg-white/10">
                                @if ($total > 0)
                                    @foreach ($phases as $key => [$label, $color])
                                        @if ((int) $row[$key] > 0)
                                            <div style="width: {{ round((int) $row[$key] / $maxTotal * 100, 2) }}%; background-color: {{ $color }};" title="{{ $label }} {{ $minutes($row[$key]) }}"></div>
                                        @endif
                                    @endforeach
                                @endif
                            </div>
                            <span class="w-14 shrink-0 text-right font-mono text-[11px] font-semibold text-gray-800 dark:text-gray-200">{{ $minutes($row['time_total_trip']) }}</span>
                        </div>
                        <div class="mt-0.5 font-mono text-[10px] text-gray-500 dark:text-gray-400">chạy {{ $minutes($row['time_travel']) }}</div>
                    </td>
                    <td @class([
                        'whitespace-nowrap border-r border-gray-100 px-2 py-1.5 text-center font-mono text-[11px] font-semibold dark:border-white/5',
                        'text-rose-600 dark:text-rose-400' => $wait !== null && $wait > $longWait,
                        'text-amber-700 dark:text-amber-400' => $wait !== null && $wait <= $longWait,
                    ])>{{ $minutes($wait) }}</td>
                    <td class="whitespace-nowrap border-r border-gray-100 px-2 py-1.5 text-right font-mono text-[11px] dark:border-white/5">
                        @if ((float) $row['km_loaded'] > 0)<div class="text-emerald-700 dark:text-emerald-400">{{ number_format((float) $row['km_loaded'], 1) }} CH</div>@endif
                        @if ((float) $row['km_empty'] > 0)<div class="text-amber-700 dark:text-amber-400">{{ number_format((float) $row['km_empty'], 1) }} KH</div>@endif
                    </td>
                    <td class="px-2 py-1.5 text-[11px] text-gray-600 dark:text-gray-400" title="{{ $row['note'] }}">
                        <div class="whitespace-nowrap">
                            {{ collect([
                                (int) $row['pcs'] > 0 ? $row['pcs'].' kiện' : null,
                                (float) $row['gw'] > 0 ? number_format((float) $row['gw'], 1).' kg' : null,
                                $row['cargo_type'] ?: null,
                            ])->filter()->implode(' · ') ?: '—' }}
                        </div>
                        @if (filled($row['note']))
                            <div class="max-w-[200px] truncate text-[10px] text-gray-400">{{ $row['note'] }}</div>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="9" class="px-3 py-8 text-center text-gray-500">Không có chuyến hoàn thành nào phù hợp bộ lọc.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

@if ($paginator->total() > 0)
    <x-filament::pagination
        :paginator="$paginator"
        :page-options="[25, 50, 100]"
        current-page-option-property="statementPerPage"
    />
@endif
