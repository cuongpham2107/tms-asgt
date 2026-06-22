<x-filament-panels::page>
    @vite('resources/css/app.css')
    @php
        $lastUpdated = $this->getLastUpdated();
    @endphp
    @php
        [$playMin, $playMax] = $this->getPlaybackBounds();
        $playMinFmt = $playMin ? (now()->setTimestamp($playMin)->format('Y-m-d H:i')) : null;
        $playMaxFmt = $playMax ? (now()->setTimestamp($playMax)->format('Y-m-d H:i')) : null;
    @endphp

    <div class="space-y-6">
        {{-- @formatter:off --}}
        {{-- Header widgets (stats overview) are rendered automatically by filament --}}
        {{-- @formatter:on --}}

        {{-- Map + Sidebar --}}
        <div class="flex gap-6 items-start">
            {{-- Sidebar as widget --}}
            <div class="w-72 shrink-0 lg:w-80">
                @livewire(\App\Filament\Widgets\GoogleMapSidebar::class, key('google-map-sidebar'))
            </div>

            {{-- Map --}}
            <div class="relative min-w-0 flex-1 overflow-hidden rounded-xl border border-gray-200 shadow-sm dark:border-gray-700" style="height: 720px">
                <div wire:loading.delay.class="opacity-30" wire:target="refreshData,setPlaybackTimestamp" class="h-full transition-opacity duration-300">
                    <x-filament-leaflet::map
                        :config="$this->getMapData()"
                        widget
                    />
                </div>

                <div wire:loading wire:target="refreshData,setPlaybackTimestamp" class="absolute inset-0 z-50 flex items-center justify-center rounded-xl" style="background: rgba(255,255,255,0.15); backdrop-filter: blur(4px);">
                    <div class="flex flex-col items-center gap-3 rounded-xl bg-gray-200 px-8 py-6 shadow-lg ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                        <x-filament::loading-indicator class="h-10 w-10 text-primary-500" />
                        <span class="text-sm font-semibold text-gray-600 dark:text-gray-300">Đang tải route...</span>
                    </div>
                </div>
            </div>
        </div>

        {{-- Legend & Info --}}
        <div class="flex flex-wrap items-center justify-between gap-4 rounded-xl border border-gray-200 bg-white px-6 py-4 dark:border-gray-700 dark:bg-gray-900">
            <div class="flex flex-wrap items-center gap-4 text-xs">
                <span class="font-semibold text-gray-500 dark:text-gray-400">Chú thích route:</span>
                <span class="flex items-center gap-1.5">
                    <span class="inline-block h-3.5 w-3.5 rounded-full bg-green-500"></span>
                    <span class="font-medium text-gray-700 dark:text-gray-300">Bắt đầu</span>
                </span>
                <span class="flex items-center gap-1.5">
                    <span class="inline-block h-3.5 w-3.5 rounded-full bg-blue-500"></span>
                    <span class="font-medium text-gray-700 dark:text-gray-300">Đang đi</span>
                </span>
                <span class="flex items-center gap-1.5">
                    <span class="inline-block h-3.5 w-3.5 rounded-full bg-violet-500"></span>
                    <span class="font-medium text-gray-700 dark:text-gray-300">Đến nơi</span>
                </span>
                <span class="flex items-center gap-1.5">
                    <span class="inline-block h-0 w-6 border-b-2 border-dashed border-gray-400"></span>
                    <span class="font-medium text-gray-700 dark:text-gray-300">GPS breadcrumbs</span>
                </span>
            </div>
            <div class="flex items-center gap-3 text-xs text-gray-400">
                @if($playMin && $playMax)
                    <div x-data="{
                        playbackTimestamp: {{ json_encode($this->playbackTimestamp ?? $playMax) }},
                        playbackPlaying: @entangle('playbackPlaying'),
                        playbackSpeed: @entangle('playbackSpeed'),
                        playMin: {{ $playMin }},
                        playMax: {{ $playMax }},
                        step: 60,
                        timer: null,
                        sendCounter: 0,
                        sendStep: 2,
                        start() {
                            this.stop();
                            this.timer = setInterval(() => {
                                if (this.playbackTimestamp === null) this.playbackTimestamp = this.playMax;
                                this.playbackTimestamp = Math.min(this.playMax, this.playbackTimestamp + this.step);
                                this.sendCounter++;
                                if (this.sendCounter % this.sendStep === 0) {
                                    this.$wire.call('setPlaybackTimestampLight', this.playbackTimestamp);
                                }
                                if (this.playbackTimestamp >= this.playMax) {
                                    this.playbackPlaying = false;
                                    this.stop();
                                }
                            }, this.playbackSpeed || 1000);
                        },
                        stop() { if (this.timer) { clearInterval(this.timer); this.timer = null; this.sendCounter = 0; } },
                    }"
                    x-init="$watch('playbackPlaying', val => { if (val) start(); else stop(); })"
                    class="flex items-center gap-2"
                    >
                        <button
                            @click="playbackPlaying = !playbackPlaying"
                            type="button"
                            class="flex items-center gap-2 rounded-lg px-2 py-1 text-gray-400 transition-colors hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-gray-800"
                        >
                            <template x-if="!playbackPlaying">
                                <x-filament::icon icon="heroicon-o-play" class="h-3.5 w-3.5" />
                            </template>
                            <template x-if="playbackPlaying">
                                <x-filament::icon icon="heroicon-o-pause" class="h-3.5 w-3.5" />
                            </template>
                            <span class="text-xs" x-text="playbackPlaying ? 'Tạm dừng' : 'Phát'"></span>
                        </button>

                        <div class="flex items-center gap-2 px-2">
                            <input type="range" :min="playMin" :max="playMax" step="60" x-model.number="playbackTimestamp" @change="$wire.call('setPlaybackTimestamp', playbackTimestamp)" />
                            <div class="text-xs text-gray-500" x-text="playbackTimestamp ? (new Date(playbackTimestamp * 1000).toISOString().slice(0,16).replace('T',' ')) : ''"></div>
                        </div>

                        <div class="flex items-center gap-2">
                            <select x-model.number="playbackSpeed" class="rounded-md border-gray-200 px-2 py-1 text-sm">
                                <option :value="2000">0.5x</option>
                                <option :value="1000">1x</option>
                                <option :value="500">2x</option>
                            </select>
                        </div>
                    </div>
                @endif
                <button
                    wire:click="refreshData"
                    type="button"
                    class="flex items-center gap-1.5 rounded-lg px-2 py-1 text-gray-400 transition-colors hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-gray-800"
                    wire:loading.attr="disabled"
                >
                    <x-filament::icon icon="heroicon-o-arrow-path" class="h-3.5 w-3.5" wire:loading.class="animate-spin" wire:target="refreshData" />
                    <span wire:loading.remove wire:target="refreshData">Làm mới</span>
                    <span wire:loading wire:target="refreshData" class="text-primary-500 font-medium">Đang tải...</span>
                </button>
                @if ($lastUpdated)
                    <span class="text-gray-300 dark:text-gray-600">|</span>
                    <span class="flex items-center gap-1">
                        <span class="inline-block h-2 w-2 rounded-full bg-emerald-400"></span>
                        {{ $lastUpdated->format('H:i:s') }}
                    </span>
                @endif
            </div>
        </div>
    </div>

    @push('scripts')
        <script>
            document.addEventListener('livewire:navigated', function initMapZoomStyling() {
                let attempts = 0;
                const maxAttempts = 30;

                function tryInit() {
                    const mapContainer = document.querySelector('[id^="map-"]');
                    if (!mapContainer) {
                        if (++attempts < maxAttempts) setTimeout(tryInit, 200);
                        return;
                    }

                    const component = Alpine.$data(mapContainer);
                    if (!component?.mapCore?.map) {
                        if (++attempts < maxAttempts) setTimeout(tryInit, 200);
                        return;
                    }

                    const mapCore = component.mapCore;
                    const map = mapCore.map;
                    const REF_ZOOM = 14;

                    function storeBaseValues() {
                        mapCore.layers.forEach(({ layer, data }) => {
                            if (layer instanceof L.Polyline && layer._baseWeight === undefined) {
                                layer._baseWeight = layer.options.weight || data?.options?.weight || 3;
                            } else if (layer instanceof L.CircleMarker && layer._baseRadius === undefined) {
                                layer._baseRadius = layer.options.radius || data?.options?.radius || 6;
                            }
                        });
                    }

                    function applyZoomStyles() {
                        const zoom = map.getZoom();
                        const scale = Math.max(0.3, Math.min(2.5, Math.pow(1.5, zoom - REF_ZOOM)));

                        mapCore.layers.forEach(({ layer }) => {
                            if (layer instanceof L.Polyline && layer._baseWeight) {
                                layer.setStyle({ weight: layer._baseWeight * scale });
                            } else if (layer instanceof L.CircleMarker && layer._baseRadius) {
                                layer.setRadius(layer._baseRadius * scale);
                            }
                        });
                    }

                    storeBaseValues();

                    const origUpdate = mapCore.updateMapData.bind(mapCore);
                    mapCore.updateMapData = function(newConfig) {
                        origUpdate(newConfig);
                        storeBaseValues();
                        applyZoomStyles();
                    };

                    map.on('zoomend', applyZoomStyles);
                    setTimeout(applyZoomStyles, 150);
                }

                tryInit();
            });
        </script>
    @endpush
</x-filament-panels::page>
