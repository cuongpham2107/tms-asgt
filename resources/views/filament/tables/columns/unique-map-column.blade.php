@php
    $config = $getMapData();
    $state = $getState();
    $placeholder = $getPlaceholder();
@endphp

<div 
    style="
        :root {
            --max-dimension: max({{ $config['mapHeight'] }}px, {{ $getWidth() }});
        };
        padding: 4px;
    "
    wire:key="{{ $config['mapId'] }}"
>
    @if (!$state && $placeholder)
        <p class="fi-ta-placeholder text-xs text-gray-400 dark:text-gray-500">
            {{ $placeholder }}
        </p>
    @elseif ($state)
        <div 
            x-data="{ shown: false }" 
            x-intersect.once.margin.200px="shown = true"
            @style([
                'border-radius: 50%' => $getIsCircular(),
                'width: ' . $getWidth() => !$getIsCircular(),
                'width: var(--max-dimension)' => $getIsCircular(),
                'height: var(--max-dimension)' => $getIsCircular(),
                'height: ' . $config['mapHeight'] . 'px' => !$getIsCircular(),
            ])
            class="relative flex items-center justify-center overflow-hidden rounded bg-gray-100 dark:bg-gray-800"
        >
            <template x-if="shown">
                <x-filament-leaflet::map
                    :config="$config"
                    column
                    @style([
                        'border-radius: 50%' => $getIsCircular(),
                        'width: ' . $getWidth() => !$getIsCircular(),
                        'width: var(--max-dimension)' => $getIsCircular(),
                        'height: var(--max-dimension)' => $getIsCircular(),
                    ])
                />
            </template>
            <template x-if="!shown">
                <div class="flex items-center justify-center text-gray-400 dark:text-gray-500 w-full h-full text-xs">
                    <span class="animate-pulse">📍</span>
                </div>
            </template>
        </div>
    @endif
</div>
