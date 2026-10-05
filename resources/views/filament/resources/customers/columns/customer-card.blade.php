@php
    $record = $getRecord();
@endphp

<div class="flex h-full flex-col gap-4 p-2">
    {{-- Top Section --}}
    <div class="flex items-start gap-3">
        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-primary-50 text-primary-600 dark:bg-primary-400/10 dark:text-primary-400">
            <x-filament::icon icon="heroicon-o-building-office" class="h-5 w-5" />
        </div>
        <div class="min-w-0 flex-1">
            <h2 class="truncate text-base font-semibold text-gray-950 dark:text-white" title="{{ $record->name }}">
                {{ $record->name }}
            </h2>
            <x-filament::badge color="gray" size="sm" icon="heroicon-m-clipboard-document-list" class="mt-1 w-fit">
                <span class="tabular-nums">{{ $record->orders_count ?? 0 }}</span> đơn hàng
            </x-filament::badge>
        </div>
    </div>

    {{-- Info Section --}}
    <dl class="flex-1 space-y-2 text-sm text-gray-500 dark:text-gray-400">
        <div class="flex items-center gap-2">
            <dt class="shrink-0">
                <x-filament::icon icon="heroicon-m-phone" class="h-4 w-4 text-gray-400 dark:text-gray-500" />
                <span class="sr-only">Điện thoại</span>
            </dt>
            <dd class="truncate font-medium text-gray-950 dark:text-white">{{ $record->phone ?? '-' }}</dd>
        </div>
        <div class="flex items-center gap-2">
            <dt class="shrink-0">
                <x-filament::icon icon="heroicon-m-envelope" class="h-4 w-4 text-gray-400 dark:text-gray-500" />
                <span class="sr-only">Email</span>
            </dt>
            <dd class="truncate font-medium text-gray-950 dark:text-white" title="{{ $record->email }}">{{ $record->email ?? '-' }}</dd>
        </div>
        <div class="flex items-start gap-2">
            <dt class="mt-0.5 shrink-0">
                <x-filament::icon icon="heroicon-m-map-pin" class="h-4 w-4 text-gray-400 dark:text-gray-500" />
                <span class="sr-only">Địa chỉ</span>
            </dt>
            <dd class="line-clamp-2 font-medium text-gray-950 dark:text-white" title="{{ $record->address }}">{{ $record->address ?? '-' }}</dd>
        </div>
    </dl>
</div>
