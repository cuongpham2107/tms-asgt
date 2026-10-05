@php
    use App\Enums\CheckpointType;
    $type = $checkpoint['checkpoint_type'] ?? null;
    $typeLabel = $type instanceof CheckpointType ? $type->getLabel() : ($type?->value ?? $type);
    $typeColor = $type instanceof CheckpointType ? $type->getColor() : 'gray';

    $orderCodes = $checkpoint['order_codes'] ?? [];
    $dpLabel = $checkpoint['dp_label'] ?? '';
@endphp

<div class="flex flex-wrap items-center gap-2">
    <x-filament::badge :color="$typeColor">
        {{ $typeLabel }}{{ $dpLabel }}
    </x-filament::badge>
    <span class="min-w-0 text-sm text-gray-500 dark:text-gray-400">
        @if(count($orderCodes) > 0)
            <span>Đơn: </span>
            @foreach($orderCodes as $i => $code)
                <span class="font-medium text-gray-950 dark:text-white">{{ $code }}</span>{{ $i < count($orderCodes) - 1 ? ', ' : '' }}
            @endforeach
        @endif
        @if($checkpoint['voice_note'] ?? null)
            <span class="inline-flex items-center gap-1 text-xs text-gray-500 dark:text-gray-400">
                · <x-filament::icon icon="heroicon-m-chat-bubble-left-ellipsis" class="h-3.5 w-3.5 text-gray-400 dark:text-gray-500" />
                {{ $checkpoint['voice_note'] }}
            </span>
        @endif
    </span>
</div>
