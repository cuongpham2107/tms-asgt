@php
    use App\Enums\CheckpointType;
    $type = $checkpoint['checkpoint_type'] ?? null;
    $typeLabel = $type instanceof CheckpointType ? $type->getLabel() : ($type?->value ?? $type);
    $typeColor = $type instanceof CheckpointType ? $type->getColor() : 'gray';

    $colorMap = [
        'info'    => 'bg-blue-50 text-blue-700 border-blue-200',
        'warning' => 'bg-amber-50 text-amber-700 border-amber-200',
        'success' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
        'danger'  => 'bg-red-50 text-red-700 border-red-200',
        'primary' => 'bg-purple-50 text-purple-700 border-purple-200',
        'gray'    => 'bg-gray-50 text-gray-600 border-gray-200',
    ];
    $colorClass = $colorMap[$typeColor] ?? $colorMap['gray'];

    $orderCodes = $checkpoint['order_codes'] ?? [];
    $dpLabel = $checkpoint['dp_label'] ?? '';
@endphp

<div class="flex items-center gap-2">
    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-semibold border {{ $colorClass }}">
        {{ $typeLabel }}{{ $dpLabel }}
    </span>
    <span class="text-sm text-gray-600">
        @if(count($orderCodes) > 0)
            <span class="text-gray-500">Đơn: </span>
            @foreach($orderCodes as $i => $code)
                <span class="font-medium text-gray-700">{{ $code }}</span>{{ $i < count($orderCodes) - 1 ? ', ' : '' }}
            @endforeach
        @endif
        @if($checkpoint['km'] ?? null)
            <span class="text-gray-400"> · {{ $checkpoint['km'] }}</span>
        @endif
        @if($checkpoint['voice_note'] ?? null)
            <span class="text-gray-400"> · 💬 {{ $checkpoint['voice_note'] }}</span>
        @endif
    </span>
</div>
