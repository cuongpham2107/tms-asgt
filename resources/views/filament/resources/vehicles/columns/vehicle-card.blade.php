@php
    $record = $getRecord();
    $statusColor = $record->getStatusColor();
    $statusLabel = $record->getStatusLabel();
    $typeLabel = $record->getTypeLabel();
    $vehicleTypeLabel = $record->getVehicleTypeLabel();
    $latestMaintenance = $record->latestMaintenance;
    $driver = $record->driver;
@endphp

<div class="flex h-full flex-col gap-4 p-2">
    {{-- Header Section --}}
    <div class="flex items-start gap-3">
        <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-primary-50 text-primary-600 dark:bg-primary-400/10 dark:text-primary-400">
            <x-filament::icon icon="heroicon-o-truck" class="h-6 w-6" />
        </div>
        <div class="min-w-0 flex-1">
            <div class="flex items-start justify-between gap-2">
                <div class="min-w-0">
                    <h2 class="truncate text-lg font-semibold text-gray-950 dark:text-white" title="{{ $record->plate_number }}">
                        {{ $record->plate_number }}
                    </h2>
                    <p class="truncate text-sm text-gray-500 dark:text-gray-400">
                        {{ $record->owner }}
                    </p>
                </div>
                <div class="flex shrink-0 flex-col items-end gap-1">
                    <x-filament::badge :color="$statusColor" size="sm">
                        {{ $statusLabel }}
                    </x-filament::badge>
                    <x-filament::badge color="gray" size="sm" icon="heroicon-m-building-office-2">
                        {{ $typeLabel }}
                    </x-filament::badge>
                </div>
            </div>
        </div>
    </div>

    {{-- Active State Section --}}
    <div class="flex items-center justify-between gap-3 rounded-lg bg-gray-50 p-3 dark:bg-white/5">
        <span class="text-sm font-medium text-gray-500 dark:text-gray-400">Trạng thái hoạt động</span>
        <x-filament::badge
            :color="$record->is_active ? 'success' : 'gray'"
            :icon="$record->is_active ? 'heroicon-m-check-circle' : 'heroicon-m-minus-circle'"
            size="sm"
        >
            {{ $record->is_active ? 'Hoạt động' : 'Ngừng hoạt động' }}
        </x-filament::badge>
    </div>

    {{-- Info Grid --}}
    <dl class="grid grid-cols-2 gap-x-6 gap-y-4">
        <div class="min-w-0">
            <dt class="mb-1 text-xs font-medium text-gray-500 dark:text-gray-400">Loại xe</dt>
            <dd class="truncate text-sm font-semibold text-gray-950 dark:text-white">{{ $vehicleTypeLabel }}</dd>
        </div>
        <div class="min-w-0">
            <dt class="mb-1 text-xs font-medium text-gray-500 dark:text-gray-400">Tải trọng</dt>
            <dd class="text-sm font-semibold tabular-nums text-gray-950 dark:text-white">{{ number_format($record->load_capacity, 1) }} tấn</dd>
        </div>
        <div class="min-w-0">
            <dt class="mb-1 text-xs font-medium text-gray-500 dark:text-gray-400">Điểm hiện tại</dt>
            <dd class="truncate text-sm font-semibold tabular-nums text-gray-950 dark:text-white">
                @if($record->gps_lat && $record->gps_lng)
                    {{ number_format($record->gps_lat, 4) }}, {{ number_format($record->gps_lng, 4) }}
                @else
                    Đang cập nhật
                @endif
            </dd>
        </div>
        <div class="min-w-0">
            <dt class="mb-1 text-xs font-medium text-gray-500 dark:text-gray-400">Số km</dt>
            <dd class="text-sm font-semibold tabular-nums text-gray-950 dark:text-white">{{ $record->current_mileage ? number_format($record->current_mileage, 0, ',', '.') : '—' }} km</dd>
        </div>
    </dl>

    {{-- Footer Section --}}
    <div class="flex items-center justify-between gap-3 border-t border-gray-200 pt-4 dark:border-white/10">
        <div class="min-w-0">
            <p class="mb-1 text-xs font-medium text-gray-500 dark:text-gray-400">BDSC gần nhất</p>
            <p class="text-sm font-semibold tabular-nums text-gray-950 dark:text-white">
                {{ $latestMaintenance?->completed_at?->format('d/m/Y') ?? 'Chưa có' }}
            </p>
        </div>

        @if($driver)
            <div class="flex min-w-0 items-center gap-2">
                <div class="h-8 w-8 shrink-0 overflow-hidden rounded-full bg-gray-100 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                    @if($driver->avatar_url)
                        <img src="{{ $driver->avatar_url }}" alt="{{ $driver->name }}" class="h-full w-full object-cover">
                    @else
                        <div class="flex h-full w-full items-center justify-center text-gray-400 dark:text-gray-500">
                            <x-filament::icon icon="heroicon-m-user" class="h-5 w-5" />
                        </div>
                    @endif
                </div>
                <span class="truncate text-sm font-medium text-gray-950 dark:text-white" title="{{ $driver->name }}">{{ $driver->name }}</span>
            </div>
        @else
            <span class="text-xs italic text-gray-400 dark:text-gray-500">Chưa gán lái xe</span>
        @endif
    </div>
</div>
