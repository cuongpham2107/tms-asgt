@php
    $record = $getRecord();
    $statusColor = $record->getStatusColor();
    $statusLabel = $record->getStatusLabel();
    $typeLabel = $record->getTypeLabel();
    $vehicleTypeLabel = $record->getVehicleTypeLabel();
    $latestMaintenance = $record->latestMaintenance;
    $driver = $record->driver;
@endphp

<div class="flex flex-col h-full p-2">
    <!-- Header Section -->
    <div class="flex items-start gap-4 mb-5">
        <div
            class="w-12 h-12 flex items-center justify-center rounded-xl bg-blue-50 dark:bg-blue-950 text-blue-500 shrink-0 border border-blue-100/50 dark:border-blue-900/50">
            <x-heroicon-o-truck class="w-6 h-6" />
        </div>
        <div class="flex-1 min-w-0">
            <div class="flex items-center justify-between gap-2">
                <h2 class="text-xl! font-bold text-gray-900 dark:text-white truncate">
                    {{ $record->plate_number }}
                </h2>
                <div class="flex flex-col items-end gap-1">
                    <span @class([
                        'px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider',
                        'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400' => $record->status === 'running',
                        'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400' => $record->status === 'on',
                        'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400' => $record->status === 'bdsc',
                        'bg-gray-100 text-gray-700 dark:bg-gray-900/30 dark:text-gray-400' => $record->status === 'off',
                    ])>
                        <span class="inline-block w-1 h-1 rounded-full bg-current mr-1 mb-0.5"></span>
                        {{ $statusLabel }}
                    </span>
                    <span class="px-2 py-0.5 rounded-md text-[10px] font-bold bg-emerald-50 text-emerald-600 dark:bg-emerald-950/30 dark:text-emerald-400 border border-emerald-100 dark:border-emerald-900/50 flex items-center gap-1">
                        <x-heroicon-m-building-office-2 class="w-3 h-3" />
                        {{ $typeLabel }}
                    </span>
                </div>
            </div>
            <p class="text-sm text-gray-400 dark:text-gray-500 font-medium">
                {{ $record->owner }}
            </p>
        </div>
    </div>

    <!-- Active Toggle Section -->
    <div class="bg-gray-50/50 dark:bg-gray-900/50 rounded-xl p-3 mb-6 flex items-center justify-between border border-gray-100 dark:border-gray-800">
        <span class="text-sm font-medium text-gray-600 dark:text-gray-400">Trạng thái hoạt động</span>
        <div @class([
            'w-10 h-5 rounded-full relative transition-colors duration-200',
            'bg-emerald-500' => $record->is_active,
            'bg-gray-300 dark:bg-gray-700' => !$record->is_active,
        ])>
            <div @class([
                'absolute top-1 w-3 h-3 bg-white rounded-full transition-transform duration-200',
                'translate-left-1 left-1' => !$record->is_active,
                'right-1' => $record->is_active,
            ])></div>
        </div>
    </div>

    <!-- Info Grid -->
    <div class="grid grid-cols-2 gap-y-4 gap-x-6 mb-6">
        <div>
            <p class="text-[11px] text-gray-400 dark:text-gray-500 uppercase tracking-wider font-semibold mb-1">Loại xe</p>
            <p class="text-sm font-bold text-gray-900 dark:text-white">{{ $vehicleTypeLabel }}</p>
        </div>
        <div>
            <p class="text-[11px] text-gray-400 dark:text-gray-500 uppercase tracking-wider font-semibold mb-1">Tải trọng</p>
            <p class="text-sm font-bold text-gray-900 dark:text-white">{{ number_format($record->load_capacity, 1) }} tấn</p>
        </div>
        <div>
            <p class="text-[11px] text-gray-400 dark:text-gray-500 uppercase tracking-wider font-semibold mb-1">Điểm hiện tại</p>
            <p class="text-sm font-bold text-gray-900 dark:text-white">
                @if($record->gps_lat && $record->gps_lng)
                    {{ number_format($record->gps_lat, 4) }}, {{ number_format($record->gps_lng, 4) }}
                @else
                    Đang cập nhật
                @endif
            </p>
        </div>
        <div>
            <p class="text-[11px] text-gray-400 dark:text-gray-500 uppercase tracking-wider font-semibold mb-1">Số km</p>
            <p class="text-sm font-bold text-gray-900 dark:text-white">{{ $record->current_mileage ? number_format($record->current_mileage, 0, ',', '.') : '—' }} km</p>
        </div>
    </div>

    <div class="h-px bg-gray-100 dark:bg-gray-800 w-full mb-5"></div>

    <!-- Footer Section -->
    <div class="flex items-center justify-between">
        <div>
            <p class="text-[10px] text-gray-400 dark:text-gray-500 font-semibold mb-1">BDSC gần nhất</p>
            <p class="text-sm font-bold text-gray-700 dark:text-gray-300">
                {{ $latestMaintenance?->completed_at?->format('d/m/Y') ?? 'Chưa có' }}
            </p>
        </div>

        @if($driver)
            <div class="flex items-center gap-2">
                <div class="w-8 h-8 rounded-full bg-gray-200 dark:bg-gray-700 overflow-hidden border border-white dark:border-gray-800 shadow-sm">
                    @if($driver->avatar_url)
                        <img src="{{ $driver->avatar_url }}" alt="{{ $driver->name }}" class="w-full h-full object-cover">
                    @else
                        <div class="w-full h-full flex items-center justify-center text-gray-400">
                            <x-heroicon-m-user class="w-5 h-5" />
                        </div>
                    @endif
                </div>
                <div class="flex flex-col">
                    <span class="text-sm font-bold text-gray-700 dark:text-gray-300 leading-tight">{{ $driver->name }}</span>
                </div>
            </div>
        @else
            <div class="text-[10px] text-gray-400 italic">Chưa gán lái xe</div>
        @endif
    </div>
</div>
