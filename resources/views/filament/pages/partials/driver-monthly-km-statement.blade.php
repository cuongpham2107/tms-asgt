{{-- Bảng kê chuyến chỉ xem: cùng dữ liệu trang Bảng kê nhưng bỏ toàn bộ cước/phí, thêm giờ bắt đầu / kết thúc chuyến --}}
@php
    $paginator = $this->statementPage;
    $rows = collect($paginator->items());
    $time = fn ($value): ?string => $value ? \Carbon\Carbon::parse($value)->format('H:i d/m') : null;
    $duration = fn (?int $minutes): string => $minutes === null ? '—' : app(\App\Services\Export\TripExcelExportService::class)->formatDuration($minutes);
    $num = fn ($value, int $decimals = 1): string => (float) $value > 0 ? number_format((float) $value, $decimals) : '';
    $headers = ['STT', 'Ngày', 'Mã đơn / chuyến', 'Loại', 'BSX', 'Lái xe', 'Bắt đầu chuyến', 'Kết thúc chuyến', 'Hành trình', 'A: Đến đóng', 'B: Xe chạy', 'C: Đến trả', 'D: Hạ xong', 'TG đóng', 'TG chạy', 'TG hạ', 'Tổng TG', 'Khách hàng', 'Kho', 'Xe', 'Km CH', 'Km KH', 'PCS', 'GW', 'Loại hàng', 'Ghi chú'];
@endphp

@if ($this->driverId !== null)
    <div class="flex items-center gap-2 text-sm">
        <span class="text-gray-600 dark:text-gray-400">Đang xem lái xe:</span>
        <x-filament::badge>{{ $rows->first()['driver_name'] ?? 'đã chọn' }}</x-filament::badge>
        <x-filament::link tag="button" wire:click="selectDriver({{ $this->driverId }})" size="sm" color="gray">Xem tất cả</x-filament::link>
    </div>
@endif

<div class="dispatch-table-container overflow-auto rounded-xl bg-white shadow-xs ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
    <table class="dispatch-table w-full border-collapse font-sans text-xs">
        <thead class="sticky top-0 z-20 shadow-sm">
            <tr class="dark:bg-slate-950" style="{{ $navy }}">
                <th colspan="{{ count($headers) }}" class="border-b border-white/10 px-4 py-2.5 text-center text-sm font-bold uppercase tracking-wider text-white sm:text-base dark:bg-slate-950" style="{{ $navy }}">
                    Bảng kê chuyến · {{ $this->periodLabel() }}
                </th>
            </tr>
            <tr class="text-[11px] font-bold uppercase tracking-wide text-white dark:bg-slate-950" style="{{ $navy }}">
                @foreach ($headers as $header)
                    <th class="whitespace-nowrap border-b border-r border-white/10 px-2 py-2 text-center last:border-r-0">{{ $header }}</th>
                @endforeach
            </tr>
        </thead>

        <tbody class="divide-y divide-gray-100 dark:divide-white/5">
            @forelse ($rows as $index => $row)
                <tr
                    wire:key="statement-{{ $row['trip_id'] }}-{{ $row['order_id'] ?? 'trip' }}"
                    @class([
                        'dispatch-row',
                        'bg-white dark:bg-gray-900' => $index % 2 === 0,
                        'bg-gray-50/40 dark:bg-white/[0.015]' => $index % 2 !== 0,
                    ])
                >
                    <td class="border-r border-gray-100 px-2 py-1.5 text-center text-gray-500 dark:border-white/5">{{ $paginator->firstItem() + $index }}</td>
                    <td class="whitespace-nowrap border-r border-gray-100 px-2 py-1.5 dark:border-white/5">{{ filled($row['date']) ? \Carbon\Carbon::parse($row['date'])->format('d/m/Y') : '—' }}</td>
                    <td class="whitespace-nowrap border-r border-gray-100 px-2 py-1.5 font-semibold text-gray-900 dark:border-white/5 dark:text-gray-100">{{ $row['code'] }}</td>
                    <td class="whitespace-nowrap border-r border-gray-100 px-2 py-1.5 dark:border-white/5">{{ $row['service_type'] }}</td>
                    <td class="whitespace-nowrap border-r border-gray-100 px-2 py-1.5 text-center font-semibold dark:border-white/5">{{ $row['plate_number'] ?? '—' }}</td>
                    <td class="whitespace-nowrap border-r border-gray-100 px-2 py-1.5 dark:border-white/5">{{ $row['driver_name'] }}</td>
                    <td class="whitespace-nowrap border-r border-gray-100 px-2 py-1.5 text-center font-mono text-[11px] font-semibold text-emerald-700 dark:border-white/5 dark:text-emerald-400">{{ $time($row['trip_started_at']) ?? '—' }}</td>
                    <td class="whitespace-nowrap border-r border-gray-100 px-2 py-1.5 text-center font-mono text-[11px] font-semibold text-rose-700 dark:border-white/5 dark:text-rose-400">{{ $time($row['trip_ended_at']) ?? '—' }}</td>
                    <td class="whitespace-nowrap border-r border-gray-100 px-2 py-1.5 dark:border-white/5">{{ $row['journey'] }}</td>
                    @foreach (['time_a', 'time_b', 'time_c', 'time_d'] as $key)
                        <td class="whitespace-nowrap border-r border-gray-100 px-2 py-1.5 text-center font-mono text-[11px] dark:border-white/5">{{ $time($row[$key]) ?? '—' }}</td>
                    @endforeach
                    @foreach (['time_loading', 'time_travel', 'time_unloading', 'time_total_trip'] as $key)
                        <td class="whitespace-nowrap border-r border-gray-100 px-2 py-1.5 text-center font-mono text-[11px] dark:border-white/5">{{ $duration($row[$key]) }}</td>
                    @endforeach
                    <td class="whitespace-nowrap border-r border-gray-100 px-2 py-1.5 dark:border-white/5">{{ $row['customer'] }}</td>
                    <td class="whitespace-nowrap border-r border-gray-100 px-2 py-1.5 dark:border-white/5">{{ $row['warehouse'] }}</td>
                    <td class="whitespace-nowrap border-r border-gray-100 px-2 py-1.5 dark:border-white/5">{{ $row['vehicle_owner'] }} · {{ $row['vehicle_type'] }}</td>
                    <td class="whitespace-nowrap border-r border-gray-100 px-2 py-1.5 text-right font-mono text-[11px] text-emerald-700 dark:border-white/5 dark:text-emerald-400">{{ $num($row['km_loaded']) }}</td>
                    <td class="whitespace-nowrap border-r border-gray-100 px-2 py-1.5 text-right font-mono text-[11px] text-amber-700 dark:border-white/5 dark:text-amber-400">{{ $num($row['km_empty']) }}</td>
                    <td class="whitespace-nowrap border-r border-gray-100 px-2 py-1.5 text-right font-mono text-[11px] dark:border-white/5">{{ $num($row['pcs'], 0) }}</td>
                    <td class="whitespace-nowrap border-r border-gray-100 px-2 py-1.5 text-right font-mono text-[11px] dark:border-white/5">{{ $num($row['gw']) }}</td>
                    <td class="whitespace-nowrap border-r border-gray-100 px-2 py-1.5 dark:border-white/5">{{ $row['cargo_type'] }}</td>
                    <td class="min-w-[160px] px-2 py-1.5 text-gray-600 dark:text-gray-400">{{ $row['note'] }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="{{ count($headers) }}" class="px-3 py-8 text-center text-gray-500">Không có chuyến hoàn thành nào trong kỳ.</td>
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
