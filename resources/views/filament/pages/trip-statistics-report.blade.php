<x-filament-panels::page>
    @include('filament.pages.partials.dispatch-table-styles')

    <style>
        .metric-input { width: 5rem; padding: 0.125rem 0.375rem; text-align: right; font-size: 0.75rem; border-radius: 0.375rem; outline: 2px solid rgb(59 130 246); background: white; color: rgb(17 24 39); }
        .dark .metric-input { background: rgb(31 41 55); color: rgb(243 244 246); }
    </style>

    {{-- Toàn bộ thân trang do widget React vẽ; page chỉ giữ header actions và state bộ lọc --}}
    <div wire:loading class="fixed inset-x-0 top-0 z-50 h-0.5 animate-pulse bg-primary-500"></div>

    <div class="fi-js-widget-bare">
        @livewire(\App\Filament\Widgets\TripStatisticsTable::class, [
            'startDate' => $this->startDate,
            'endDate' => $this->endDate,
            'activeTab' => $this->activeTab,
            'customerId' => $this->customerId,
            'vehicleOwner' => $this->vehicleOwner,
            'search' => $this->search,
            'chargeStatus' => $this->chargeStatus,
            'page' => $this->page,
            'perPage' => $this->perPage,
            'dataVersion' => $this->dataVersion,
        ])
    </div>
</x-filament-panels::page>
