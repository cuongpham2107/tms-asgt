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
        {{-- Thanh công cụ chọn & chỉnh kích thước vùng kiểm tra GPS trực tiếp trên bản đồ --}}
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

            {{-- Ô nhập/hiển thị kích thước động theo đúng hình đang chọn --}}
            <div class="flex flex-wrap items-center gap-2 text-xs font-medium text-gray-700 dark:text-gray-200">
                <template x-if="geofenceType === 'circle'">
                    <div class="inline-flex items-center gap-1.5 rounded-md bg-white px-2 py-1 ring-1 ring-gray-200 dark:bg-gray-900 dark:ring-white/10">
                        <span class="text-gray-500 dark:text-gray-400">Bán kính:</span>
                        <input
                            type="number"
                            min="100"
                            max="5000"
                            step="10"
                            :value="currentRadius"
                            @input="const r = syncRadiusToInput($event.target.value, true); updateGeofenceCircle(r)"
                            class="w-20 rounded border border-gray-300 bg-gray-50 px-1.5 py-0.5 text-right font-mono text-xs font-bold text-primary-700 focus:border-primary-500 focus:outline-none dark:border-gray-700 dark:bg-gray-800 dark:text-primary-400"
                        />
                        <span class="font-semibold text-gray-500">m</span>
                    </div>
                </template>

                <template x-if="geofenceType === 'square'">
                    <div class="inline-flex items-center gap-1.5 rounded-md bg-white px-2 py-1 ring-1 ring-gray-200 dark:bg-gray-900 dark:ring-white/10">
                        <span class="text-gray-500 dark:text-gray-400">Cạnh hình vuông:</span>
                        <input
                            type="number"
                            min="100"
                            max="20000"
                            step="10"
                            :value="squareSideM"
                            @input="applySquareSideMeters($event.target.value, true)"
                            class="w-20 rounded border border-gray-300 bg-gray-50 px-1.5 py-0.5 text-right font-mono text-xs font-bold text-emerald-700 focus:border-emerald-500 focus:outline-none dark:border-gray-700 dark:bg-gray-800 dark:text-emerald-400"
                        />
                        <span class="font-semibold text-gray-500">m</span>
                    </div>
                </template>

                <template x-if="geofenceType === 'rectangle'">
                    <div class="inline-flex items-center gap-1.5 rounded-md bg-white px-2 py-1 ring-1 ring-gray-200 dark:bg-gray-900 dark:ring-white/10">
                        <span class="text-gray-500 dark:text-gray-400">Rộng:</span>
                        <input
                            type="number"
                            min="100"
                            max="20000"
                            step="10"
                            :value="rectWidthM"
                            @input="applyRectangleDimensionsMeters($event.target.value, rectHeightM, true)"
                            class="w-20 rounded border border-gray-300 bg-gray-50 px-1.5 py-0.5 text-right font-mono text-xs font-bold text-emerald-700 focus:border-emerald-500 focus:outline-none dark:border-gray-700 dark:bg-gray-800 dark:text-emerald-400"
                        />
                        <span class="text-gray-400">m × Dài:</span>
                        <input
                            type="number"
                            min="100"
                            max="20000"
                            step="10"
                            :value="rectHeightM"
                            @input="applyRectangleDimensionsMeters(rectWidthM, $event.target.value, true)"
                            class="w-20 rounded border border-gray-300 bg-gray-50 px-1.5 py-0.5 text-right font-mono text-xs font-bold text-emerald-700 focus:border-emerald-500 focus:outline-none dark:border-gray-700 dark:bg-gray-800 dark:text-emerald-400"
                        />
                        <span class="font-semibold text-gray-500">m</span>
                    </div>
                </template>
            </div>
        </div>

        <div
            style="height: {{ $config['mapHeight'] }}px; width: 100%; overflow: hidden;"
            class="relative rounded-lg ring-1 ring-gray-200 dark:ring-white/10"
        >
            <div id="{{ $config['mapId'] }}" class="h-full w-full"></div>
        </div>

        <p class="text-[11px] text-gray-600 dark:text-gray-400">
            💡 <strong>Mẹo:</strong> Kéo trực tiếp <strong>4 chốt trắng</strong> trên viền để tăng giảm kích thước — các ô kích thước (<strong>Bán kính</strong> / <strong>Cạnh</strong> / <strong>Rộng × Dài</strong>) sẽ tự động nhảy số theo và ngược lại.
        </p>
    </div>
</x-dynamic-component>
