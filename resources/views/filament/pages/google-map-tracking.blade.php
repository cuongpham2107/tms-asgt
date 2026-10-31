<x-filament-panels::page>
    {{-- Toàn bộ bản đồ, danh sách xe, chú thích do widget React vẽ --}}
    <div wire:loading class="fixed inset-x-0 top-0 z-50 h-0.5 animate-pulse bg-primary-500"></div>

    @livewire(\App\Filament\Widgets\VehicleTrackingMap::class)
</x-filament-panels::page>
