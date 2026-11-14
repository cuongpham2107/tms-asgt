@include('filament.forms.components.location-geofence-script')

@php
    $config = $getMapData();
    $defaultRadius = (int) config('checkpoint_geofence.radius_m', 3000);
@endphp

<x-dynamic-component
    :component="$getFieldWrapperView()"
    :field="$field"
>
    <div
        wire:ignore
        x-data="locationGeofenceMap(
            $wire,
            {{ \Illuminate\Support\Js::from($config) }},
            {{ $defaultRadius }},
        )"
        class="space-y-2"
    >
        {{-- Thanh công cụ chọn & vẽ vùng kiểm tra GPS trực tiếp trên bản đồ --}}
        <div class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-gray-200 bg-gray-50/90 px-3 py-2 text-xs dark:border-white/10 dark:bg-gray-800/80">
            <div class="flex flex-wrap items-center gap-1.5">
                <span class="font-semibold text-gray-700 dark:text-gray-200 mr-1">Vùng cắm mốc:</span>

                <button
                    type="button"
                    @click="setGeofenceType('circle', true)"
                    :class="geofenceType === 'circle'
                        ? 'bg-primary-600 text-white shadow-xs ring-1 ring-primary-600'
                        : 'bg-white text-gray-700 hover:bg-gray-100 ring-1 ring-gray-300 dark:bg-gray-900 dark:text-gray-300 dark:ring-white/15'"
                    class="inline-flex items-center gap-1 rounded-md px-2.5 py-1 font-semibold transition cursor-pointer"
                >
                    <span>⭕ Bán kính tròn</span>
                </button>

                <button
                    type="button"
                    @click="setGeofenceType('rectangle', true)"
                    :class="geofenceType === 'rectangle'
                        ? 'bg-emerald-600 text-white shadow-xs ring-1 ring-emerald-600'
                        : 'bg-white text-gray-700 hover:bg-gray-100 ring-1 ring-gray-300 dark:bg-gray-900 dark:text-gray-300 dark:ring-white/15'"
                    class="inline-flex items-center gap-1 rounded-md px-2.5 py-1 font-semibold transition cursor-pointer"
                >
                    <span>▭ Hình chữ nhật</span>
                </button>

                <button
                    type="button"
                    @click="setGeofenceType('square', true)"
                    :class="geofenceType === 'square'
                        ? 'bg-emerald-600 text-white shadow-xs ring-1 ring-emerald-600'
                        : 'bg-white text-gray-700 hover:bg-gray-100 ring-1 ring-gray-300 dark:bg-gray-900 dark:text-gray-300 dark:ring-white/15'"
                    class="inline-flex items-center gap-1 rounded-md px-2.5 py-1 font-semibold transition cursor-pointer"
                >
                    <span>□ Hình vuông</span>
                </button>

                <button
                    type="button"
                    @click="toggleDrawMode()"
                    :class="isDrawingBox
                        ? 'bg-amber-500 text-white ring-2 ring-amber-300 animate-pulse'
                        : 'bg-amber-50 text-amber-800 hover:bg-amber-100 ring-1 ring-amber-300 dark:bg-amber-950/60 dark:text-amber-200 dark:ring-amber-700'"
                    class="inline-flex items-center gap-1 rounded-md px-2.5 py-1 font-semibold transition cursor-pointer ml-1"
                    title="Bấm rồi giữ chuột kéo trên bản đồ để vẽ vùng mới"
                >
                    <span x-text="isDrawingBox ? '🎯 Đang chờ kéo chuột trên bản đồ... (Bấm để huỷ)' : (geofenceType === 'circle' ? '✏️ Kéo chuột vẽ bán kính' : '✏️ Kéo chuột vẽ lại khung')"></span>
                </button>
            </div>

            <div class="flex items-center gap-2 text-[11px] font-medium text-gray-600 dark:text-gray-300">
                <span
                    class="inline-flex items-center rounded-md bg-white px-2 py-0.5 font-mono font-semibold text-gray-800 ring-1 ring-gray-200 dark:bg-gray-900 dark:text-gray-200 dark:ring-white/10"
                    x-text="dimensionSummary"
                ></span>
            </div>
        </div>

        <div
            style="height: {{ $config['mapHeight'] }}px; width: 100%; overflow: hidden;"
            class="relative rounded-lg ring-1 ring-gray-200 dark:ring-white/10"
        >
            <div id="{{ $config['mapId'] }}" class="h-full w-full"></div>
        </div>

        <p class="text-[11px] text-gray-600 dark:text-gray-400">
            💡 <strong>Mẹo:</strong> Kéo trực tiếp <strong>4 chốt trắng</strong> trên viền (hình tròn / hình vuông / hình chữ nhật) để tăng giảm kích thước — ô <strong>Bán kính kiểm tra GPS (mét)</strong> sẽ tự động nhảy số theo. Hoặc giữ phím <kbd class="px-1 py-0.5 rounded bg-gray-100 dark:bg-gray-800 border">Shift</kbd> + kéo chuột để vẽ nhanh.
        </p>
    </div>
</x-dynamic-component>
